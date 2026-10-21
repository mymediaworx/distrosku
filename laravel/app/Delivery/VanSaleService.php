<?php

namespace App\Delivery;

use App\Exceptions\WorkflowException;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\Warehouse;
use App\Pricing\PricingEngine;
use App\Services\ComplianceChecker;
use App\Services\Money;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\DB;

/**
 * Van sales: a driver sells from a van warehouse to a customer on route. Same pipeline as the counter
 * (pricing engine, compliance, KY excise via invoice posting, buydowns, signature). No settlement (out of scope).
 */
class VanSaleService
{
    public function __construct(private SalesOrderService $orders, private SignatureService $sig, private PricingEngine $pricing) {}

    /**
     * @param  list<array{product_id:int, qty:mixed, price?:mixed}>  $lines  price omitted = engine price
     */
    public function sell(Warehouse $van, Customer $c, ShipTo $st, array $lines, string $tender, ?array $signature = null): SalesOrder
    {
        if (! $van->is_van) {
            throw new WorkflowException("{$van->name} is not a van/truck warehouse.");
        }
        if ($st->customer_id !== $c->id) {
            throw new WorkflowException('Ship-to does not belong to the customer.');
        }
        if (! in_array($tender, ['cash', 'check', 'account'], true)) {
            throw new WorkflowException('Tender must be cash, check or account.');
        }
        if ($tender === 'account' && SignatureService::paidTerms($c)) {
            throw new WorkflowException("{$c->name} is on {$c->terms} terms; collect payment instead of charging the account.");
        }
        $lines = array_values(array_filter($lines, fn ($l) => Money::of($l['qty'] ?: 0)->isPositive()));
        if (! $lines) {
            throw new WorkflowException('Add at least one item.');
        }
        $products = Product::whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        foreach ($products as $p) {
            if ($r = app(ComplianceChecker::class)->checkLine($p, $c, $st)) {
                \App\Audit\Audit::durable('van_sale_blocked', $p, "Van sale blocked: {$p->sku} to {$c->name} ({$st->state})",
                    ['channel' => 'van', 'van_id' => $van->id, 'customer_id' => $c->id, 'ship_to_id' => $st->id, 'sku' => $p->sku, 'reasons' => $r]);
                throw new WorkflowException("{$p->name}: ".implode(' ', $r));
            }
        }

        return DB::transaction(function () use ($van, $c, $st, $lines, $tender, $signature, $products) {
            $so = SalesOrder::create([
                'number' => SalesOrderService::nextNumber('VAN', SalesOrder::class),
                'customer_id' => $c->id, 'ship_to_id' => $st->id, 'warehouse_id' => $van->id,
                'channel' => 'van', 'order_date' => now()->toDateString(),
            ]);
            foreach ($lines as $l) {
                $p = $products[$l['product_id']];
                $price = isset($l['price']) && $l['price'] !== '' ? $l['price'] : $this->pricing->quote($p, $c, $l['qty'])->price;
                $so->lines()->create(['product_id' => $p->id, 'qty' => Money::s($l['qty']), 'unit_price' => Money::s($price)]);
            }
            $this->orders->confirm($so, $tender === 'account');
            $so->refresh();
            if ($this->sig->required($so)) {
                if (! $signature) {
                    throw new WorkflowException('Signature required: this sale contains regulated products.');
                }
                $this->sig->capture($so, $signature, 'van', null, $tender === 'account');
            }
            $this->orders->deliver($so);
            $this->orders->invoice($so);
            if ($tender !== 'account') {
                $this->orders->recordPayment($so->fresh(), $so->fresh()->total, $tender, 'VAN');
            }

            return $so->fresh();
        });
    }
}

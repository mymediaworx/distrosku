<?php

namespace App\Pos;

use App\Delivery\SignatureService;
use App\Models\SalesOrder;
use App\Services\Money;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\DB;

/**
 * One counter-POS sale, start to finish: order, lines, confirm (compliance + credit + stock reservation),
 * 21+ signature, deliver, invoice, payment. Shared by the live POS screen and offline-sale sync (#26),
 * so a sale recorded offline goes through exactly the same checks when it reaches the server.
 * All-or-nothing: any failure rolls the whole sale back and is rethrown to the caller.
 */
final class PosCheckout
{
    public const TENDERS = ['cash', 'check', 'card', 'account'];

    public function __construct(private SalesOrderService $orders, private SignatureService $signatures) {}

    /**
     * @param  array{customer_id: int, warehouse_id: int, fulfillment?: string, ship_to_id?: ?int, notes?: ?string,
     *               tender: string, lines: list<array{product_id: int, qty: mixed, price: mixed, lot_id?: ?int}>,
     *               signature?: ?array, order_date?: ?string, credit_override_reason?: ?string}  $sale
     */
    public function complete(array $sale, ?\App\Models\User $overrideBy = null): SalesOrder
    {
        $delivery = ($sale['fulfillment'] ?? 'pickup') === 'delivery';
        $tender = in_array($sale['tender'] ?? null, self::TENDERS, true) ? $sale['tender'] : 'cash';

        return DB::transaction(function () use ($sale, $delivery, $tender, $overrideBy) {
            $so = SalesOrder::create([
                'number' => SalesOrderService::nextNumber('POS', SalesOrder::class),
                'customer_id' => $sale['customer_id'], 'ship_to_id' => $delivery ? ($sale['ship_to_id'] ?? null) : null, 'warehouse_id' => $sale['warehouse_id'],
                'fulfillment' => $delivery ? 'delivery' : 'pickup', 'channel' => 'pos',
                'order_date' => $sale['order_date'] ?? now()->toDateString(), 'notes' => trim((string) ($sale['notes'] ?? '')) ?: null,
            ]);
            foreach ($sale['lines'] as $l) {
                $lotId = isset($l['lot_id']) && $l['lot_id'] ? (int) $l['lot_id'] : null; // 0.20.15 (#36): optional lot from the picker
                if ($lotId) {
                    app(\App\Warehouse\LotService::class)->assertSellable($lotId, \App\Models\Product::findOrFail($l['product_id']));
                }
                $so->lines()->create(['product_id' => $l['product_id'], 'qty' => Money::s($l['qty']), 'unit_price' => Money::s($l['price']), 'lot_id' => $lotId]);
            }
            if ($overrideBy && filled($sale['credit_override_reason'] ?? null)) {
                // Owner/admin override, e.g. resolving an offline account sale that went over the limit (logged by CreditLimit).
                $this->orders->recalc($so);
                app(\App\Credit\CreditLimit::class)->override($so->fresh(), $overrideBy, $sale['credit_override_reason']);
            }
            $so = $this->orders->confirm($so->fresh(), $tender === 'account'); // same instance flows into deliver()
            // 0.15: regulated items need the counter signature (21+; account charge when tender is account).
            if ($this->signatures->required($so->fresh())) {
                $this->signatures->capture($so->fresh(), $sale['signature'] ?? [], 'counter', null, $tender === 'account');
            }
            $this->orders->deliver($so);
            $this->orders->invoice($so);
            if ($tender !== 'account') {
                $this->orders->recordPayment($so->fresh(), $so->fresh()->total, $tender, 'POS');
            }

            return $so->fresh();
        });
    }
}

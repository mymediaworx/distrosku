<?php

namespace App\Portal;

use App\Credit\CreditLimit;
use App\Credit\CreditLimitException;
use App\Delivery\SignatureService;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\ComplianceException;
use App\Exceptions\InventoryException;
use App\Exceptions\WorkflowException;
use App\Models\CustomerContact;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\ShipTo;
use App\Models\Warehouse;
use App\Services\ComplianceChecker;
use App\Services\Money;
use App\Services\SalesOrderService;
use Illuminate\Support\Facades\DB;

class PortalService
{
    public function __construct(private ComplianceChecker $compliance, private PortalPricing $pricing, private SalesOrderService $orders) {}

    public function shipToFor(CustomerContact $c, ?int $shipToId): ?ShipTo
    {
        $all = $c->allowedShipTos();

        return $all->firstWhere('id', $shipToId) ?? $all->first();
    }

    /** Orderable catalog: active, not archived. Each row: product, price, reasons (empty = available). */
    public function catalog(CustomerContact $c, ShipTo $shipTo, string $q = '', ?int $departmentId = null, int $limit = 60)
    {
        $q = trim($q);

        return Product::with('department')->where('active', true)->notArchived()
            ->when($departmentId, fn ($w) => $w->where('department_id', $departmentId))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')
                ->orWhere('sku', strtoupper($q))->orWhere('upc', preg_replace('/\D/', '', $q) ?: '-')))
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (Product $p) => ['product' => $p, 'price' => $this->pricing->price($p, $c->customer), 'reasons' => $this->reasons($p, $c, $shipTo)]);
    }

    public function reasons(Product $p, CustomerContact $c, ShipTo $shipTo): array
    {
        if (! $p->active || $p->isArchived()) {
            return ['No longer available.'];
        }

        return $this->compliance->checkLine($p, $c->customer, $shipTo);
    }

    /** Past order lines -> cart quantities; returns [cart, skipped messages]. */
    public function reorder(CustomerContact $c, SalesOrder $so, ShipTo $shipTo, array $cart): array
    {
        abort_unless((int) $so->customer_id === (int) $c->customer_id, 404);
        $skipped = [];
        foreach ($so->lines()->with('product')->get() as $l) {
            $p = $l->product;
            if ($p && ! ($r = $this->reasons($p, $c, $shipTo))) {
                $cart[$p->id] = (string) ((float) ($cart[$p->id] ?? 0) + (float) $l->qty);
            } else {
                $skipped[] = ($p?->sku ?? '?').': '.implode(' ', $r ?? ['not available']);
            }
        }

        return [$cart, $skipped];
    }

    /**
     * Submit the cart as a portal SalesOrder. Compliance is checked per line (and again by SalesOrderService::confirm).
     * Confirmed when stock allows; otherwise kept as a draft for the office to review.
     */
    public function submit(CustomerContact $c, ShipTo $shipTo, array $cart, ?string $note = null): SalesOrder
    {
        abort_unless((int) $shipTo->customer_id === (int) $c->customer_id && $c->allowedShipTos()->contains('id', $shipTo->id), 403);
        $lines = [];
        foreach ($cart as $pid => $qty) {
            $qty = Money::s($qty);
            if (Money::of($qty)->isNegativeOrZero()) {
                continue;
            }
            $p = Product::find($pid);
            if (! $p) {
                continue;
            }
            if ($r = $this->reasons($p, $c, $shipTo)) {
                throw new WorkflowException("{$p->sku} {$p->name}: ".implode(' ', $r));
            }
            $lines[] = [$p, $qty, $this->pricing->price($p, $c->customer, $qty)];
        }
        if (! $lines) {
            throw new WorkflowException('Your cart is empty.');
        }
        // 0.17.1: friendly credit-limit check before anything is saved (account terms only).
        if (! SignatureService::paidTerms($c->customer)) {
            $total = Money::add(...array_map(fn ($l) => Money::mul($l[1], $l[2]), $lines));
            try {
                app(CreditLimit::class)->check($c->customer, $total);
            } catch (CreditLimitException $e) {
                $avail = Money::of($e->limit)->minus(Money::of($e->exposure));
                throw new WorkflowException('This order ($'.CreditLimit::fmt($total).') is more than your available credit ($'
                    .CreditLimit::fmt($avail->isNegative() ? 0 : $avail).'). Please make a payment on the Pay page, reduce the order, or contact your sales rep.');
            }
        }
        $so = DB::transaction(function () use ($c, $shipTo, $lines, $note) {
            $so = SalesOrder::create(['customer_id' => $c->customer_id, 'ship_to_id' => $shipTo->id, 'channel' => 'portal',
                'warehouse_id' => Warehouse::where('code', 'MAIN')->value('id') ?? Warehouse::value('id'),
                'order_date' => now()->toDateString(), 'notes' => trim("Portal order by {$c->name} <{$c->email}>. ".($note ?? ''))]);
            foreach ($lines as [$p, $qty, $price]) {
                $so->lines()->create(['product_id' => $p->id, 'qty' => $qty, 'unit_price' => $price]);
            }
            $this->orders->recalc($so);

            return $so;
        });
        try {
            $this->orders->confirm($so->fresh());
        } catch (InventoryException) {
            // not enough stock: stays a draft for the office
        } catch (ComplianceException $e) {
            throw new WorkflowException('Order saved as draft for review: '.$e->getMessage());
        } catch (CreditLimitException) {
            throw new WorkflowException('Order saved as draft for review: it is more than your available credit. Your sales rep will contact you, or make a payment on the Pay page.');
        }

        return $so->fresh();
    }

    public function openInvoices(CustomerContact $c)
    {
        return SalesOrder::where('customer_id', $c->customer_id)->where('status', S::Invoiced)->orderBy('invoiced_at')->get()
            ->filter(fn ($so) => Money::of($so->balance())->isPositive())->values();
    }
}

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
    public function catalog(CustomerContact $c, ShipTo $shipTo, string $q = '', ?int $departmentId = null, int $limit = 500)
    {
        $q = trim($q);

        return Product::with('department')->where('active', true)->notArchived()
            ->when($departmentId, fn ($w) => $w->where('department_id', $departmentId))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')
                ->orWhere('sku', strtoupper($q))->orWhere('upc', preg_replace('/\D/', '', $q) ?: '-')))
            ->orderBy('name')->limit($limit)->get()
            ->map(fn (Product $p) => ['product' => $p, 'price' => $this->pricing->price($p, $c->customer), 'reasons' => $this->reasons($p, $c, $shipTo)]);
    }

    /**
     * 0.20.3: portal home sections. Promotions = ticked "Show in Promotions" OR a product-specific active promotion
     * that applies to this customer today; New = ticked and (no "new until" or not yet past); Featured = ticked.
     * Same rules as the catalog: active, not archived, customer price (lowest wins), compliance reasons per row.
     */
    public function sections(CustomerContact $c, ShipTo $shipTo, int $limit = 12): array
    {
        $today = now()->toDateString();
        $cust = $c->customer;
        $promoIds = \App\Models\Promotion::query()->where('active', true)->whereNotNull('product_id')
            ->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)
            ->where(fn ($q) => $q->whereNull('customer_id')->orWhere('customer_id', $cust->id))
            ->where(fn ($q) => $q->whereNull('customer_group_id')->when($cust->customer_group_id, fn ($w) => $w->orWhere('customer_group_id', $cust->customer_group_id)))
            ->pluck('product_id')->unique()->all();
        $base = fn () => Product::with('department')->where('active', true)->notArchived()->orderBy('portal_sort')->orderBy('name')->limit($limit);
        $row = fn (Product $p) => ['product' => $p, 'price' => $this->pricing->price($p, $cust), 'reasons' => $this->reasons($p, $c, $shipTo)];

        return [
            'promotions' => $base()->where(fn ($q) => $q->where('portal_promo', true)->orWhereIn('id', $promoIds ?: [0]))->get()->map($row),
            'new' => $base()->where('portal_new', true)->where(fn ($q) => $q->whereNull('portal_new_until')->orWhereDate('portal_new_until', '>=', $today))->get()->map($row),
            'featured' => $base()->where('portal_featured', true)->get()->map($row),
        ];
    }

    /**
     * 0.20.3: products this customer bought before (delivered or invoiced orders), newest first, with last date and qty.
     * Respects a contact limited to one ship-to. Inactive/archived products are left out.
     */
    public function buyAgain(CustomerContact $c, ShipTo $shipTo, int $limit = 100)
    {
        $lines = \App\Models\SalesOrderLine::query()->select('sales_order_lines.product_id', 'sales_order_lines.qty', 'sales_orders.order_date', 'sales_orders.number', 'sales_orders.id as so_id')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->where('sales_orders.customer_id', $c->customer_id)
            ->whereIn('sales_orders.status', [\App\Enums\SalesOrderStatus::Delivered->value, \App\Enums\SalesOrderStatus::Invoiced->value])
            ->when($c->ship_to_id, fn ($q) => $q->where('sales_orders.ship_to_id', $c->ship_to_id))
            ->orderByDesc('sales_orders.order_date')->orderByDesc('sales_orders.id')->orderByDesc('sales_order_lines.id')
            ->limit(2000)->get()->unique('product_id')->take($limit);
        $products = Product::with('department')->whereIn('id', $lines->pluck('product_id'))->where('active', true)->notArchived()->get()->keyBy('id');

        return $lines->filter(fn ($l) => $products->has($l->product_id))->map(fn ($l) => [
            'product' => $p = $products[$l->product_id], 'price' => $this->pricing->price($p, $c->customer),
            'reasons' => $this->reasons($p, $c, $shipTo), 'last_date' => $l->order_date, 'last_qty' => $l->qty, 'last_order' => $l->number, 'last_order_id' => $l->so_id,
        ])->values();
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
    public function submit(CustomerContact $c, ?ShipTo $shipTo, array $cart, ?string $note = null, string $fulfillment = 'pickup'): SalesOrder
    {
        // 0.19.6: counter pickup (default) needs no ship-to; delivery needs one this contact may order for.
        $fulfillment = $fulfillment === 'delivery' ? 'delivery' : 'pickup';
        $whId = Warehouse::where('code', 'MAIN')->value('id') ?? Warehouse::value('id');
        if ($fulfillment === 'pickup') {
            $shipTo = null;
        } elseif (! $shipTo) {
            throw new WorkflowException('Choose a ship-to for delivery, or switch to Counter pickup.');
        }
        abort_unless(! $shipTo || ((int) $shipTo->customer_id === (int) $c->customer_id && $c->allowedShipTos()->contains('id', $shipTo->id)), 403);
        $loc = $shipTo ?? SalesOrder::pickupLocation($whId, $c->customer_id);
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
            if ($r = $this->reasons($p, $c, $loc)) {
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
        $so = DB::transaction(function () use ($c, $shipTo, $lines, $note, $fulfillment, $whId) {
            $so = SalesOrder::create(['customer_id' => $c->customer_id, 'ship_to_id' => $shipTo?->id, 'channel' => 'portal', 'fulfillment' => $fulfillment,
                'warehouse_id' => $whId,
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

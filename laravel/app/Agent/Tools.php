<?php

namespace App\Agent;

use App\Analytics\ReportService;
use App\Credit\CreditLimit;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\OfflineSale;
use App\Models\Product;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\User;
use App\Pricing\PricingEngine;
use App\Support\CustomerSearch;
use App\Support\ProductSearch;
use App\Warehouse\ProductLookup;
use App\Warehouse\Uom;
use InvalidArgumentException;

/**
 * #35 Stage 1: READ-ONLY tools for the AI agent. Every tool wraps an existing service/report and is offered only when the
 * calling user's own permissions (canModule) allow it. Nothing here writes, posts, prices or changes anything.
 */
class Tools
{
    /** name => [module, description, input schema] */
    public static function catalog(): array
    {
        $str = fn (string $d) => ['type' => 'string', 'description' => $d];

        return [
            'find_customer' => ['customers', 'Find customers by account number, name, DBA, phone or city. Returns terms, price group, credit summary, balance and licenses with expiry.',
                ['type' => 'object', 'properties' => ['query' => $str('What to search for'), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]], 'required' => ['query']]],
            'find_product' => ['products', 'Find products by name, SKU, UPC or case/carton barcode. Returns price, units (case/carton/pack), tax class and stock by warehouse.',
                ['type' => 'object', 'properties' => ['query' => $str('Name, SKU or barcode'), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]], 'required' => ['query']]],
            'quote_price' => ['pricing', "The pricing engine's price for a customer, product and quantity (read-only: never changes a price).",
                ['type' => 'object', 'properties' => ['customer' => $str('Account number'), 'product' => $str('SKU or UPC'), 'qty' => ['type' => 'number', 'minimum' => 0.0001], 'date' => $str('YYYY-MM-DD, default today')], 'required' => ['customer', 'product']]],
            'customer_history' => ['sales_orders', 'Recent orders for a customer (number, date, status, total) and the items they buy most.',
                ['type' => 'object', 'properties' => ['customer' => $str('Account number'), 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], 'required' => ['customer']]],
            'ar_aging' => ['reports', 'Accounts receivable aging (0-30, 31-60, 61-90, 90+) as of a date, all customers or one.',
                ['type' => 'object', 'properties' => ['as_of' => $str('YYYY-MM-DD, default today'), 'customer' => $str('Account number (optional)')]]],
            'customer_statement' => ['reports', 'Statement for one customer: charges, credits and running balance for a period.',
                ['type' => 'object', 'properties' => ['customer' => $str('Account number'), 'from' => $str('YYYY-MM-DD'), 'to' => $str('YYYY-MM-DD')], 'required' => ['customer']]],
            'stock_and_inbound' => ['products', 'On hand and reserved by warehouse for a product, plus open purchase orders with expected dates.',
                ['type' => 'object', 'properties' => ['product' => $str('SKU or UPC')], 'required' => ['product']]],
            'review_queue' => ['offline_sales', 'Offline sales waiting for a person to review, with the reasons.',
                ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]]]],
        ];
    }

    /** Tools this user may use (MCP tools/list shape). */
    public static function forUser(User $u): array
    {
        $out = [];
        foreach (self::catalog() as $name => [$module, $desc, $schema]) {
            if ($u->canModule($module)) {
                $out[] = ['name' => $name, 'description' => $desc, 'inputSchema' => $schema, 'annotations' => ['readOnlyHint' => true]];
            }
        }

        return $out;
    }

    public static function allowed(User $u, string $name): bool
    {
        $t = self::catalog()[$name] ?? null;

        return $t !== null && $u->canModule($t[0]);
    }

    public function call(User $u, string $name, array $a): array
    {
        if (! self::allowed($u, $name)) {
            throw new InvalidArgumentException("Unknown tool or not allowed for this user: {$name}");
        }

        return match ($name) {
            'find_customer' => $this->findCustomer($a),
            'find_product' => $this->findProduct($a),
            'quote_price' => $this->quote($a),
            'customer_history' => $this->history($a),
            'ar_aging' => app(ReportService::class)->arAging($a['as_of'] ?? null, ($c = $this->customerOrNull($a['customer'] ?? null)) ? ['customer_id' => $c->id] : []),
            'customer_statement' => app(ReportService::class)->statement($this->customer($a['customer'] ?? '')->id, $a['from'] ?? null, $a['to'] ?? null),
            'stock_and_inbound' => $this->stock($a),
            'review_queue' => OfflineSale::where('status', OfflineSale::NEEDS_REVIEW)->latest('id')->limit($this->limit($a, 20, 50))->get()
                ->map(fn ($o) => ['uuid' => $o->uuid, 'sold_at' => $o->sold_at?->toIso8601String(), 'customer_id' => $o->customer_id, 'total' => (string) $o->total, 'problems' => $o->problems])->all(),
        };
    }

    private function limit(array $a, int $default, int $max): int
    {
        return max(1, min($max, (int) ($a['limit'] ?? $default)));
    }

    private function customerOrNull(?string $code): ?Customer
    {
        return $code ? Customer::where('code', trim($code))->first() : null;
    }

    private function customer(string $code): Customer
    {
        return $this->customerOrNull($code) ?? throw new InvalidArgumentException("No customer with account number \"{$code}\".");
    }

    private function product(string $q): Product
    {
        $hit = ProductLookup::resolve(trim($q));

        return $hit['product'] ?? Product::where('sku', trim($q))->first() ?? throw new InvalidArgumentException("No product for \"{$q}\".");
    }

    private function findCustomer(array $a): array
    {
        return CustomerSearch::query((string) ($a['query'] ?? ''))->with(['customerGroup', 'licenses'])->limit($this->limit($a, 10, 20))->get()->map(fn (Customer $c) => [
            'account' => $c->code, 'name' => $c->name, 'dba' => $c->dba, 'phone' => $c->phone, 'terms' => $c->terms,
            'price_group' => $c->customerGroup?->name, 'walk_in' => (bool) $c->walk_in, 'balance' => $c->balance(), 'credit' => CreditLimit::summary($c),
            'licenses' => $c->licenses->map(fn ($l) => ['type' => $l->type, 'number' => $l->number, 'state' => $l->state, 'expires' => $l->expires_at?->toDateString()])->values()->all(),
        ])->all();
    }

    private function findProduct(array $a): array
    {
        $q = trim((string) ($a['query'] ?? ''));
        $ps = ($hit = ProductLookup::resolve($q)) ? collect([$hit['product']]) : ProductSearch::search($q, $this->limit($a, 10, 20));

        return $ps->map(fn (Product $p) => [
            'sku' => $p->sku, 'name' => $p->name, 'upc' => $p->upc, 'price' => (string) $p->price, 'unit' => $p->base_unit_name,
            'tax_class' => $p->tax_class?->value, 'active' => (bool) $p->active,
            'units' => $p->units()->get()->map(fn ($u) => ['code' => $u->code, 'name' => $u->name, 'contains' => (string) $u->factor])->all(),
            'stock' => Inventory::where('product_id', $p->id)->with('warehouse:id,code')->get()->map(fn ($i) => ['warehouse' => $i->warehouse?->code, 'on_hand' => (string) $i->qty])->all(),
        ])->values()->all();
    }

    private function quote(array $a): array
    {
        $c = $this->customer((string) ($a['customer'] ?? ''));
        $p = $this->product((string) ($a['product'] ?? ''));
        $q = app(PricingEngine::class)->quote($p, $c, $a['qty'] ?? 1, $a['date'] ?? null);

        return ['customer' => $c->code, 'sku' => $p->sku, 'qty' => (string) ($a['qty'] ?? 1), 'price' => $q->price, 'list_price' => $q->listPrice, 'source' => $q->label()];
    }

    private function history(array $a): array
    {
        $c = $this->customer((string) ($a['customer'] ?? ''));
        $orders = SalesOrder::where('customer_id', $c->id)->latest('order_date')->latest('id')->limit($this->limit($a, 10, 50))->get(['id', 'number', 'order_date', 'status', 'total', 'channel']);
        $top = \App\Models\SalesOrderLine::query()->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->where('sales_orders.customer_id', $c->id)->selectRaw('product_id, SUM(qty) as qty, COUNT(*) as times')->groupBy('product_id')->orderByDesc('times')->limit(10)->get();
        $names = Product::whereIn('id', $top->pluck('product_id'))->get(['id', 'sku', 'name'])->keyBy('id');

        return ['customer' => $c->code, 'orders' => $orders->map(fn ($o) => ['number' => $o->number, 'date' => $o->order_date?->toDateString(), 'status' => $o->status?->value, 'total' => (string) $o->total, 'channel' => $o->channel])->all(),
            'buys_most' => $top->map(fn ($t) => ['sku' => $names[$t->product_id]->sku ?? null, 'name' => $names[$t->product_id]->name ?? null, 'times' => (int) $t->times, 'qty' => (string) $t->qty])->all()];
    }

    private function stock(array $a): array
    {
        $p = $this->product((string) ($a['product'] ?? ''));
        $inv = Inventory::where('product_id', $p->id)->with('warehouse:id,code,name')->get();
        $po = PurchaseOrderLine::where('product_id', $p->id)->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', ['ordered', 'partial']))
            ->with('purchaseOrder:id,number,expected_at,status')->get();

        return ['sku' => $p->sku, 'name' => $p->name,
            'warehouses' => $inv->map(fn ($i) => ['warehouse' => $i->warehouse?->code, 'on_hand' => (string) $i->qty, 'reserved' => (string) ($i->reserved ?? 0), 'as_cases' => Uom::describe($p, $i->qty)])->all(),
            'inbound' => $po->map(fn ($l) => ['po' => $l->purchaseOrder?->number, 'expected' => $l->purchaseOrder?->expected_at?->toDateString(), 'open_qty' => (string) max(0, (float) $l->qty - (float) $l->qty_received)])->all()];
    }
}

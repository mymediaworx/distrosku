<?php

namespace App\Imports\Types;

use App\Imports\RowResult;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\ImportBatch;
use App\Models\PriceRule;
use App\Warehouse\ProductLookup;

/** Price lists / contract prices -> price_rules (same engine as phase 14). Key: product + customer/group + min qty + start date. */
class PriceListsImport extends ImportType
{
    public function key(): string
    {
        return 'price_lists';
    }

    public function label(): string
    {
        return 'Price lists / contract prices';
    }

    public function fields(): array
    {
        return [
            'product' => ['label' => 'SKU or UPC', 'required' => true, 'example' => 'MARL-RED-KS'],
            'customer_code' => ['label' => 'Customer number (contract price)'],
            'group_code' => ['label' => 'Price group code'],
            'min_qty' => ['label' => 'Minimum qty', 'example' => '1'],
            'price' => ['label' => 'Price', 'required' => true, 'example' => '62.50'],
            'starts_on' => ['label' => 'Starts on'], 'ends_on' => ['label' => 'Ends on'], 'note' => ['label' => 'Note'],
        ];
    }

    public function evaluate(array $v, ImportBatch $b): RowResult
    {
        $e = [];
        static::required($v, ['product' => 'SKU or UPC', 'price' => 'Price'], $e);
        $p = ($code = static::s($v, 'product')) ? ProductLookup::find($code) : null;
        if ($code && ! $p) {
            $e[] = "No product for \"{$code}\".";
        }
        $cust = ($cc = static::s($v, 'customer_code')) ? Customer::where('code', $cc)->first() : null;
        if ($cc && ! $cust) {
            $e[] = "Customer \"{$cc}\" does not exist.";
        }
        $grp = ($gc = static::s($v, 'group_code')) ? CustomerGroup::where('code', $gc)->first() : null;
        if ($gc && ! $grp) {
            $e[] = "Price group \"{$gc}\" does not exist.";
        }
        if ($cust && $grp) {
            $e[] = 'Use either a customer or a price group, not both.';
        }
        $min = static::num($v, 'min_qty', $e, 'Minimum qty') ?? '1.0000';
        $price = static::num($v, 'price', $e, 'Price');
        $start = static::date($v, 'starts_on', $e);
        $end = static::date($v, 'ends_on', $e);
        if ($start && $end && $end < $start) {
            $e[] = 'Ends on is before starts on.';
        }
        $rule = $p ? PriceRule::where('product_id', $p->id)->where('customer_id', $cust?->id)->where('customer_group_id', $grp?->id)
            ->where('min_qty', $min)->when($start, fn ($q) => $q->whereDate('starts_on', $start), fn ($q) => $q->whereNull('starts_on'))->first() : null;
        $attrs = ['price' => $price, 'ends_on' => $end, 'note' => static::s($v, 'note'), 'active' => true];
        $diff = static::diff($rule, $attrs);
        $key = $p ? implode('|', [$p->id, $cust?->id, $grp?->id, $min, $start]) : null;

        return new RowResult($e, $rule ? ($diff ? 'update' : 'unchanged') : 'create', $diff, $key,
            ['model' => $rule, 'base' => ['product_id' => $p?->id, 'customer_id' => $cust?->id, 'customer_group_id' => $grp?->id, 'min_qty' => $min, 'starts_on' => $start], 'attrs' => $attrs]);
    }

    public function apply(array $v, ImportBatch $b, RowResult $r): string
    {
        $r->context['model'] ? $r->context['model']->update(static::filled($r->context['attrs'])) : PriceRule::create($r->context['base'] + static::filled($r->context['attrs']));

        return $r->action;
    }
}

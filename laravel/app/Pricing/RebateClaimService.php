<?php

namespace App\Pricing;

use App\Models\BuydownAccrual;
use Illuminate\Database\Eloquent\Builder;

class RebateClaimService
{
    public function query(?string $from = null, ?string $to = null, ?string $manufacturer = null, ?bool $claimed = false): Builder
    {
        return BuydownAccrual::query()->with(['buydown', 'product', 'customer', 'salesOrder', 'creditMemo'])
            ->when($from, fn ($q) => $q->whereDate('sold_on', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sold_on', '<=', $to))
            ->when($manufacturer, fn ($q) => $q->whereHas('buydown', fn ($b) => $b->where('manufacturer', $manufacturer)))
            ->when($claimed !== null, fn ($q) => $claimed ? $q->whereNotNull('claimed_at') : $q->whereNull('claimed_at'));
    }

    /** CSV rows for a manufacturer claim (one row per invoiced line). */
    public function csv(Builder $q): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Manufacturer', 'Program', 'Type', 'Invoice', 'Sold on', 'Customer', 'SKU', 'UPC', 'Product', 'Qty', 'Per unit', 'Amount', 'Claimed', 'Claim ref', 'Line type', 'Credit memo', 'Paid']);
        foreach ($q->orderBy('sold_on')->orderBy('id')->cursor() as $a) {
            fputcsv($out, [$a->buydown?->manufacturer, $a->buydown?->program_code ?: $a->buydown?->description, $a->buydown?->kind, $a->salesOrder?->number,
                $a->sold_on?->toDateString(), $a->customer?->name, $a->product?->sku, $a->product?->upc, $a->product?->name,
                $a->qty, $a->amount_per_unit, $a->amount, $a->claimed_at?->toDateString(), $a->claim_ref,
                $a->kind ?? 'accrual', $a->creditMemo?->number, $a->paid_at?->toDateString()]);
        }
        rewind($out);

        return stream_get_contents($out);
    }

    public function markClaimed(iterable $accruals, string $ref): int
    {
        $n = 0;
        foreach ($accruals as $a) {
            if (! $a->claimed_at) {
                $a->update(['claimed_at' => now(), 'claim_ref' => $ref]);
                $n++;
            }
        }

        return $n;
    }
}

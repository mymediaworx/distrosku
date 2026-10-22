<?php

namespace App\Purchasing;

use App\Models\PurchaseOrder;

/** 0.20.1: inbound carrier + tracking links for purchase orders. */
class InboundTracking
{
    public const CARRIERS = ['ups' => 'UPS', 'fedex' => 'FedEx', 'usps' => 'USPS', 'dhl' => 'DHL', 'other' => 'LTL / freight / other'];

    public const URLS = [
        'ups' => 'https://www.ups.com/track?tracknum=',
        'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=',
        'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=',
        'dhl' => 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?tracking-id=',
    ];

    /** @return list<string> */
    public static function numbers(PurchaseOrder $po): array
    {
        $n = $po->tracking_numbers;
        if (is_string($n)) {
            $n = preg_split('/[\s,;]+/', $n);
        }

        return array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) (is_array($v) ? ($v['number'] ?? '') : $v)), (array) $n), fn ($v) => $v !== '')));
    }

    public static function carrierLabel(PurchaseOrder $po): ?string
    {
        if (! $po->carrier_code) {
            return null;
        }
        if ($po->carrier_code === 'other') {
            return $po->carrier_name ?: 'Other carrier';
        }

        return self::CARRIERS[$po->carrier_code] ?? $po->carrier_code;
    }

    public static function safeUrl(?string $u): ?string
    {
        $u = trim((string) $u);

        return $u !== '' && preg_match('#^https?://#i', $u) ? $u : null;
    }

    /** @return list<array{label:string,url:string}> one Track link per tracking number (custom URL wins; {tracking} placeholder supported). */
    public static function links(PurchaseOrder $po): array
    {
        $nums = self::numbers($po);
        $custom = self::safeUrl($po->tracking_url);
        $out = [];
        if ($custom && (! str_contains($custom, '{tracking}') || ! $nums)) {
            $out[] = ['label' => 'Track'.(count($nums) === 1 ? ' '.$nums[0] : ''), 'url' => str_replace('{tracking}', '', $custom)];
            if (! str_contains($custom, '{tracking}')) {
                return $out;
            }
        }
        foreach ($nums as $n) {
            $url = $custom ? str_replace('{tracking}', rawurlencode($n), $custom)
                : (isset(self::URLS[$po->carrier_code]) ? self::URLS[$po->carrier_code].rawurlencode($n) : null);
            if ($url) {
                $out[] = ['label' => 'Track '.$n, 'url' => $url];
            }
        }

        return $out;
    }

    /** HTML for tables/pages: clickable Track links (new tab), plain numbers when there is no link. */
    public static function html(PurchaseOrder $po): string
    {
        $links = self::links($po);
        $parts = array_map(fn ($l) => '<a href="'.e($l['url']).'" target="_blank" rel="noopener noreferrer" class="text-primary-600 underline" data-test="track-link">'.e($l['label']).'</a>', $links);
        if (! $links && ($nums = self::numbers($po))) {
            $parts[] = e(implode(', ', $nums));
        }

        return implode(' · ', $parts);
    }
}

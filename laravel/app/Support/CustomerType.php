<?php

namespace App\Support;

/** 0.20.3: fixed customer type list. Stored value => label. Legacy values (wholesaler/subjobber/distributor) still treated as licensees. */
class CustomerType
{
    public const OPTIONS = [
        'c_store' => 'C-store',
        'liquor_store' => 'Liquor store',
        'smoke_shop' => 'Smoke shop',
        'retailer' => 'Retailer',
        'restaurant' => 'Restaurant',
        'food_truck' => 'Food truck',
        'wholesale_distributor' => 'Wholesale distributor',
        'other' => 'Other',
    ];

    /** Types filed as licensees (KY 1B/2B, MSA Distributor). */
    public const LICENSEE = ['wholesale_distributor', 'wholesaler', 'subjobber', 'sub_jobber', 'distributor'];

    public static function label(?string $v): string
    {
        return self::OPTIONS[$v] ?? ($v ? ucfirst(str_replace('_', ' ', $v)) : '');
    }

    public static function isLicensee(?string $v): bool
    {
        return in_array(strtolower((string) $v), self::LICENSEE, true);
    }

    /** Map any free-text/legacy value (or label) to an option key; unknown => other. */
    public static function map(?string $v): string
    {
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return 'other';
        }
        if (isset(self::OPTIONS[$s])) {
            return $s;
        }
        foreach (self::OPTIONS as $k => $label) {
            if ($s === strtolower($label)) {
                return $k;
            }
        }
        $n = preg_replace('/[^a-z]+/', ' ', $s);

        return match (true) {
            (bool) preg_match('/\b(wholesal\w*|sub ?jobber|jobber|distribut\w*)\b/', $n) => 'wholesale_distributor',
            (bool) preg_match('/\b(c ?store|convenience|gas|fuel|market|mart)\b/', $n) => 'c_store',
            (bool) preg_match('/\b(liquor|package|wine|spirits|beer)\b/', $n) => 'liquor_store',
            (bool) preg_match('/\b(smoke|vape|tobacco|head shop|cigar)\b/', $n) => 'smoke_shop',
            (bool) preg_match('/\bfood ?truck\b/', $n) => 'food_truck',
            (bool) preg_match('/\b(restaurant|bar|cafe|diner|grill)\b/', $n) => 'restaurant',
            (bool) preg_match('/\b(retail\w*|store|shop)\b/', $n) => 'retailer',
            default => 'other',
        };
    }
}

<?php

namespace App\Compliance;

use App\Models\Customer;
use App\Models\Product;

/**
 * 0.21: walk-in / cash customers (the CASH customer, or any customer flagged "Walk-in / cash") cannot buy
 * regulated products. "Regulated" is the existing 0.15 product class rule: every tax class except General and
 * Beverage (cigarettes, cigars, pipe, snuff/chew, RYO, vapor, hemp/CBD, kratom). One rule, enforced in
 * ComplianceChecker::checkLine (POS, van, portal, sales-order confirm) and on order-line save.
 */
final class WalkInRule
{
    public const CASH_CODE = 'CASH';

    public static function isWalkIn(?Customer $c): bool
    {
        return $c !== null && ((bool) $c->walk_in || strtoupper((string) $c->code) === self::CASH_CODE);
    }

    public static function isWalkInId(int|string|null $customerId): bool
    {
        return $customerId ? self::isWalkIn(Customer::find($customerId)) : false;
    }

    public static function blocks(?Customer $c, ?Product $p): bool
    {
        return $p !== null && self::isWalkIn($c) && $p->isRegulated();
    }

    public static function message(Product $p): string
    {
        return "Walk-in / cash customers can't buy regulated products (tobacco, vapor, CBD/hemp, kratom): {$p->sku} {$p->name}. Use a customer account with a license, or remove this item.";
    }

    /** Reason string for ComplianceChecker, or null. */
    public static function reason(?Customer $c, ?Product $p): ?string
    {
        return self::blocks($c, $p) ? self::message($p) : null;
    }
}

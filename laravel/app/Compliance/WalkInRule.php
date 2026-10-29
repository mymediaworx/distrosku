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

    /** Account numbers that mean "the counter cash customer", however an admin typed them (case, spaces, dashes ignored). */
    private const CASH_CODES = ['CASH', 'WALKIN', 'CASHWALKIN', 'WALKINCASH', 'CASHCUSTOMER', 'CASHSALE', 'CASHSALES'];

    /** "cash-walk in " => CASHWALKIN */
    public static function normCode(?string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $code));
    }

    public static function isCashCode(?string $code): bool
    {
        return in_array(self::normCode($code), self::CASH_CODES, true);
    }

    public static function isWalkIn(?Customer $c): bool
    {
        return $c !== null && ((bool) $c->walk_in || self::isCashCode($c->code));
    }

    /**
     * The customer the POS "Walk-in (CASH)" button selects. Robust to how the account was created: account number
     * (`code`) matched ignoring case, spaces and punctuation ("Cash", " CASH ", "CASH-WALKIN"); active accounts first,
     * exact CASH before variants; then, if there is exactly one customer with the Walk-in switch on, that one.
     */
    public static function cashCustomer(): ?Customer
    {
        $all = Customer::query()->orderByDesc('active')->orderBy('id')->get(['id', 'code', 'name', 'active', 'walk_in']);
        $byCode = $all->filter(fn (Customer $c) => self::isCashCode($c->code));
        $pick = $byCode->first(fn (Customer $c) => $c->active && self::normCode($c->code) === self::CASH_CODE)
            ?? $byCode->first(fn (Customer $c) => $c->active)
            ?? $byCode->first();
        if ($pick) {
            return $pick;
        }
        $flagged = $all->filter(fn (Customer $c) => (bool) $c->walk_in && $c->active);

        return $flagged->count() === 1 ? $flagged->first() : null;
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

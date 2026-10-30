<?php

namespace App\Tax;

use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\Setting;
use App\Services\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * #43: state sales tax and exemption certificates.
 * OFF by default (Company Settings > Sales tax); nothing is charged until an owner turns it on.
 * When on, every invoice line for a customer WITHOUT a valid certificate gets "<name> <rate>%" on top of the price
 * (and on top of the product's own pass-through taxes such as excise, which are part of the sale price), through the same
 * per-line tax mechanism as 0.20.20 product taxes, so it shows on the invoice and posts to 2200 Sales Tax Payable.
 * A certificate is a customer license of type "resale" (KY 51A105) or "sales_tax_exempt" (e.g. 51A126) for the state,
 * with a number or a document, not expired on the sale date. Walk-in / cash sales never have one.
 */
class SalesTax
{
    public const EXEMPT_TYPES = ['resale', 'sales_tax_exempt'];

    public static function enabled(): bool
    {
        return (string) Setting::get('sales_tax_enabled', '0') === '1';
    }

    /** Percent, e.g. "6". */
    public static function rate(): string
    {
        $r = (string) Setting::get('sales_tax_rate', '6');

        return is_numeric($r) && (float) $r >= 0 && (float) $r < 100 ? $r : '6';
    }

    public static function name(): string
    {
        return trim((string) Setting::get('sales_tax_name', '')) ?: 'KY sales tax';
    }

    public static function state(): string
    {
        return strtoupper(trim((string) Setting::get('sales_tax_state', 'KY'))) ?: 'KY';
    }

    /**
     * Exemption status for a customer on a date.
     *
     * @return array{exempt: bool, license: ?CustomerLicense, status: string, message: string}
     *                                                                                          status: valid | expiring | expired | missing
     */
    public static function exemption(?Customer $c, CarbonInterface|string|null $date = null): array
    {
        $date = Carbon::parse($date ?? today())->startOfDay();
        if (! $c || \App\Compliance\WalkInRule::isWalkIn($c)) {
            return ['exempt' => false, 'license' => null, 'status' => 'missing', 'message' => 'Walk-in / cash sale: sales tax applies.'];
        }
        $certs = $c->licenses()->whereIn('type', self::EXEMPT_TYPES)
            ->where(fn ($q) => $q->whereNull('state')->orWhere('state', '')->orWhereRaw('UPPER(state) = ?', [self::state()]))
            ->orderByRaw('expires_at IS NULL DESC')->orderByDesc('expires_at')->get()
            ->filter(fn (CustomerLicense $l) => $l->hasProof() || ! empty($l->documents));
        $valid = $certs->first(fn (CustomerLicense $l) => ! $l->expires_at || $l->expires_at->startOfDay()->gte($date));
        if ($valid) {
            $exp = $valid->expires_at;
            $soon = $exp && $exp->startOfDay()->lte($date->copy()->addDays(30));

            return ['exempt' => true, 'license' => $valid, 'status' => $soon ? 'expiring' : 'valid',
                'message' => 'Exempt: '.self::label($valid).($exp ? ' (expires '.$exp->format('m/d/Y').')' : '').'.'];
        }
        if ($last = $certs->first()) {
            return ['exempt' => false, 'license' => $last, 'status' => 'expired',
                'message' => self::label($last).' expired '.$last->expires_at->format('m/d/Y').': sales tax applies until a current certificate is on file.'];
        }

        return ['exempt' => false, 'license' => null, 'status' => 'missing', 'message' => 'No resale or exemption certificate on file: sales tax applies.'];
    }

    private static function label(CustomerLicense $l): string
    {
        return ($l->type === 'resale' ? 'Resale certificate' : 'Exemption certificate').($l->number ? ' #'.$l->number : '');
    }

    /** The sales-tax entry for one unit, or null when off / exempt / zero rate. */
    public static function perUnit(?Customer $c, mixed $taxBasePerUnit, CarbonInterface|string|null $date = null): ?array
    {
        if (! self::enabled() || (float) self::rate() <= 0 || self::exemption($c, $date)['exempt']) {
            return null;
        }
        $amt = Money::of($taxBasePerUnit)->multipliedBy(Money::of(self::rate()))->dividedBy(100, 4, \Brick\Math\RoundingMode::HALF_UP);

        $r = self::rate();

        return ['name' => self::name().' '.(str_contains($r, '.') ? rtrim(rtrim($r, '0'), '.') : $r).'%', 'per_unit' => Money::s($amt)];
    }
}

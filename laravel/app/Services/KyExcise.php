<?php

namespace App\Services;

use App\Enums\TaxClass;
use App\Models\Product;
use App\Models\TaxRate;
use App\Models\Vendor;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Kentucky cigarette / tobacco / vapor excise math (KRS 138.140). Rates come from tax_rates rows with effective dates.
 */
class KyExcise
{
    public const CIGARETTE_PER_20 = 'cigarette_per_20';

    public const SNUFF = 'snuff_per_1_5oz';

    public const CHEW_SINGLE = 'chew_single_unit';

    public const CHEW_HALF_POUND = 'chew_half_pound_unit';

    public const CHEW_POUND = 'chew_pound_unit';

    public const CHEW_OVER_16 = 'chew_over16_per_4oz';

    public const OTP_PCT = 'otp_percent';

    public const PREMIUM_CIGAR_PCT = 'premium_cigar_percent';

    public const VAPOR_CLOSED = 'vapor_closed_per_cartridge';

    public const VAPOR_OPEN_PCT = 'vapor_open_percent';

    public const DISTRIBUTOR_DISCOUNT_PCT = 'distributor_discount_percent';

    public function rate(string $type, CarbonInterface|string|null $date = null, string $jurisdiction = 'KY'): BigDecimal
    {
        $date = Carbon::parse($date ?? today())->toDateString();
        $row = TaxRate::where('jurisdiction', $jurisdiction)->where('tax_type', $type)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->first();
        if (! $row) {
            throw new RuntimeException("No {$jurisdiction} tax rate '{$type}' effective {$date}.");
        }

        return BigDecimal::of((string) $row->rate);
    }

    /** Chewing tobacco unit bucket for one consumer unit of the given net weight. */
    public static function chewBucket(mixed $oz): string
    {
        $w = Money::of($oz);
        if ($w->isLessThan(4)) {
            return 'single';
        }
        if ($w->isLessThanOrEqualTo(8)) {
            return 'half_pound';
        }
        if ($w->isLessThanOrEqualTo(16)) {
            return 'pound';
        }

        return 'over_16';
    }

    /** Number of 1.5 oz taxable increments ("or portion thereof") in one snuff unit. */
    public static function snuffIncrements(mixed $oz): int
    {
        return (int) (string) Money::of($oz)->dividedBy('1.5', 0, RoundingMode::UP);
    }

    /** Tax on ONE consumer unit of snuff or chew. */
    public function smokelessUnitTax(Product $p, CarbonInterface|string|null $date = null): BigDecimal
    {
        if ($p->net_weight_oz === null) {
            throw new RuntimeException("Product {$p->sku} needs a net weight (oz) to compute KY smokeless tax.");
        }
        if (($p->smokeless_type ?? 'snuff') === 'snuff') {
            return $this->rate(self::SNUFF, $date)->multipliedBy(self::snuffIncrements($p->net_weight_oz));
        }

        return match (self::chewBucket($p->net_weight_oz)) {
            'single' => $this->rate(self::CHEW_SINGLE, $date),
            'half_pound' => $this->rate(self::CHEW_HALF_POUND, $date),
            'pound' => $this->rate(self::CHEW_POUND, $date),
            'over_16' => $this->rate(self::CHEW_POUND, $date)->plus(
                $this->rate(self::CHEW_OVER_16, $date)->multipliedBy(
                    (string) Money::of($p->net_weight_oz)->minus(16)->dividedBy(4, 0, RoundingMode::UP))),
        };
    }

    /** Consumer units contained in $qty sold/purchased units. */
    public static function consumerUnits(Product $p, mixed $qty): BigDecimal
    {
        return Money::of($qty)->multipliedBy(max(1, (int) $p->units_per_case));
    }

    /** Excise owed by a KY distributor on snuff/chew quantity. */
    public function smokelessTax(Product $p, mixed $qty, CarbonInterface|string|null $date = null): string
    {
        return Money::s($this->smokelessUnitTax($p, $date)->multipliedBy(self::consumerUnits($p, $qty)));
    }

    /** True when Vega (not its supplier) owes KY tax on this product bought from this vendor. */
    public static function vegaOwesOnPurchase(Product $p, ?Vendor $v): bool
    {
        return $p->tax_class === TaxClass::SnuffChew && ! $p->ky_tax_paid_by_supplier && ! ($v?->ky_tobacco_licensed);
    }
}

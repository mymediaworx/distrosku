<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** 0.18: certificate of analysis for a product (file, lab, batch/lot, test date, expiry). */
class ProductCoa extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tested_on' => 'date', 'expires_on' => 'date', 'thc_total_mg' => 'decimal:4'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public static function warnDays(): int
    {
        return max(0, (int) (Setting::get('coa_expiry_warn_days', 30) ?? 30));
    }

    /** current | expiring | expired | no_expiry */
    public function state(CarbonInterface|string|null $on = null): string
    {
        $on = $on ? Carbon::parse($on)->startOfDay() : Carbon::today();
        if (! $this->expires_on) {
            return 'no_expiry';
        }
        if ($this->expires_on->lt($on)) {
            return 'expired';
        }

        return $this->expires_on->lte($on->copy()->addDays(self::warnDays())) ? 'expiring' : 'current';
    }

    /** Is there a COA in force for the product on the date (tested on/before, not expired)? */
    public static function currentFor(Product $p, CarbonInterface|string $on): bool
    {
        $d = Carbon::parse($on)->toDateString();

        return static::where('product_id', $p->id)
            ->where(fn ($q) => $q->whereNull('tested_on')->orWhereDate('tested_on', '<=', $d))
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $d))->exists();
    }

    /** COAs expiring within the warning window (or already expired, newest per product only). */
    public static function expiringSoon(?int $days = null)
    {
        $until = Carbon::today()->addDays($days ?? self::warnDays())->toDateString();

        return static::with('product')->whereNotNull('expires_on')->whereDate('expires_on', '<=', $until)
            ->whereIn('id', static::selectRaw('max(id)')->groupBy('product_id'))->orderBy('expires_on');
    }
}

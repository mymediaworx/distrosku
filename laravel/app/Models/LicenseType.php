<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 0.20.6: Settings › License types. `code` is what customer_licenses.type and compliance rules store. */
class LicenseType extends Model
{
    use \App\Dba\ManagedByDba;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** code => name; includes a current value that is not in the list so it stays visible. */
    public static function options(?string $current = null, bool $activeOnly = true): array
    {
        try {
            $q = static::query()->orderBy('sort')->orderBy('name');
            if ($activeOnly) {
                $q->where(fn ($w) => $w->where('active', true)->when($current, fn ($w) => $w->orWhere('code', $current)));
            }
            $o = $q->pluck('name', 'code')->all();
        } catch (\Throwable) {
            $o = [];
        }
        if ($current && ! isset($o[$current])) {
            $o[$current] = $current;
        }

        return $o;
    }

    public static function label(?string $code): string
    {
        if (! $code) {
            return '';
        }
        try {
            return static::where('code', $code)->value('name') ?? $code;
        } catch (\Throwable) {
            return $code;
        }
    }

    /** Ensure the starter list exists (idempotent). */
    public static function seedDefaults(): void
    {
        $defaults = [
            ['tobacco_retail', 'KY Tobacco License', 'KY', 10],
            ['hemp', 'KY Hemp License', 'KY', 20],
            ['tobacco_wholesale', 'Tobacco Wholesale License', null, 30],
            ['resale', 'Resale Certificate', null, 40],
            ['sales_tax_exempt', 'Sales Tax Exemption Certificate', null, 45], // #43
            ['kratom', 'Kratom License', null, 50],
        ];
        foreach ($defaults as [$code, $name, $state, $sort]) {
            if (! static::where('code', $code)->exists()) {
                static::create(['code' => $code, 'name' => $name, 'state' => $state, 'sort' => $sort, 'active' => true]);
            }
        }
    }
}

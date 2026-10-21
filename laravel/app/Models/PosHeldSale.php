<?php

namespace App\Models;

use App\Audit\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** 0.19.3: a POS cart put on hold. Reserves no stock; expires after Setting pos_hold_days (default 7). */
class PosHeldSale extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cart' => 'array', 'expires_at' => 'datetime'];
    }

    public static function days(): int
    {
        return max(1, (int) Setting::get('pos_hold_days', '7'));
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Delete expired holds, logging each. Returns the count. */
    public static function purgeExpired(): int
    {
        $n = 0;
        static::whereNotNull('expires_at')->where('expires_at', '<=', now())->chunkById(200, function ($rows) use (&$n) {
            foreach ($rows as $h) {
                Audit::action('pos_hold_expired', null, 'Held POS sale expired: '.($h->label ?: '#'.$h->id), ['held_id' => $h->id, 'customer_id' => $h->customer_id, 'lines' => count($h->cart ?? []), 'total' => $h->total]);
                $h->delete();
                $n++;
            }
        });

        return $n;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PactRegistration extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filed_on' => 'date', 'expires_on' => 'date', 'active' => 'boolean'];
    }

    /** Active and not expired on a date. */
    public static function current(string $jurisdiction, ?string $date = null): ?self
    {
        $d = $date ?? now()->toDateString();

        return static::where('jurisdiction', strtoupper($jurisdiction))->where('active', true)->whereNotNull('filed_on')->whereDate('filed_on', '<=', $d)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $d))->first();
    }
}

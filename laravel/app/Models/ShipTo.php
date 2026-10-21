<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShipTo extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['msa_cash_and_carry' => 'boolean', 'is_default' => 'boolean'];
    }

    /** 0.19.3: exactly one default ship-to per customer (the first one is default automatically). */
    protected static function booted(): void
    {
        static::creating(function (ShipTo $s) {
            if (! $s->is_default && ! static::where('customer_id', $s->customer_id)->where('is_default', true)->exists()) {
                $s->is_default = true;
            }
        });
        static::saved(function (ShipTo $s) {
            if ($s->is_default && ($s->wasChanged('is_default') || $s->wasRecentlyCreated)) {
                static::where('customer_id', $s->customer_id)->whereKeyNot($s->id)->where('is_default', true)->update(['is_default' => false]);
            } elseif (! $s->is_default && ! static::where('customer_id', $s->customer_id)->where('is_default', true)->exists()) {
                static::where('customer_id', $s->customer_id)->orderBy('id')->limit(1)->update(['is_default' => true]);
            }
        });
        static::deleted(function (ShipTo $s) {
            if ($s->is_default) {
                static::where('customer_id', $s->customer_id)->orderBy('id')->limit(1)->update(['is_default' => true]);
            }
        });
    }

    /** Orders still point at this ship-to (they keep the address), so it cannot be deleted. */
    public function inUse(): bool
    {
        return \App\Models\SalesOrder::where('ship_to_id', $this->id)->exists();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function licenses()
    {
        return $this->hasMany(CustomerLicense::class);
    }
}

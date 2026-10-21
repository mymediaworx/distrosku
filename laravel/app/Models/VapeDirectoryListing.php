<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** 0.18: a SKU listed on a state's vapor product directory, for a date range. */
class VapeDirectoryListing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['listed_from' => 'date', 'listed_to' => 'date', 'manual' => 'boolean'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function registryEntry()
    {
        return $this->belongsTo(StateRegistryEntry::class, 'registry_entry_id');
    }

    public static function listed(Product $p, string $state, string $date): bool
    {
        $d = Carbon::parse($date)->toDateString();

        return static::where('product_id', $p->id)->where('state', strtoupper($state))->whereDate('listed_from', '<=', $d)
            ->where(fn ($q) => $q->whereNull('listed_to')->orWhereDate('listed_to', '>=', $d))->exists();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Shared product master used for price research across SKU entries. */
class SharedProduct extends Model
{
    protected $guarded = ['id'];

    public static function normalize(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n);

        return trim(preg_replace('/\s+/', ' ', $n));
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function priceSubmissions()
    {
        return $this->hasMany(PriceSubmission::class);
    }
}

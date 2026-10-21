<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:4'];
    }

    public function sharedProduct()
    {
        return $this->belongsTo(SharedProduct::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

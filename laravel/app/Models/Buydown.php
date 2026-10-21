<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Buydown extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['units_sold' => '0', 'status' => 'active', 'kind' => 'buydown'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'amount_per_unit' => 'decimal:4',
            'units_sold' => 'decimal:4',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function accruals()
    {
        return $this->hasMany(BuydownAccrual::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

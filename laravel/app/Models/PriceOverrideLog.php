<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceOverrideLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quoted_price' => 'decimal:4', 'old_price' => 'decimal:4', 'new_price' => 'decimal:4'];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

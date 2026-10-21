<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledCost extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cost' => 'decimal:4', 'old_cost' => 'decimal:4', 'effective_on' => 'date', 'applied_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }
}

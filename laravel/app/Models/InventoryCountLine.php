<?php

namespace App\Models;

use App\Services\Money;
use Illuminate\Database\Eloquent\Model;

class InventoryCountLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected' => 'decimal:4', 'counted' => 'decimal:4', 'unit_cost' => 'decimal:4', 'counted_at' => 'datetime'];
    }

    public function inventoryCount()
    {
        return $this->belongsTo(InventoryCount::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function bin()
    {
        return $this->belongsTo(Bin::class);
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function scans()
    {
        return $this->hasMany(InventoryCountScan::class);
    }

    public function variance(): string
    {
        return $this->counted === null ? '0' : Money::s(Money::of($this->counted)->minus(Money::of($this->expected)));
    }

    public function varianceValue(): string
    {
        return Money::mul($this->variance(), $this->unit_cost);
    }
}

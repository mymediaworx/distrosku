<?php

namespace App\Models;

use App\Pricing\PricingEngine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['unit_cost' => '0', 'line_total' => '0'];

    /** Set by PricingEngine when a manual override needs logging after save. */
    public ?array $pendingOverrideLog = null;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
            'list_price' => 'decimal:4',
            'quoted_price' => 'decimal:4',
            'price_overridden' => 'boolean',
        ];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function priceRule()
    {
        return $this->belongsTo(PriceRule::class);
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::creating(fn (self $l) => Product::assertOrderable($l->product_id));
        static::updating(fn (self $l) => $l->isDirty('product_id') ? Product::assertOrderable($l->product_id) : null);
        static::saving(fn (self $l) => app(PricingEngine::class)->applyToLine($l));
        static::saved(fn (self $l) => PricingEngine::writeLog($l));
    }
}

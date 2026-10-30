<?php

namespace App\Models;

use App\Pricing\PricingEngine;
use App\Models\Concerns\HasLineUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    use HasFactory, HasLineUnit;

    protected $guarded = ['id'];

    protected $attributes = ['unit_cost' => '0', 'line_total' => '0', 'tax_per_unit' => '0', 'tax_total' => '0'];

    /** Set by PricingEngine when a manual override needs logging after save. */
    public ?array $pendingOverrideLog = null;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
            'list_price' => 'decimal:4',
            'quoted_price' => 'decimal:4',
            'price_overridden' => 'boolean',
            'tax_per_unit' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'tax_detail' => 'array',
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

    /** 0.21: walk-in / cash customers cannot have regulated products on an order. */
    private static function assertWalkInAllowed(self $l): void
    {
        $so = $l->sales_order_id ? SalesOrder::with('customer')->find($l->sales_order_id) : null;
        $p = $l->product_id ? Product::find($l->product_id) : null;
        if ($so && \App\Compliance\WalkInRule::blocks($so->customer, $p)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['product_id' => \App\Compliance\WalkInRule::message($p)]);
        }
    }

    protected static function booted(): void
    {
        static::creating(function (self $l) {
            Product::assertOrderable($l->product_id);
            self::assertWalkInAllowed($l);
        });
        static::updating(function (self $l) {
            if ($l->isDirty('product_id')) {
                Product::assertOrderable($l->product_id);
                self::assertWalkInAllowed($l);
            }
        });
        static::saving(fn (self $l) => app(PricingEngine::class)->applyToLine($l));
        static::saved(fn (self $l) => PricingEngine::writeLog($l));
    }
}

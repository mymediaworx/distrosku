<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 0.20.15 (#36): a lot-tracked sale line that left without an assignable lot. Never silent; resolved by choosing the lot. */
class LotReviewItem extends Model
{
    public const REASONS = [
        'no_lot_stock' => 'No lot stock on hand',
        'several_lots' => 'Several lots on hand, none chosen',
        'lot_short' => 'Chosen/assigned lot did not cover the qty',
        'lot_unavailable' => 'Chosen lot is on hold, expired or not this product',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'resolved_at' => 'datetime'];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function line()
    {
        return $this->belongsTo(SalesOrderLine::class, 'sales_order_line_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}

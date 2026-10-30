<?php

namespace App\Models;

use App\Models\Concerns\HasLineUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    use HasFactory, HasLineUnit;

    protected $guarded = ['id'];

    protected $attributes = ['qty_received' => '0', 'excise_accrued' => '0', 'unit_cost' => '0', 'qty_billed' => '0', 'qty_received_legacy' => '0'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'qty_received' => 'decimal:4',
            'excise_accrued' => 'decimal:4',
            'qty_billed' => 'decimal:4',
            'qty_received_legacy' => 'decimal:4',
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    protected static function booted(): void
    {
        static::creating(fn (self $l) => Product::assertOrderable($l->product_id));
        static::updating(fn (self $l) => $l->isDirty('product_id') ? Product::assertOrderable($l->product_id) : null);
    }
}

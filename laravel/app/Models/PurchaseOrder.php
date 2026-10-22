<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use App\Services\Sequence;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasActivity;
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (blank($m->number)) {
                $m->number = Sequence::next('PO', static::class);
            }
        });
        static::saved(fn (self $m) => \App\Purchasing\ReceivingAlerts::onSaved($m)); // 0.20.1 receiving-team alerts
    }

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'received_at' => 'datetime',
            'total' => 'decimal:4',
            'expected_at' => 'date',
            'ordered_at' => 'datetime',
            'tracking_numbers' => 'array',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }
}

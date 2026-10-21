<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use App\Services\SalesOrderService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class DeliveryStop extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // A stop marked delivered delivers its sales order (stock out). Failure aborts the save.
        static::saved(function (self $stop) {
            if (! in_array($stop->status, ['delivered', 'partial'], true) || ! ($stop->wasChanged('status') || $stop->wasRecentlyCreated)) {
                return;
            }
            $so = $stop->salesOrder;
            if ($so && in_array($so->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Picked], true)) {
                try {
                    app(SalesOrderService::class)->deliver($so);
                } catch (\Throwable $e) {
                    throw ValidationException::withMessages(['stops' => "Stop for {$so->number}: ".$e->getMessage()]);
                }
            }
            if (! $stop->delivered_at) {
                $stop->delivered_at = now();
                $stop->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function manifest()
    {
        return $this->belongsTo(DeliveryManifest::class, 'delivery_manifest_id');
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function signatures()
    {
        return $this->hasMany(DeliverySignature::class);
    }

    public function mapUrl(): ?string
    {
        $st = $this->salesOrder?->shipTo;
        $addr = $st ? trim(implode(', ', array_filter([$st->address, $st->city, $st->state, $st->zip]))) : '';

        return $addr !== '' ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($addr) : null;
    }
}

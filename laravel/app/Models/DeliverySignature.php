<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DeliverySignature extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['acknowledgments' => 'array', 'account_charge' => 'boolean', 'signed_at' => 'datetime', 'order_total' => 'decimal:4', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function stop()
    {
        return $this->belongsTo(DeliveryStop::class, 'delivery_stop_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** data: URI of the stored PNG (for PDFs and the order screen; files are on the private disk). */
    public function dataUri(): ?string
    {
        $d = Storage::disk('local');

        return $d->exists($this->image_path) ? 'data:image/png;base64,'.base64_encode($d->get($this->image_path)) : null;
    }
}

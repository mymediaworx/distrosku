<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['weight_lb' => 'decimal:2', 'shipped_at' => 'datetime', 'label_printed_at' => 'datetime'];
    }

    public function pickList()
    {
        return $this->belongsTo(PickList::class);
    }

    public function carrier()
    {
        return $this->belongsTo(Carrier::class);
    }

    public function trackingUrl(): ?string
    {
        return $this->carrier?->trackingUrl($this->tracking_number);
    }

    public function lines()
    {
        return $this->hasMany(PackageLine::class);
    }
}

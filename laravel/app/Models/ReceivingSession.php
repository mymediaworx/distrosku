<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivingSession extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'variances' => 'array'];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function scans()
    {
        return $this->hasMany(ReceivingScan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

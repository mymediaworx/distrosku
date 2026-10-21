<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function shipTo()
    {
        return $this->belongsTo(ShipTo::class);
    }
}

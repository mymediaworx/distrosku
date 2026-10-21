<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorPaymentAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function payment()
    {
        return $this->belongsTo(VendorPayment::class, 'vendor_payment_id');
    }

    public function bill()
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }
}

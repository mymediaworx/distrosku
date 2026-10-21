<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorPayment extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'posted'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:4'];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function allocations()
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }
}

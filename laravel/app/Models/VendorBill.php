<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use App\Services\Money;
use Illuminate\Database\Eloquent\Model;

class VendorBill extends Model
{
    use HasActivity;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'match_status' => 'none', 'total' => '0', 'amount_paid' => '0'];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'due_date' => 'date', 'posted_at' => 'datetime', 'match_notes' => 'array',
            'total' => 'decimal:4', 'amount_paid' => 'decimal:4'];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(VendorBillLine::class);
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function allocations()
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }

    public function balance(): string
    {
        return Money::s(Money::of($this->total)->minus(Money::of($this->amount_paid)));
    }
}

<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use App\Models\Concerns\HasActivity;
use App\Services\Money;
use App\Services\Sequence;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    use HasActivity;
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['amount_paid' => '0', 'amount_credited' => '0', 'subtotal' => '0', 'total' => '0', 'status' => 'draft'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (blank($m->number)) {
                $m->number = Sequence::next('SO', static::class);
            }
            if (blank($m->sales_rep_id) && $m->customer_id) {
                $m->sales_rep_id = Customer::whereKey($m->customer_id)->value('sales_rep_id');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'order_date' => 'date',
            'ship_date' => 'date',
            'elig_override_at' => 'datetime',
            'elig_warnings' => 'array',
            'compliance_reasons' => 'array',
            'confirmed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'amount_credited' => 'decimal:4',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesRep()
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function shipTo()
    {
        return $this->belongsTo(ShipTo::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function creditMemos()
    {
        return $this->hasMany(CreditMemo::class);
    }

    /** Open AR on this order: total - credited - paid. */
    public function balance(): string
    {
        return Money::s(Money::of($this->total)->minus(Money::of($this->amount_credited))->minus(Money::of($this->amount_paid)));
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function signatures()
    {
        return $this->hasMany(DeliverySignature::class);
    }

    public function deliveryStops()
    {
        return $this->hasMany(DeliveryStop::class);
    }
}

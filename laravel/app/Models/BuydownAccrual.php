<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One buydown/rebate earned on one invoiced sales line; claimed_at/claim_ref set when submitted to the manufacturer. */
class BuydownAccrual extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sold_on' => 'date', 'qty' => 'decimal:4', 'amount_per_unit' => 'decimal:4', 'amount' => 'decimal:4', 'claimed_at' => 'datetime', 'paid_at' => 'date'];
    }

    public function creditMemo()
    {
        return $this->belongsTo(CreditMemo::class);
    }

    public function buydown()
    {
        return $this->belongsTo(Buydown::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Model;

/** One price for a product: customer contract, customer-group, quantity break and/or dated (effective/expiry). */
class PriceRule extends Model
{
    use HasActivity;

    protected $guarded = ['id'];

    protected $attributes = ['min_qty' => '1', 'active' => true];

    protected function casts(): array
    {
        return ['min_qty' => 'decimal:4', 'price' => 'decimal:4', 'starts_on' => 'date', 'ends_on' => 'date', 'active' => 'boolean'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    public function scopeLabel(): string
    {
        return $this->customer_id ? 'contract' : ($this->customer_group_id ? 'group' : (bccomp($this->min_qty, '1', 4) > 0 ? 'qty_break' : 'dated'));
    }
}

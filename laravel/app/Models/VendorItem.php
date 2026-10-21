<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_cost' => 'decimal:4', 'last_cost_on' => 'date'];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Pending (not yet applied) cost changes for this item's product from this vendor. */
    public function scheduledCosts()
    {
        return $this->hasMany(ScheduledCost::class, 'product_id', 'product_id')->where('vendor_id', $this->vendor_id)->whereNull('applied_at')->orderBy('effective_on');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

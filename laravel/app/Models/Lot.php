<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lot extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['on_hold' => false];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'on_hold' => 'boolean'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stocks()
    {
        return $this->hasMany(LotStock::class);
    }

    public function coas()
    {
        return $this->hasMany(ProductCoa::class);
    }

    /**
     * 0.20.15 (#36): a hemp/CBD lot with no COA linked to it (lot_id) and none whose batch/lot text matches.
     * Non-hemp lots never flag.
     */
    public function coaMissing(): bool
    {
        $p = $this->product;
        if (! $p || ! $p->hasCannabinoid()) {
            return false;
        }

        return ! ProductCoa::where('product_id', $p->id)->where(fn ($q) => $q->where('lot_id', $this->id)->orWhere('batch_lot', $this->lot_number))->exists();
    }

    public function movements()
    {
        return $this->hasMany(LotMovement::class);
    }
}

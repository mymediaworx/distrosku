<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** #40: stamps put on packs: N packs of the unstamped SKU become N packs of the stamped SKU (one stamp per pack). */
class TaxStampAffixing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['affixed_on' => 'date'];
    }

    public function fromProduct()
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct()
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

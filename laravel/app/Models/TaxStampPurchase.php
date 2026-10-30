<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** #40: tax stamps bought from a state (one row per order of stamps). */
class TaxStampPurchase extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'unit_cost' => 'decimal:4', 'total' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

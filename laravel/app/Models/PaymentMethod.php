<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tokenised payment method (gateway customer + payment profile ids). Never holds PAN or bank account numbers. */
class PaymentMethod extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'active' => 'boolean'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 0.20.15 (#36): recall contacted log: who reached which customer about a lot, when and how. */
class LotContact extends Model
{
    public const METHODS = ['phone' => 'Phone', 'email' => 'Email', 'text' => 'Text', 'in_person' => 'In person', 'letter' => 'Letter', 'other' => 'Other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['contacted_at' => 'datetime'];
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function shipTo()
    {
        return $this->belongsTo(ShipTo::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

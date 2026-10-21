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

    public function movements()
    {
        return $this->hasMany(LotMovement::class);
    }
}

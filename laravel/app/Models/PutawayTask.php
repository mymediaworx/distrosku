<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PutawayTask extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'done_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function suggestedBin()
    {
        return $this->belongsTo(Bin::class, 'suggested_bin_id');
    }

    public function bin()
    {
        return $this->belongsTo(Bin::class);
    }
}

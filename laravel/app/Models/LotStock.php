<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LotStock extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}

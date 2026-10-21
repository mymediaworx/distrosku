<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCountScan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function line()
    {
        return $this->belongsTo(InventoryCountLine::class, 'inventory_count_line_id');
    }
}

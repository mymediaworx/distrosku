<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickLineLot extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['qty_allocated' => '0', 'qty_picked' => '0'];

    protected function casts(): array
    {
        return ['qty_allocated' => 'decimal:4', 'qty_picked' => 'decimal:4'];
    }

    public function lot()
    {
        return $this->belongsTo(Lot::class);
    }

    public function line()
    {
        return $this->belongsTo(PickListLine::class, 'pick_list_line_id');
    }
}

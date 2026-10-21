<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickListLine extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['qty_picked' => '0', 'qty_packed' => '0'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'qty_picked' => 'decimal:4', 'qty_packed' => 'decimal:4'];
    }

    public function pickList()
    {
        return $this->belongsTo(PickList::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function bin()
    {
        return $this->belongsTo(Bin::class);
    }

    public function salesOrderLine()
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function lots()
    {
        return $this->hasMany(PickLineLot::class);
    }
}

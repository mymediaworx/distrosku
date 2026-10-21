<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditMemoLine extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['disposition' => 'restock', 'unit_price' => '0', 'unit_cost' => '0', 'line_total' => '0'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_price' => 'decimal:4', 'unit_cost' => 'decimal:4', 'line_total' => 'decimal:4'];
    }

    public function creditMemo()
    {
        return $this->belongsTo(CreditMemo::class);
    }

    public function salesOrderLine()
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    use HasFactory;

    protected $table = 'inventories';

    protected $guarded = ['id'];

    protected $attributes = ['qty' => '0', 'reserved' => '0'];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'reserved' => 'decimal:4',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function bin()
    {
        return $this->belongsTo(Bin::class);
    }
}

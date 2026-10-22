<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    use HasActivity;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_licensed_tobacco' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** 0.20.1: receiving team (inbound PO alerts). */
    public function receivers()
    {
        return $this->belongsToMany(User::class, 'warehouse_receivers')->withTimestamps();
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}

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

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}

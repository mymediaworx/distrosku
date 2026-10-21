<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PickList extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['picked_at' => 'datetime', 'packed_at' => 'datetime'];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(PickListLine::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    public function picker()
    {
        return $this->belongsTo(User::class, 'picker_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackageLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4'];
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function pickLine()
    {
        return $this->belongsTo(PickListLine::class, 'pick_list_line_id');
    }
}

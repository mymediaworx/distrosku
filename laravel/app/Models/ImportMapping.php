<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportMapping extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }
}

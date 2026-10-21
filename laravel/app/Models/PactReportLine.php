<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PactReportLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['shipped_on' => 'date', 'qty' => 'decimal:4', 'sticks_or_units' => 'decimal:4'];
    }
}

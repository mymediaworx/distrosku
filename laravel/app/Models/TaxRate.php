<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use \App\Dba\ManagedByDba;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:6', 'increment_oz' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date', 'needs_verification' => 'boolean'];
    }
}

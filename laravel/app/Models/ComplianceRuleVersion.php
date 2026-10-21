<?php

namespace App\Models;

use App\Enums\TaxClass;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ComplianceRuleVersion extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tax_class' => TaxClass::class,
            'banned' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'max_thc_mg' => 'decimal:4',
        ];
    }
}

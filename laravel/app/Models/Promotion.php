<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Model;

/** Date-bound % or $-per-unit off, targeted by product, department, customer and/or customer group (blank = any). */
class Promotion extends Model
{
    use HasActivity;

    protected $guarded = ['id'];

    protected $attributes = ['kind' => 'percent', 'active' => true];

    protected function casts(): array
    {
        return ['value' => 'decimal:4', 'starts_on' => 'date', 'ends_on' => 'date', 'active' => 'boolean'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class);
    }
}

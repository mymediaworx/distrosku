<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use App\Services\Sequence;
use Illuminate\Database\Eloquent\Model;

class CreditMemo extends Model
{
    use HasActivity;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'total' => '0', 'cost_total' => '0'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (blank($m->number)) {
                $m->number = Sequence::next('CM', static::class);
            }
        });
    }

    protected function casts(): array
    {
        return ['memo_date' => 'date', 'posted_at' => 'datetime', 'total' => 'decimal:4', 'cost_total' => 'decimal:4'];
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines()
    {
        return $this->hasMany(CreditMemoLine::class);
    }
}

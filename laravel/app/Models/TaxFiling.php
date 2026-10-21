<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Model;

class TaxFiling extends Model
{
    use HasActivity;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'summary' => 'array', 'data' => 'array', 'tax_due' => 'decimal:4', 'amount_paid' => 'decimal:4', 'paid_on' => 'date', 'filed_at' => 'datetime', 'approved_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function amends()
    {
        return $this->belongsTo(self::class, 'amends_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'filed'], true);
    }

    public function filer()
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}

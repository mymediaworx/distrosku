<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatement extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'reconciled_at' => 'datetime', 'summary' => 'array',
            'opening_balance' => 'decimal:4', 'closing_balance' => 'decimal:4'];
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function lines()
    {
        return $this->hasMany(BankStatementLine::class);
    }
}

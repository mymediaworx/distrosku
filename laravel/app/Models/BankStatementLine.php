<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'unmatched'];

    protected function casts(): array
    {
        return ['txn_date' => 'date', 'amount' => 'decimal:4'];
    }

    public function statement()
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function journalLine()
    {
        return $this->belongsTo(JournalLine::class);
    }
}

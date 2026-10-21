<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerBalanceAudit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['old_balance' => 'decimal:4', 'new_balance' => 'decimal:4', 'old_date' => 'date', 'new_date' => 'date'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }
}

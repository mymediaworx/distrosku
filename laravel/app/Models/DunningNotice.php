<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An overdue reminder. A draft staff can print / download; with auto-email on (0.20.18) the daily job emails it (see email_* columns). */
class DunningNotice extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['emailed_at' => 'datetime', 'printed_at' => 'datetime', 'invoices' => 'array', 'overdue_amount' => 'decimal:4', 'balance' => 'decimal:4'];
    }

    public function batch()
    {
        return $this->belongsTo(DunningBatch::class, 'dunning_batch_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}

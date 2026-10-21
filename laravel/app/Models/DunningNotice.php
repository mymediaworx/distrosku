<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A draft overdue reminder. The ERP never sends it; staff review and send from their own mailbox. */
class DunningNotice extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['invoices' => 'array', 'overdue_amount' => 'decimal:4', 'balance' => 'decimal:4'];
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

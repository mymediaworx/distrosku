<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Issue #26: a sale recorded on a POS / van device while offline, as received at sync. */
class OfflineSale extends Model
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const NEEDS_REVIEW = 'needs_review';

    public const RESOLVED = 'resolved';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'problems' => 'array',
            'warnings' => 'array',
            'sold_at' => 'datetime',
            'synced_at' => 'datetime',
            'resolved_at' => 'datetime',
            'total' => 'decimal:4',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function syncedBy()
    {
        return $this->belongsTo(User::class, 'synced_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }
}

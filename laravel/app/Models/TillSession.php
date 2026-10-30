<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** #44: one cash drawer shift on one register. */
class TillSession extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_float' => 'decimal:2', 'expected_cash' => 'decimal:2',
            'counted_cash' => 'decimal:2', 'variance' => 'decimal:2', 'count_detail' => 'array'];
    }

    public function movements()
    {
        return $this->hasMany(TillMovement::class);
    }

    public function salesOrders()
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function openedBy()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}

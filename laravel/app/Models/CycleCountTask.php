<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CycleCountTask extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['task_date' => 'date', 'completed_at' => 'datetime', 'priority' => 'integer'];
    }

    public const REASONS = ['discrepancy' => 'Past count discrepancy', 'high_risk' => 'High-risk category', 'abc_a' => 'A item (high velocity/value)',
        'not_recent' => 'Not counted recently', 'abc_b' => 'B item', 'abc_c' => 'C item'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function inventoryCount()
    {
        return $this->belongsTo(InventoryCount::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}

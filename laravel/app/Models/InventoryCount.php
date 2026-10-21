<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Model;

class InventoryCount extends Model
{
    use HasActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['blind' => 'boolean', 'auto_approved' => 'boolean', 'scope' => 'array', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'posted_at' => 'datetime',
            'variance_value' => 'decimal:4'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(InventoryCountLine::class);
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['counting', 'submitted'], true);
    }
}

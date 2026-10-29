<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 0.20.20: a count typed on the Quick Tools page. Never moves stock until an admin applies it. */
class QuickCount extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['counted_qty' => 'decimal:4', 'on_hand_at_review' => 'decimal:4', 'applied_delta' => 'decimal:4', 'reviewed_at' => 'datetime'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

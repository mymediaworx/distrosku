<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DunningBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['as_of' => 'date', 'total_overdue' => 'decimal:4'];
    }

    public function notices()
    {
        return $this->hasMany(DunningNotice::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

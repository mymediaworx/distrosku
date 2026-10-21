<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PactReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'totals' => 'array', 'generated_at' => 'datetime', 'filed_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(PactReportLine::class);
    }

    public function filer()
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}

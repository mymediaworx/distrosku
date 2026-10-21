<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodAudit extends Model
{
    protected $guarded = ['id'];

    public function period()
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

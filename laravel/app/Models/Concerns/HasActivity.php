<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;

/** History tab: activity log rows for this record. */
trait HasActivity
{
    public function activities()
    {
        return $this->morphMany(ActivityLog::class, 'subject')->latest('id');
    }
}

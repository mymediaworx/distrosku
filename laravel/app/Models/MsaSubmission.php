<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Archived MULTICAT file (one row per generated file; the .TOB itself is kept on the local disk). */
class MsaSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['week_ending' => 'date', 'test' => 'boolean', 'altria' => 'boolean', 'warnings' => 'array', 'uploaded_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** #44: cash put into (paid_in) or taken out of (paid_out, drop) the drawer during a till session. */
class TillMovement extends Model
{
    protected $guarded = ['id'];

    public const TYPES = ['paid_in' => 'Paid in', 'paid_out' => 'Paid out', 'drop' => 'Safe drop'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function session()
    {
        return $this->belongsTo(TillSession::class, 'till_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

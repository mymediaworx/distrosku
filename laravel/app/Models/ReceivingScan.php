<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceivingScan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'expires_on' => 'date'];
    }

    public function session()
    {
        return $this->belongsTo(ReceivingSession::class, 'receiving_session_id');
    }

    public function poLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}

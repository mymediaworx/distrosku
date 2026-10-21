<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 0.19: email / SMS template for a transactional event ({customer} {order} {total} {date} {due} {route} {company}). */
class TransactionalTemplate extends Model
{
    protected $guarded = ['id'];

    public const KEYS = ['order_confirmed' => 'Order confirmed', 'delivery_eta' => 'Delivery scheduled (ETA)', 'invoice_emailed' => 'Invoice ready'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function render(array $vars): array
    {
        $r = fn (?string $s) => $s === null ? null : strtr($s, collect($vars)->mapWithKeys(fn ($v, $k) => ['{'.$k.'}' => (string) $v])->all());

        return ['subject' => $r($this->subject), 'body' => $r($this->body)];
    }
}

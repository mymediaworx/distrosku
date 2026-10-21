<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Carrier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_postal' => 'boolean', 'active' => 'boolean'];
    }

    public function trackingUrl(?string $tracking): ?string
    {
        $tracking = trim((string) $tracking);
        if ($tracking === '' || ! $this->tracking_url || ! str_contains($this->tracking_url, '{tracking}')) {
            return null;
        }

        return str_replace('{tracking}', rawurlencode($tracking), $this->tracking_url);
    }
}

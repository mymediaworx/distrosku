<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MsaDirectoryEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date'];
    }

    /** Upper-case letters and digits only: "Marlboro (Red)" -> "MARLBORORED". */
    public static function key(?string $brand): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $brand));
    }
}

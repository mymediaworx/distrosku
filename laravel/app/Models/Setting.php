<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Key/value settings, values encrypted at rest with APP_KEY. */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $v = static::find($key)?->value;
        } catch (\Throwable) { // table missing (pre-migration) or undecryptable
            $v = null;
        }

        return $v === null || $v === '' ? $default : $v;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function installId(): string
    {
        $id = static::get('install_id');
        if (! $id) {
            $id = (string) Str::uuid();
            static::put('install_id', $id);
        }

        return $id;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** The single company record (single-tenant install). */
class Company extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['public_token'];

    protected function casts(): array
    {
        return ['suspended' => 'boolean', 'suspended_at' => 'datetime', 'public_token_rotated_at' => 'datetime'];
    }

    public static function current(): self
    {
        static $cache = null;
        if ($cache && $cache->exists && ! app()->runningUnitTests()) {
            return $cache;
        }
        try {
            $c = static::query()->orderBy('id')->first();
        } catch (\Throwable) { // table missing before migration
            return new static(['name' => config('erp.company')]);
        }

        return $cache = $c ?? static::create(['name' => config('erp.company')]);
    }

    public static function isSuspended(): bool
    {
        try {
            return Schema::hasTable('companies') && (bool) static::query()->where('suspended', true)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function rotateToken(): string
    {
        $this->forceFill(['public_token' => bin2hex(random_bytes(24)), 'public_token_rotated_at' => now()])->save();

        return $this->public_token;
    }

    public function publicUrl(): ?string
    {
        return $this->public_token ? url('/q/'.$this->public_token) : null;
    }
}

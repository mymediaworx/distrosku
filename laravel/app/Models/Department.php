<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Department $d) => $d->code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $d->code)));
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}

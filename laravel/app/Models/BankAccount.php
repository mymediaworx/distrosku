<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cleared_through' => 'date', 'is_default' => 'boolean', 'active' => 'boolean'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function statements()
    {
        return $this->hasMany(BankStatement::class);
    }

    public static function default(): ?self
    {
        return static::where('active', true)->orderByDesc('is_default')->orderBy('id')->first();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MsaDirectoryImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_on' => 'date'];
    }

    public function entries()
    {
        return $this->hasMany(MsaDirectoryEntry::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

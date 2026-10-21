<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    use HasActivity;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'total' => 'decimal:4',
            'reversed_at' => 'datetime',
        ];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function reversalOf()
    {
        return $this->belongsTo(Journal::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(Journal::class, 'reversal_of_id');
    }

    public function source()
    {
        return $this->morphTo();
    }
}

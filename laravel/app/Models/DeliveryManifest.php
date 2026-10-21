<?php

namespace App\Models;

use App\Models\Concerns\HasActivity;
use App\Services\Sequence;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryManifest extends Model
{
    use HasActivity;
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            if (blank($m->number)) {
                $m->number = Sequence::next('DM', static::class);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function driver()
    {
        return $this->belongsTo(User::class);
    }

    public function stops()
    {
        return $this->hasMany(DeliveryStop::class)->orderBy('sequence');
    }
}

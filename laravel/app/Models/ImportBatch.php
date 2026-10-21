<?php

namespace App\Models;

use App\Imports\ImportRegistry;
use App\Imports\Types\ImportType;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use Concerns\HasActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'effective_on' => 'date', 'validated_at' => 'datetime', 'committed_at' => 'datetime'];
    }

    public function rows()
    {
        return $this->hasMany(ImportRow::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): ImportType
    {
        return ImportRegistry::make($this->type);
    }
}

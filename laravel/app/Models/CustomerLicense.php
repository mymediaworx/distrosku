<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerLicense extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /** KY ABC public license lookup (search form; Site ID is not accepted as a URL query param). */
    public const KY_ABC_LOOKUP_URL = 'https://abcportal.ky.gov/BelleExternal/licenselookup/licensedetails';

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function shipTo()
    {
        return $this->belongsTo(ShipTo::class);
    }

    /** True when this row counts as a valid license proof (Site ID and/or License #). */
    public function hasProof(): bool
    {
        return filled($this->site_id) || filled($this->number);
    }

    public function isKentucky(): bool
    {
        return strtoupper((string) $this->state) === 'KY';
    }
}

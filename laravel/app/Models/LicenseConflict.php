<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 0.20.17: one license/account conflict for the office to review (approve / edit / skip). */
class LicenseConflict extends Model
{
    protected $guarded = ['id'];

    public const KINDS = [
        'no_proof' => 'License has no Site ID or number',
        'expired' => 'License expired',
        'duplicate_tax_id' => 'Customers share a State tax ID',
        'duplicate_name' => 'Customers share the same name',
        'missing_license' => 'Active customer with orders and no license',
    ];

    protected function casts(): array
    {
        return ['related_customer_ids' => 'array', 'resolved_at' => 'datetime'];
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function license()
    {
        return $this->belongsTo(CustomerLicense::class, 'license_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** The queue: pending first, then skipped (they come back at the end). */
    public function scopeQueue($q)
    {
        return $q->whereIn('status', ['pending', 'skipped'])->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")->orderBy('id');
    }
}

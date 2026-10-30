<?php

namespace App\Models;

use App\Exceptions\WorkflowException;
use Illuminate\Database\Eloquent\Model;

class CustomerGroup extends Model
{
    protected $guarded = ['id'];

    /** Built-in price groups (Michael, 2026-10-30): fixed codes every install has. Wholesale customers are not charged sales tax. */
    public const STANDARD = 'STANDARD';

    public const WHOLESALE = 'WHOLESALE';

    public const SYSTEM = [self::STANDARD => 'Standard', self::WHOLESALE => 'Wholesale'];

    protected static function booted(): void
    {
        static::updating(function (self $g) {
            if ($g->isDirty('code') && array_key_exists((string) $g->getOriginal('code'), self::SYSTEM)) {
                throw new WorkflowException("The {$g->getOriginal('code')} price group code is built in and can't be changed.");
            }
        });
        static::deleting(function (self $g) {
            if ($g->isSystem()) {
                throw new WorkflowException("The {$g->name} price group is built in and can't be deleted.");
            }
        });
    }

    public function isSystem(): bool
    {
        return array_key_exists((string) $this->code, self::SYSTEM);
    }

    public function isWholesale(): bool
    {
        return $this->code === self::WHOLESALE;
    }

    /** The built-in group by code (created if an install is missing it). */
    public static function system(string $code): self
    {
        return static::firstOrCreate(['code' => $code], ['name' => self::SYSTEM[$code]]);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}

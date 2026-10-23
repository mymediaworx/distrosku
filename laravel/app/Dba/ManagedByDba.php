<?php

namespace App\Dba;

use Illuminate\Validation\ValidationException;

/**
 * 0.20.8: rows pushed by the instance manager (global rules) are read-only locally,
 * unless the rule set marks them overridable; a local edit then sets local_override and later pushes leave the row alone.
 */
trait ManagedByDba
{
    protected static function bootManagedByDba(): void
    {
        static::updating(function ($m) {
            if (RulesApplier::$applying || ! $m->getOriginal('managed')) {
                return;
            }
            if (! $m->getOriginal('managed_overridable')) {
                throw ValidationException::withMessages(['managed' => 'This entry is managed centrally by the instance manager and is read-only here.']);
            }
            $m->local_override = true;
        });
        static::deleting(function ($m) {
            if (! RulesApplier::$applying && $m->managed) {
                throw ValidationException::withMessages(['managed' => 'This entry is managed centrally by the instance manager and cannot be deleted here.']);
            }
        });
    }

    public function isDbaLocked(): bool
    {
        return (bool) $this->managed && ! $this->managed_overridable;
    }
}

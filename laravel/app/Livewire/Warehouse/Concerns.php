<?php

namespace App\Livewire\Warehouse;

use Throwable;

/** Shared scan box state + feedback for warehouse scanner screens. */
trait Concerns
{
    public string $scan = '';

    public string $qty = '1';

    public ?string $ok = null;

    public ?string $err = null;

    protected function attempt(callable $fn): void
    {
        $this->ok = $this->err = null;
        try {
            $this->ok = $fn();
        } catch (Throwable $e) {
            $this->err = $e->getMessage();
        }
        $this->scan = '';
        $this->qty = '1';
        $this->dispatch('scanned');
    }

    protected function authorizeScan(): void
    {
        abort_unless(auth()->user()?->canModule('warehouse_scan', 'manage'), 403);
    }
}

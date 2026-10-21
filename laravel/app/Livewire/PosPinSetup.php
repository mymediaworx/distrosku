<?php

namespace App\Livewire;

use App\Exceptions\WorkflowException;
use App\Pos\PosPin;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Set your own POS PIN (needs your password login and current password). */
#[Layout('layouts.pos')]
#[Title('My POS PIN')]
class PosPinSetup extends Component
{
    public string $password = '';

    public string $pin = '';

    public string $pin_confirmation = '';

    public ?string $message = null;

    public ?string $error = null;

    public function save(): void
    {
        $this->reset('message', 'error');
        $u = auth()->user();
        if (! Hash::check($this->password, $u->password)) {
            $this->error = 'Current password is wrong.';

            return;
        }
        if ($this->pin !== $this->pin_confirmation) {
            $this->error = 'The PINs do not match.';

            return;
        }
        try {
            app(PosPin::class)->set($u, $this->pin, $u);
            $this->message = 'PIN saved. Use it on the POS lock screen.';
            $this->reset('password', 'pin', 'pin_confirmation');
        } catch (WorkflowException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.pos-pin-setup');
    }
}

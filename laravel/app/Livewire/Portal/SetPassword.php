<?php

namespace App\Livewire\Portal;

use App\Models\CustomerContact;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One-time link issued by an admin (copied, never emailed). Sets the password and signs the contact in. */
#[Layout('layouts.portal')]
#[Title('Set your password')]
class SetPassword extends Component
{
    #[Locked]
    public string $token = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function save()
    {
        $c = CustomerContact::findBySetupToken($this->token);
        abort_unless($c, 404);
        $this->validate(['password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()]]);
        $c->setPasswordFromLink($this->password);
        if (! $c->canUsePortal()) {
            return redirect()->route('portal.login')->with('portal_error', 'Password saved, but portal access is currently disabled.');
        }
        Auth::guard('portal')->login($c);
        session()->regenerate();

        return redirect()->route('portal.catalog');
    }

    public function render()
    {
        return view('livewire.portal.set-password', ['contact' => CustomerContact::findBySetupToken($this->token)]);
    }
}

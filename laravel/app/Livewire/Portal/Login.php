<?php

namespace App\Livewire\Portal;

use App\Models\Company;
use App\Models\CustomerContact;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.portal')]
#[Title('Sign in')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public function mount()
    {
        if (Auth::guard('portal')->check() && ! Company::isSuspended()) {
            return redirect()->route('portal.catalog');
        }
    }

    public function login()
    {
        $this->validate(['email' => 'required|email|max:190', 'password' => 'required|string|max:200']);
        if (Company::isSuspended()) {
            $this->addError('email', 'This account is suspended. The portal is unavailable.');

            return null;
        }
        $key = 'portal-login:'.Str::lower($this->email).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return null;
        }
        $c = CustomerContact::where('email', Str::lower($this->email))->first();
        if (! $c || ! $c->canUsePortal() || ! Auth::guard('portal')->attempt(['email' => Str::lower($this->email), 'password' => $this->password])) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'These credentials do not match an active portal login.');

            return null;
        }
        RateLimiter::clear($key);
        session()->regenerate();
        $c->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('portal.catalog');
    }

    public function render()
    {
        return view('livewire.portal.login', ['suspended' => Company::isSuspended()]);
    }
}

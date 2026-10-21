<?php

namespace App\Livewire;

use App\Exceptions\WorkflowException;
use App\Http\Middleware\PosAccess;
use App\Models\Company;
use App\Models\User;
use App\Pos\PosPin;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** POS lock screen: tap your name, enter your 4-digit PIN on the keypad. Only on enrolled terminals. */
#[Layout('layouts.pos')]
#[Title('POS locked')]
class PosLock extends Component
{
    public ?int $userId = null;

    public string $pin = '';

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(request()->cookie(PosPin::TERMINAL_COOKIE) === '1', 403, 'This device is not a POS terminal. Sign in with your password at /pos first.');
        PosAccess::lock(request());
        if (Company::isSuspended()) { // no PIN logins while the company account is suspended (BrandWorx admin uses a password)
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->view('catalog.suspended', ['company' => Company::current()], 403));
        }
    }

    public function choose(int $id): void
    {
        $this->userId = $id;
        $this->pin = '';
        $this->error = null;
    }

    public function digit(string $d): void
    {
        if (! preg_match('/^\d$/', $d) || ! $this->userId || strlen($this->pin) >= 4) {
            return;
        }
        $this->pin .= $d;
        if (strlen($this->pin) === 4) {
            $this->submit();
        }
    }

    public function back(): void
    {
        $this->pin = substr($this->pin, 0, -1);
    }

    public function submit()
    {
        $this->error = null;
        $u = $this->userId ? User::find($this->userId) : null;
        $pin = $this->pin;
        $this->pin = '';
        if (! $u || Company::isSuspended() && ! $u->hasRole('brandworx_admin')) {
            $this->error = 'Choose your name first.';

            return null;
        }
        try {
            app(PosPin::class)->attempt($u, $pin, request()->ip());
        } catch (WorkflowException $e) {
            $this->error = $e->getMessage();

            return null;
        }
        $s = session();
        $cart = $s->get('pos_cart');
        $s->regenerate();
        $s->put(PosPin::SESSION_USER, $u->id);
        $s->put(PosPin::SESSION_SEEN, time());
        if ($cart) {
            $s->put('pos_cart', $cart);
        }

        return $this->redirectRoute('pos');
    }

    public function render()
    {
        $users = User::where('active', true)->whereNotNull('pos_pin_hash')->orderBy('name')->get()->filter(fn ($u) => $u->canModule('pos', 'manage'))->values();

        return view('livewire.pos-lock', ['users' => $users, 'held' => count(session('pos_cart.cart', []))]);
    }
}

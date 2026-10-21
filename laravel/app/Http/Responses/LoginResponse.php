<?php

namespace App\Http\Responses;

use App\Filament\Pages\Modules;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as Contract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/** 0.15: after sign-in, users who chose the Modules launcher land there; everyone else keeps Filament's default. */
class LoginResponse implements Contract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $home = $request->user()?->landing_page === 'modules' ? Modules::getUrl() : filament()->getUrl();

        return redirect()->intended($home);
    }
}

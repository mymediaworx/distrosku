<x-filament-panels::page>
    @if(auth()->user()->must_change_password)
        <div data-test="must-change" style="border:1px solid #f59e0b;background:#fffbeb;padding:.75rem;border-radius:.5rem">Your password was reset by an administrator. Choose a new password to continue.</div>
    @endif
    <form wire:submit="save" style="max-width:28rem;display:grid;gap:1rem">
        @foreach(['current' => 'Current (or temporary) password', 'password' => 'New password (min '.\App\Security\PasswordAdmin::MIN.' characters)', 'password_confirmation' => 'Confirm new password'] as $f => $l)
            <label style="display:grid;gap:.25rem"><span style="font-size:.875rem;font-weight:500">{{ $l }}</span>
                <x-filament::input.wrapper :valid="! $errors->has($f)"><x-filament::input type="password" wire:model="{{ $f }}" autocomplete="{{ $f === 'current' ? 'current-password' : 'new-password' }}" /></x-filament::input.wrapper>
                @error($f)<span style="color:#dc2626;font-size:.8rem">{{ $message }}</span>@enderror</label>
        @endforeach
        <div><x-filament::button type="submit">Change password</x-filament::button></div>
    </form>
</x-filament-panels::page>

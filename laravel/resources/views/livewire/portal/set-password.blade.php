<div class="card" style="max-width:420px;margin:2rem auto">
    @if(! $contact)
        <h2>Link expired</h2><p>This set-password link is invalid, already used or expired. Ask your sales rep for a new one.</p>
    @else
        <h2>Set your password</h2>
        <p class="muted">{{ $contact->name }} · {{ $contact->email }} · {{ $contact->customer->name }}</p>
        <form wire:submit="save">
            <label for="p">New password (min. 10 characters, letters and numbers)</label><input id="p" type="password" wire:model="password" autocomplete="new-password" required>
            <label for="p2">Repeat password</label><input id="p2" type="password" wire:model="password_confirmation" autocomplete="new-password" required>
            @error('password')<p class="err">{{ $message }}</p>@enderror
            <p><button type="submit">Save and sign in</button></p>
        </form>
    @endif
</div>

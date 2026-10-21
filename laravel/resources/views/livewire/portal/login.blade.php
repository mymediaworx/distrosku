<div class="card" style="max-width:420px;margin:2rem auto">
    <h2>Sign in</h2>
    @if($suspended)<p class="err">This account is suspended. The portal is unavailable.</p>@endif
    @if(session('portal_error'))<p class="err">{{ session('portal_error') }}</p>@endif
    <form wire:submit="login">
        <label for="e">Email</label><input id="e" type="email" wire:model="email" autocomplete="username" required>
        <label for="p">Password</label><input id="p" type="password" wire:model="password" autocomplete="current-password" required>
        @error('email')<p class="err">{{ $message }}</p>@enderror
        <p><button type="submit" wire:loading.attr="disabled">Sign in</button></p>
    </form>
    <p class="muted">No password yet or forgot it? Ask your sales rep for a new set-password link.</p>
</div>

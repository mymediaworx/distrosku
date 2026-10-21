<div class="lockbox">
    <form class="card" wire:submit="save">
        <h2 style="margin-top:0">My POS PIN</h2>
        <p>Four digits, different from every other employee's PIN. It only opens the POS.</p>
        <label>Current password</label><input type="password" wire:model="password" autocomplete="current-password">
        <label>New PIN</label><input type="password" wire:model="pin" inputmode="numeric" maxlength="4" pattern="\d{4}" autocomplete="off">
        <label>Repeat PIN</label><input type="password" wire:model="pin_confirmation" inputmode="numeric" maxlength="4" pattern="\d{4}" autocomplete="off">
        @if($error)<p class="err">{{ $error }}</p>@endif
        @if($message)<p class="ok">{{ $message }}</p>@endif
        <button type="submit" style="margin-top:.75rem">Save PIN</button>
        <p><a href="{{ route('pos') }}" style="display:inline-flex;min-height:44px;align-items:center">Back to POS</a></p>
    </form>
</div>

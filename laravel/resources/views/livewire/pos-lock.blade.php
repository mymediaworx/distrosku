<div class="lockbox">
    <div class="card">
        <h2 style="margin:.2rem 0 1rem">POS locked: tap your name</h2>
        @if($held)<p class="ok">A sale in progress ({{ $held }} item{{ $held === 1 ? '' : 's' }}) is kept for the next login.</p>@endif
        <div class="who">
            @forelse($users as $u)
                <button type="button" wire:click="choose({{ $u->id }})" class="{{ $userId === $u->id ? 'on' : '' }}">{{ $u->name }}</button>
            @empty
                <p>No employee has a POS PIN yet. Sign in with your password at <a href="/admin/login">/admin</a> and set one under My PIN.</p>
            @endforelse
        </div>
    </div>
    @if($userId)
        <div class="card">
            <div class="dots" aria-label="PIN entered">@for($i = 0; $i < 4; $i++)<span class="{{ strlen($pin) > $i ? 'f' : '' }}"></span>@endfor</div>
            @if($error)<p class="err" style="text-align:center">{{ $error }}</p>@endif
            <div class="keypad">
                @foreach(['1','2','3','4','5','6','7','8','9'] as $k)<button type="button" wire:click="digit('{{ $k }}')">{{ $k }}</button>@endforeach
                <button type="button" class="alt" wire:click="choose(0)" aria-label="Cancel">✕</button>
                <button type="button" wire:click="digit('0')">0</button>
                <button type="button" class="alt" wire:click="back" aria-label="Backspace">⌫</button>
            </div>
        </div>
    @endif
    <p style="text-align:center"><a href="/admin/login" style="display:inline-flex;min-height:44px;align-items:center">Sign in with password instead</a></p>
</div>

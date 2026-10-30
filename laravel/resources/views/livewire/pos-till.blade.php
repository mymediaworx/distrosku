<div class="till" data-test="till">
    <p><a href="{{ route('pos') }}">← Back to the POS</a> · Register <strong>{{ $register }}</strong></p>
    @if($message)<p class="ok" data-test="till-msg">{{ $message }}</p>@endif
    @if($error)<p class="err" data-test="till-err">{{ $error }}</p>@endif
    @if($closedId)<p><a href="{{ route('pos.till.z', $closedId) }}" target="_blank" data-test="z-link">Print the Z-report</a></p>@endif

    @if(! $till)
        <form class="card" wire:submit="open" data-test="till-open">
            <h2 style="margin-top:0">Open the till</h2>
            <label for="float">Starting cash in the drawer (float)</label>
            <input id="float" wire:model="float" inputmode="decimal" placeholder="150.00" required>
            <p><button type="submit">Open till</button></p>
        </form>
    @else
        <div class="card" data-test="till-status">
            <h2 style="margin-top:0">Till open</h2>
            <p>Since {{ $till->opened_at->format('g:i A') }} by {{ $till->openedBy?->name }} · float ${{ number_format((float) $till->opening_float, 2) }}</p>
            <table class="tbl">
                <tr><td>Cash sales</td><td class="r">${{ number_format((float) $report['cash_sales'], 2) }}</td></tr>
                @foreach($types as $t => $label)<tr><td>{{ $label }}</td><td class="r">{{ $t === 'paid_in' ? '+' : '−' }}${{ number_format((float) $report['totals'][$t], 2) }}</td></tr>@endforeach
                <tr class="tot"><td><strong>Cash that should be in the drawer</strong></td><td class="r"><strong data-test="till-expected">${{ number_format((float) $report['expected'], 2) }}</strong></td></tr>
            </table>
            <p><a href="{{ route('pos.till.z', $till) }}" target="_blank">X-report (so far)</a></p>
        </div>

        <form class="card" wire:submit="record" data-test="till-move">
            <h3 style="margin-top:0">Cash in / out</h3>
            <div class="chips">@foreach($types as $t => $label)<button type="button" class="{{ $type === $t ? 'on' : '' }}" wire:click="$set('type', '{{ $t }}')">{{ $label }}</button>@endforeach</div>
            <label for="amt">Amount</label><input id="amt" wire:model="amount" inputmode="decimal" required>
            <label for="why">{{ $type === 'paid_out' ? 'What was it for (receipt)?' : ($type === 'drop' ? 'Note (bag / envelope #)' : 'Where the cash came from') }}</label>
            <input id="why" wire:model="reason" maxlength="255" required>
            <p class="muted">{{ $type === 'paid_out' ? 'Paid-outs are booked as an expense (6500).' : ($type === 'drop' ? 'Cash moved to the safe: it stays in Cash, only the drawer count changes.' : 'Cash added to the drawer (for example change from the safe).') }}</p>
            <p><button type="submit">Record</button></p>
        </form>

        <form class="card" wire:submit="close" data-test="till-close">
            <h3 style="margin-top:0">Close the till</h3>
            <div class="denoms">
                @foreach(\App\Livewire\PosTill::BILLS as $b)<label>${{ $b }} ×<input wire:model.live.debounce.300ms="count.{{ $b }}" inputmode="numeric" aria-label="Number of ${{ $b }} bills"></label>@endforeach
                <label>Coins $<input wire:model.live.debounce.300ms="count.coins" inputmode="decimal" aria-label="Coins total"></label>
            </div>
            <p class="total">Counted: <span data-test="till-counted">${{ number_format((float) $this->counted, 2) }}</span></p>
            <label for="cn">Note (optional)</label><input id="cn" wire:model="closeNotes" maxlength="255">
            <p><button type="submit" class="pay" wire:confirm="Close the till with ${{ number_format((float) $this->counted, 2) }} counted?">Close till</button></p>
        </form>
    @endif
</div>

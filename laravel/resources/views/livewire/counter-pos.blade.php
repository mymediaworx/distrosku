<div x-data="{ idle: null, reset() { clearTimeout(this.idle); this.last = Date.now(); this.idle = setTimeout(() => $wire.lock(), {{ \App\Pos\PosPin::IDLE_SECONDS * 1000 }}); }, last: Date.now() }"
     x-init="reset(); ['pointerdown','keydown','touchstart'].forEach(e => window.addEventListener(e, () => reset(), {passive: true}));
             document.addEventListener('visibilitychange', () => { if (!document.hidden && Date.now() - last > {{ \App\Pos\PosPin::IDLE_SECONDS * 1000 }}) $wire.lock(); });">
    <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;padding:.5rem 1rem">
        <span>Cashier: <strong data-test="operator">{{ $operator }}</strong></span>
        <span style="display:flex;gap:.5rem">
            @unless($pinSession)<a href="{{ route('pos.pin') }}" style="min-height:44px;display:inline-flex;align-items:center;padding:0 .75rem">My PIN</a>@endunless
            <button type="button" wire:click="lock" style="width:auto;min-width:150px">Lock / switch</button>
        </span>
    </div>
    <div class="pos">
        <div>
            <div class="card">
                <form class="scan" wire:submit="add">
                    <input wire:model="scan" placeholder="Scan UPC or type SKU / name" autofocus inputmode="search" autocomplete="off" aria-label="Search or scan">
                    <button type="submit">Add</button>
                </form>
                @if($error)<p class="err">{{ $error }}</p>@endif
                @if($message)<p class="ok">{{ $message }}</p>@endif
            </div>
            <div class="card">
                @forelse($cart as $i => $line)
                    <div class="line" wire:key="l{{ $line['product_id'] }}">
                        <div><strong>{{ $line['sku'] }}</strong><br><small>{{ $line['name'] }}</small>@if(!empty($line['source']))<br><small class="src">{{ \App\Pricing\PricingEngine::sourceLabel($line['source']) }}</small>@endif</div>
                        <div class="step">
                            <button type="button" wire:click="dec({{ $i }})" aria-label="One less">−</button>
                            <input type="number" step="1" min="1" inputmode="numeric" wire:model.blur="cart.{{ $i }}.qty" aria-label="Qty">
                            <button type="button" wire:click="inc({{ $i }})" aria-label="One more">+</button>
                        </div>
                        <input class="price" type="number" step="0.01" min="0" inputmode="decimal" wire:model.blur="cart.{{ $i }}.price" aria-label="Price">
                        <button type="button" class="x" wire:click="remove({{ $i }})" aria-label="Remove">×</button>
                        @foreach($line['reasons'] as $r)<div class="blk">⛔ {{ $r }}</div>@endforeach
                    </div>
                @empty
                    <p>Cart is empty. Scan or search above.</p>
                @endforelse
            </div>
        </div>
        <div class="side">
            <div class="card row" style="grid-template-columns:1fr">
                <div><label>Customer</label>
                    <select wire:model.live="customerId">@foreach($customers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                    @if(! is_null($this->customerBalance))<small data-test="customer-balance">Account balance: {{ number_format((float) $this->customerBalance, 2) }}{{ (float) $this->customerBalance < 0 ? ' (credit)' : '' }}</small>@endif</div>
                <div><label>Ship-to</label>
                    <select wire:model.live="shipToId">@foreach($shipTos as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->state }})</option>@endforeach</select></div>
                <div><label>Warehouse</label>
                    <select wire:model="warehouseId">@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
            </div>
            <div class="card">
                <p class="total">${{ number_format((float) $this->total, 2) }}</p>
                <div class="tenders">
                    @foreach(['cash' => 'Cash', 'check' => 'Check', 'card' => 'Card (manual)', 'account' => 'Charge to account'] as $k => $v)
                        <button type="button" wire:click="$set('tender', '{{ $k }}')" class="{{ $tender === $k ? 'on' : '' }}">{{ $v }}</button>
                    @endforeach
                </div>
                @if($tender === 'cash')
                    <div x-data="{ add(d) { let v = ($wire.tendered || '') + d; if (/^\d*(\.\d{0,2})?$/.test(v)) $wire.tendered = v; } }">
                        <label>Cash tendered</label>
                        <input type="text" inputmode="decimal" wire:model.live.debounce.300ms="tendered" placeholder="0.00" aria-label="Cash tendered" style="font-size:1.5rem;text-align:right">
                        <div class="keypad">
                            @foreach(['7','8','9','4','5','6','1','2','3','.','0'] as $k)<button type="button" x-on:click="add('{{ $k }}')">{{ $k }}</button>@endforeach
                            <button type="button" class="alt" x-on:click="$wire.tendered = ($wire.tendered || '').slice(0, -1)" aria-label="Backspace">⌫</button>
                        </div>
                        @if(! is_null($this->changeDue))<p class="total" style="font-size:1.4rem">Change due: ${{ $this->changeDue }}</p>@endif
                    </div>
                @endif
                @if($sigAcks)<div style="margin:.6rem 0">@include('partials.signature-pad', ['acks' => $sigAcks])</div>@endif
                <button class="pay" wire:click="checkout" @disabled($this->blocked || empty($cart)) wire:loading.attr="disabled">Complete sale</button>
            </div>
        </div>
    </div>
</div>

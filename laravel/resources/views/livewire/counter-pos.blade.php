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
                <div class="posbar">
                    <button type="button" class="big" wire:click="openBrowse" data-test="browse">Browse catalog</button>
                    <button type="button" wire:click="$set('holding', true)" @disabled(empty($cart)) data-test="hold">Hold</button>
                    <button type="button" wire:click="$set('recalling', true)" data-test="recall">Recall</button>
                </div>
                @if($error)<p class="err">{{ $error }}</p>@endif
                @if($message)<p class="ok">{{ $message }}</p>@endif
                @foreach($recallNotes as $n)<p class="warn" data-test="recall-note">⚠ {{ $n }}</p>@endforeach
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
            <div class="card"><label>Notes</label><textarea wire:model.blur="notes" rows="2" aria-label="Notes" style="width:100%"></textarea></div>
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

    @if($browsing)
        <div class="ovl" data-test="browser">
            <div class="ovl-in">
                <div style="display:flex;gap:.5rem;align-items:center">
                    <input wire:model.live.debounce.250ms="browseFilter" placeholder="Filter by name, SKU, brand" inputmode="search" aria-label="Filter" style="flex:1">
                    <button type="button" wire:click="closeBrowse" style="width:auto;min-width:110px">Done</button>
                </div>
                <div class="chips">
                    <button type="button" wire:click="browseBy(null, null)" class="{{ ! $browseCategory && ! $browseBrand ? 'on' : '' }}">All</button>
                    @foreach($this->browseFacets['categories'] as $cat)<button type="button" wire:click="browseBy(@js($cat), null)" class="{{ $browseCategory === $cat ? 'on' : '' }}">{{ $cat }}</button>@endforeach
                </div>
                @if($this->browseFacets['brands'])
                    <div class="chips sm">@foreach($this->browseFacets['brands'] as $br)<button type="button" wire:click="browseBy(@js($browseCategory), @js($br))" class="{{ $browseBrand === $br ? 'on' : '' }}">{{ $br }}</button>@endforeach</div>
                @endif
                <div class="tiles">
                    @forelse($this->browseTiles as $t)
                        <button type="button" class="tile" wire:key="t{{ $t['id'] }}" wire:click="pick({{ $t['id'] }})" data-test="tile">
                            <strong>{{ $t['name'] }}</strong><small>{{ $t['sku'] }} · {{ $t['unit'] }}</small>
                            <span class="tp">${{ number_format((float) $t['price'], 2) }}</span><small class="{{ (float) $t['stock'] > 0 ? '' : 'out' }}">Stock: {{ $t['stock'] }}</small>
                        </button>
                    @empty
                        <p>No products match.</p>
                    @endforelse
                </div>
            </div>
            @if($pickProductId)
                <div class="ovl" style="background:rgba(0,0,0,.35)">
                    <div class="ovl-in" style="max-width:360px">
                        <p><strong>Quantity</strong></p>
                        <p class="total" data-test="pick-qty">{{ $pickQty === '' ? '0' : $pickQty }}</p>
                        <div class="keypad">
                            @foreach(['7','8','9','4','5','6','1','2','3'] as $k)<button type="button" wire:click="pickKey('{{ $k }}')">{{ $k }}</button>@endforeach
                            <button type="button" class="alt" wire:click="pickKey('clear')">C</button>
                            <button type="button" wire:click="pickKey('0')">0</button>
                            <button type="button" class="alt" wire:click="pickKey('back')" aria-label="Backspace">⌫</button>
                        </div>
                        <div style="display:flex;gap:.5rem"><button type="button" class="alt" wire:click="$set('pickProductId', null)" style="background:#cbd5e1;color:#111">Cancel</button>
                            <button type="button" class="pay" wire:click="addPicked" style="margin-top:0">Add to sale</button></div>
                    </div>
                </div>
            @endif
        </div>
    @endif
    @if($holding)
        <div class="ovl"><div class="ovl-in" style="max-width:460px">
            <p><strong>Hold this sale</strong></p>
            <p><small>Saves the cart, customer, prices and notes. No stock is reserved. Expires after {{ \App\Models\PosHeldSale::days() }} days.</small></p>
            <input wire:model="holdLabel" placeholder="Label (optional), e.g. Joe - pickup 3pm" maxlength="80" aria-label="Hold label">
            <div style="display:flex;gap:.5rem;margin-top:.5rem"><button type="button" wire:click="$set('holding', false)" style="background:#cbd5e1;color:#111">Cancel</button>
                <button type="button" class="pay" wire:click="hold" style="margin-top:0" data-test="hold-save">Hold sale</button></div>
        </div></div>
    @endif
    @if($recalling)
        <div class="ovl"><div class="ovl-in">
            <div style="display:flex;justify-content:space-between;align-items:center"><p><strong>Held sales</strong></p>
                <button type="button" wire:click="$set('recalling', false)" style="width:auto;min-width:110px">Close</button></div>
            @forelse($this->heldSales as $h)
                <div class="line" wire:key="h{{ $h->id }}" style="grid-template-columns:1fr auto auto">
                    <div><strong>{{ $h->label ?: 'Held sale #'.$h->id }}</strong><br>
                        <small>{{ $h->customer?->name }} · {{ count($h->cart ?? []) }} item(s) · ${{ number_format((float) $h->total, 2) }}</small><br>
                        <small>{{ $h->created_at->timezone(config('app.timezone'))->format('M j g:i A') }} by {{ $h->user?->name ?? '—' }}@if($h->register) · {{ $h->register }}@endif · expires {{ $h->expires_at?->timezone(config('app.timezone'))->format('M j') }}</small></div>
                    <button type="button" wire:click="recall({{ $h->id }})" style="width:auto;min-width:110px" data-test="recall-one">Recall</button>
                    @if($canDeleteHeld)<button type="button" class="x" wire:click="deleteHeld({{ $h->id }})" wire:confirm="Delete this held sale?" aria-label="Delete">×</button>@endif
                </div>
            @empty
                <p>No held sales at this location.</p>
            @endforelse
        </div></div>
    @endif
</div>

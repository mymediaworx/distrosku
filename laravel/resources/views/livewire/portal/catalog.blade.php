<div>
    <div class="card">
        <p class="muted">{{ $contact->customer->name }} · {{ $contact->name }}</p>
        <label for="ff">Fulfillment</label>
        <select id="ff" wire:model.live="fulfillment" data-test="fulfillment"><option value="pickup">Counter pickup</option><option value="delivery">Delivery</option></select>
        @if($delivery)
            @if($shipTos->count() > 1)
                <label for="st">Ship to</label>
                <select id="st" wire:model.live="shipToId">@foreach($shipTos as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->state }})</option>@endforeach</select>
            @elseif($shipTo)
                <p>Ship to: <strong>{{ $shipTo->name }}</strong> ({{ $shipTo->state }})</p>
            @else
                <p class="err">No ship-to address on file for delivery. Choose Counter pickup or contact us.</p>
            @endif
        @endif
        <div class="inline" style="margin-top:.5rem">
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Search name, SKU or UPC" enterkeyhint="search" aria-label="Search products">
            <select wire:model.live="departmentId" aria-label="Department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
        </div>
        <button type="button" class="scanbtn" x-data x-on:click="$dispatch('portal-scan-open')" data-test="scan-open">Scan barcodes with camera</button>
    </div>

    {{-- #37: camera scanning. Chrome on Android uses the built-in BarcodeDetector; other browsers (iPhone Safari) load
         the bundled ZXing reader on first use. wire:ignore keeps the live video out of Livewire re-renders. --}}
    <div wire:ignore x-data="portalScanner(@js(asset('js/vendor/zxing-browser-0.2.1.min.js')))"
         x-on:portal-scan-open.window="start()" x-on:portal-scan.window="result($event.detail)" x-on:keydown.escape.window="stop()">
        <div class="scanner" x-show="open" x-cloak role="dialog" aria-modal="true" aria-label="Scan barcodes" data-test="scanner">
            <div class="scanner-top"><strong>Scan to add to cart</strong><button type="button" class="scan-done" x-on:click="stop()">Done</button></div>
            <div class="scanner-view"><video x-ref="v" playsinline muted></video><div class="scanner-aim" aria-hidden="true"></div></div>
            <p class="scanner-status" x-text="status" aria-live="polite"></p>
            <form class="inline" x-on:submit.prevent="submitTyped()" style="margin:.25rem 0 .5rem">
                <input type="text" x-model="typed" inputmode="numeric" placeholder="Or type the barcode" aria-label="Type a barcode">
                <button type="submit" style="flex:0 0 auto;width:auto">Add</button>
            </form>
            <ul class="scanner-list">
                <template x-for="(it, i) in items" :key="i"><li :class="it.ok ? 'ok' : 'err'" x-text="(it.ok ? '✓ ' : '✗ ') + it.text"></li></template>
            </ul>
        </div>
    </div>
    @if($error)<div class="card err">{{ $error }}</div>@endif
    @if($message)<div class="card ok">{{ $message }}</div>@endif

    @if($showCart)
        <div class="card" id="cart">
            <h3>Cart</h3>
            @forelse($lines as $l)
                <div style="border-bottom:1px solid #eee;padding:.5rem 0" wire:key="c{{ $l['p']->id }}">
                    <strong>{{ $l['p']->sku }}</strong> {{ $l['p']->name }}
                    <div class="inline" style="align-items:center">
                        <input type="number" min="0" step="1" value="{{ (int) $l['qty'] }}" wire:change="setQty({{ $l['p']->id }}, $event.target.value)" aria-label="Qty" style="max-width:90px">
                        <span>× ${{ number_format((float) $l['price'], 2) }} = <strong>${{ number_format((float) $l['total'], 2) }}</strong></span>
                        <button class="sec" type="button" wire:click="remove({{ $l['p']->id }})">Remove</button>
                    </div>
                    @foreach($l['reasons'] as $r)<div class="na">⛔ {{ $r }}</div>@endforeach
                </div>
            @empty
                <p>Your cart is empty.</p>
            @endforelse
            @if($lines)
                <p class="price" style="text-align:right">Total ${{ number_format((float) $total, 2) }}</p>
                <label for="n">Note / PO number (optional)</label><textarea id="n" wire:model="note" rows="2" maxlength="500"></textarea>
                @if($blocked)<p class="err">Remove unavailable items before submitting.</p>@endif
                <p><button type="button" wire:click="submit" @disabled($blocked || ! $location) wire:loading.attr="disabled">Submit order</button></p>
            @endif
        </div>
    @endif

    {{-- Segmented control: one row, the current view is highlighted (it used to be two stacked bars with no active state). --}}
    <nav class="tabs" role="tablist" aria-label="Catalog view">
        <button type="button" role="tab" class="{{ $view === 'again' ? '' : 'on' }}" aria-selected="{{ $view === 'again' ? 'false' : 'true' }}" wire:click="$set('view', 'shop')" data-test="tab-shop">Shop</button>
        <button type="button" role="tab" class="{{ $view === 'again' ? 'on' : '' }}" aria-selected="{{ $view === 'again' ? 'true' : 'false' }}" wire:click="$set('view', 'again')" data-test="tab-again">Buy again</button>
    </nav>

    @if($view === 'again')
        <div class="card" data-test="buy-again">
            <h3>Buy again</h3>
            @forelse($again as $a)
                @php($p = $a['product'])
                <div style="border-bottom:1px solid #eee;padding:.5rem 0" wire:key="a{{ $p->id }}">
                    <strong>{{ $p->name }}</strong> <span class="muted">{{ $p->sku }}</span><br>
                    <span class="muted">Last ordered {{ \Illuminate\Support\Carbon::parse($a['last_date'])->format('M j, Y') }} · qty {{ (float) $a['last_qty'] + 0 }} ({{ $a['last_order'] }})</span><br>
                    <span class="price">${{ number_format((float) $a['price'], 2) }}</span> <span class="muted">/ {{ $p->unit }}</span>
                    @if($a['reasons'])
                        @foreach($a['reasons'] as $why)<div class="na">Unavailable: {{ $why }}</div>@endforeach
                    @else
                        <div class="inline" style="margin-top:.4rem;align-items:center">
                            <input type="number" min="1" step="1" wire:model="againQty.{{ $p->id }}" aria-label="Qty" style="max-width:90px">
                            <button type="button" wire:click="addAgain({{ $p->id }})">Add to cart</button>
                            @if(isset($cart[$p->id]))<span class="badge">{{ (int) $cart[$p->id] }} in cart</span>@endif
                        </div>
                    @endif
                </div>
            @empty
                <p>No past purchases yet.</p>
            @endforelse
        </div>
        @if($pastOrders->isNotEmpty())
            <div class="card"><h3>Past orders</h3>
                @foreach($pastOrders as $o)
                    <div class="inline" style="justify-content:space-between;align-items:center;border-bottom:1px solid #eee;padding:.4rem 0" wire:key="o{{ $o->id }}">
                        <span>{{ $o->number }} · {{ $o->order_date?->format('M j, Y') }} · ${{ number_format((float) $o->total, 2) }}</span>
                        <button type="button" class="sec" wire:click="reorderOrder({{ $o->id }})">Reorder all from {{ $o->number }}</button>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        @foreach(['promotions' => 'Promotions', 'new' => 'New products', 'featured' => 'Featured'] as $key => $title)
            @if(! empty($sections[$key]) && $sections[$key]->isNotEmpty())
                <h3 data-test="section-{{ $key }}">{{ $title }}</h3>
                <div class="grid" style="margin-bottom:1rem">
                    @foreach($sections[$key] as $r)
                        @include('livewire.portal.partials.item', ['r' => $r, 'k' => $key])
                    @endforeach
                </div>
            @endif
        @endforeach

        @if($groups->isNotEmpty())
            <div class="inline" style="justify-content:flex-end;margin:.25rem 0">
                <button type="button" class="sec" onclick="document.querySelectorAll('details.cat').forEach(d => d.open = true)">Expand all</button>
                <button type="button" class="sec" onclick="document.querySelectorAll('details.cat').forEach(d => d.open = false)">Collapse all</button>
            </div>
        @endif
        @forelse($groups as $name => $items)
            <details class="cat" wire:ignore.self wire:key="cat-{{ md5($name.'|'.$q.'|'.$departmentId) }}" @if($searching || $groups->count() === 1) open @endif data-test="category">
                <summary><strong>{{ $name }}</strong> <span class="muted">({{ $items->count() }})</span></summary>
                <div class="grid" style="margin:.5rem 0 1rem">
                    @foreach($items as $r)
                        @include('livewire.portal.partials.item', ['r' => $r, 'k' => 'c'])
                    @endforeach
                </div>
            </details>
        @empty
            <p>No products found.</p>
        @endforelse
    @endif

    @push('scripts')
    <script>
    // #37 portal camera scanning. Each code goes to the server (same lookup and availability rules as typing it).
    document.addEventListener('alpine:init', () => Alpine.data('portalScanner', (zxingUrl) => ({
        open: false, status: '', items: [], typed: '', stream: null, timer: null, controls: null, last: '', lastAt: 0,
        async start() {
            this.open = true; this.status = 'Starting camera…'; await this.$nextTick();
            const v = this.$refs.v;
            try {
                const native = 'BarcodeDetector' in window && (await BarcodeDetector.getSupportedFormats()).includes('ean_13');
                if (native) {
                    this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
                    v.srcObject = this.stream; await v.play();
                    const det = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'itf', 'code_128'] });
                    const tick = async () => {
                        if (!this.open) return;
                        try { const r = await det.detect(v); if (r.length) this.found(r[0].rawValue); } catch (e) {}
                        this.timer = setTimeout(tick, 250);
                    };
                    tick();
                } else {
                    await this.load(zxingUrl);
                    const reader = new ZXingBrowser.BrowserMultiFormatReader();
                    this.controls = await reader.decodeFromConstraints({ video: { facingMode: 'environment' } }, v, (res) => { if (res) this.found(res.getText()); });
                }
                this.status = 'Point the camera at a barcode.';
            } catch (e) {
                this.status = e && e.name === 'NotAllowedError'
                    ? 'Camera access was blocked. Allow the camera for this site, or type the barcode below.'
                    : 'The camera isn\'t available here. Type the barcode below.';
            }
        },
        load(url) {
            return window.ZXingBrowser ? Promise.resolve() : new Promise((ok, fail) => {
                const s = document.createElement('script'); s.src = url; s.onload = ok; s.onerror = fail; document.head.appendChild(s);
            });
        },
        found(code) {
            const now = Date.now(), seen = this.lastAt;
            this.lastAt = now;
            // A box held in view is read many times a second: count it once, and again only after it has left
            // the view for 1.5 s (so scanning two identical items still works).
            if (code === this.last && now - seen < 1500) return;
            this.last = code; this.status = 'Checking ' + code + '…';
            this.$wire.scan(code);
        },
        submitTyped() { // typed codes always count, and don't disturb the camera's duplicate check
            const c = this.typed.trim(); if (!c) return;
            this.typed = ''; this.status = 'Checking ' + c + '…'; this.$wire.scan(c);
        },
        result(d) {
            if (!this.open) return;
            this.items.unshift({ ok: d.ok, text: d.text }); this.items = this.items.slice(0, 10);
            this.status = d.ok ? 'Added. Scan the next item.' : 'Not added. Scan another item.';
            if (navigator.vibrate) navigator.vibrate(d.ok ? 60 : [80, 60, 80]);
            try {
                const c = new (window.AudioContext || window.webkitAudioContext)(), o = c.createOscillator();
                o.frequency.value = d.ok ? 880 : 220; o.connect(c.destination); o.start();
                setTimeout(() => { o.stop(); c.close(); }, d.ok ? 90 : 250);
            } catch (e) {}
        },
        stop() {
            this.open = false; clearTimeout(this.timer);
            if (this.controls) { this.controls.stop(); this.controls = null; }
            if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
        },
    })));
    </script>
    @endpush

    <div class="cartbar" style="margin-top:1rem" data-test="cartbar"><span>{{ count($cart) }} item(s) · ${{ number_format((float) $total, 2) }}</span>
        <button type="button" wire:click="$toggle('showCart')">{{ $showCart ? 'Hide cart' : 'View cart' }}</button></div>
</div>

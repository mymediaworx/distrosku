<div>
    <div class="card">
        <label>Van / truck</label>
        <select wire:model.live="vanId" data-test="van">@forelse($vans as $v)<option value="{{ $v->id }}">{{ $v->name }}</option>@empty<option value="">No van warehouse set up</option>@endforelse</select>
        <livewire:customer-picker wire:model.live="customerId" label="Customer" wire:key="van-customer-picker" />
        @if($customerId)<label>Fulfillment</label>
            <select wire:model.live="fulfillment" data-test="fulfillment"><option value="pickup">Counter pickup</option><option value="delivery">Delivery</option></select>
            @if($fulfillment === 'delivery')<label>Ship-to</label>
                @if($shipTos->isEmpty())<p class="err" data-test="no-ship-to">No ship-to for delivery: add one on the customer page in the office app, or switch to Counter pickup.</p>
                @else<select wire:model.live="shipToId" data-test="ship-to">@foreach($shipTos as $s)<option value="{{ $s->id }}">{{ $s->name }}{{ $s->is_default ? ' (default)' : '' }} · {{ $s->city }}</option>@endforeach</select>@endif
            @endif
        @endif
    </div>
    <div class="card"><form wire:submit="add" style="display:flex;gap:.5rem"><input wire:model="scan" placeholder="Scan or type UPC / SKU / name" autofocus><button style="width:auto">Add</button></form>
        <table class="tbl" style="margin-top:.5rem">
            @foreach($cart as $i => $l)<tr><td>{{ $l['name'] }}<br><span class="muted">{{ $l['sku'] }} · ${{ number_format((float) $l['price'], 2) }} · van has {{ (float) ($stock[$l['product_id']] ?? 0) }}</span>@if(! empty($l['lots']))<br><label data-test="line-lot">Lot <select wire:model.change="cart.{{ $i }}.lot_id" aria-label="Lot for {{ $l['sku'] }}">@foreach($l['lots'] as $lid => $lbl)<option value="{{ $lid }}">{{ $lbl }}</option>@endforeach</select></label>@endif</td>
                <td style="width:80px"><input inputmode="decimal" wire:model.blur="cart.{{ $i }}.qty"></td><td style="width:48px"><button class="bad" wire:click="remove({{ $i }})" aria-label="Remove">×</button></td></tr>@endforeach
        </table>
        <p style="text-align:right;font-size:1.4rem;font-weight:700" data-test="total">${{ number_format((float) $this->total, 2) }}</p>
        <label>Tender</label>
        <div class="grid">@foreach(['cash' => 'Cash', 'check' => 'Check', 'account' => 'Charge account'] as $k => $v)<button type="button" wire:click="$set('tender','{{ $k }}')" class="{{ $tender === $k ? '' : 'btn2' }}">{{ $v }}</button>@endforeach</div>
    </div>
    @if($needsSig)<div class="card">@include('partials.signature-pad', ['acks' => $acks])</div>@endif
    @if($err)<div class="card err" data-test="err">{{ $err }}</div>@endif
    @if($ok)<div class="card ok" data-test="ok">{{ $ok }}</div>@endif
    <button wire:click="sell" wire:loading.attr="disabled" @disabled(! $cart || ! $customerId) data-test="sell">Complete sale</button>
    {{-- #26: keep offline van sales ready on this phone (page copy + catalog every 10 minutes); show the switch when signal drops --}}
    <div wire:ignore>
        <div id="van-offline-bar" data-test="offline-banner" hidden style="position:fixed;left:0;right:0;bottom:0;z-index:50;background:#b45309;color:#fff;padding:.8rem 1rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem">
            <strong>No signal.</strong><a href="{{ route('driver.van.offline', ['van' => $vanId]) }}" style="color:#fff;font-weight:700;min-height:44px;display:inline-flex;align-items:center">Switch to offline van sale</a>
        </div>
        <script>
        (() => {
            const bar = document.getElementById('van-offline-bar');
            const show = () => { bar.hidden = navigator.onLine; };
            window.addEventListener('online', show); window.addEventListener('offline', show); show();
            if ('serviceWorker' in navigator) navigator.serviceWorker.register('/pos-sw.js', { scope: '/driver' }).catch(() => {});
            const save = async () => {
                if (!navigator.onLine || !window.indexedDB) return;
                try {
                    const r = await fetch(@js(route('driver.van.offline.catalog', [], false)), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (!r.ok) return;
                    const cat = await r.json();
                    const open = indexedDB.open('distrosku-van', 1);
                    open.onupgradeneeded = () => { open.result.createObjectStore('kv'); open.result.createObjectStore('queue', { keyPath: 'uuid' }); };
                    open.onsuccess = () => { try { open.result.transaction('kv', 'readwrite').objectStore('kv').put(cat, 'catalog'); } catch (e) {} };
                } catch (e) {}
            };
            setTimeout(save, 5000); setInterval(save, 10 * 60 * 1000);
            // the cached offline page opens for the van picked here
            fetch(@js(route('driver.van.offline', ['van' => $vanId], false)), { credentials: 'same-origin' }).then((r) => r.ok && caches && caches.open('distrosku-van-v1').then((c) => c.put('/driver/van/offline', r))).catch(() => {});
        })();
        </script>
    </div>
</div>

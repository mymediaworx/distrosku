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
            <input type="search" wire:model.live.debounce.400ms="q" placeholder="Search name, SKU or UPC" enterkeyhint="search">
            <select wire:model.live="departmentId" aria-label="Department"><option value="">All departments</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
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

    <div class="grid">
        @forelse($rows as $r)
            @php($p = $r['product'])
            <div class="item" wire:key="p{{ $p->id }}">
                @if($p->imageUrl())<img class="thumb" src="{{ $p->imageUrl() }}" alt="" loading="lazy">@else<div class="thumb"></div>@endif
                <div>
                    <strong>{{ $p->name }}</strong><br><span class="muted">{{ $p->sku }}@if($p->upc) · UPC {{ $p->upc }}@endif</span><br>
                    <span class="price">${{ number_format((float) $r['price'], 2) }}</span> <span class="muted">/ {{ $p->unit }}</span>
                    @if($r['reasons'])
                        @foreach($r['reasons'] as $why)<div class="na" data-test="unavailable">Unavailable: {{ $why }}</div>@endforeach
                    @else
                        <div class="inline" style="margin-top:.4rem"><button type="button" wire:click="add({{ $p->id }})">Add</button>
                            @if(isset($cart[$p->id]))<span class="badge">{{ (int) $cart[$p->id] }} in cart</span>@endif</div>
                    @endif
                </div>
            </div>
        @empty
            <p>No products found.</p>
        @endforelse
    </div>

    <div class="cartbar" style="margin-top:1rem"><span>{{ count($cart) }} item(s) · ${{ number_format((float) $total, 2) }}</span>
        <button type="button" wire:click="$toggle('showCart')">{{ $showCart ? 'Hide cart' : 'View cart' }}</button></div>
</div>

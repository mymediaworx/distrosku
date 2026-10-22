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

    <nav class="tabs" style="margin:.5rem 0">
        <button type="button" class="{{ $view === 'again' ? 'sec' : '' }}" wire:click="$set('view', 'shop')" data-test="tab-shop">Shop</button>
        <button type="button" class="{{ $view === 'again' ? '' : 'sec' }}" wire:click="$set('view', 'again')" data-test="tab-again">Buy again</button>
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

    <div class="cartbar" style="margin-top:1rem"><span>{{ count($cart) }} item(s) · ${{ number_format((float) $total, 2) }}</span>
        <button type="button" wire:click="$toggle('showCart')">{{ $showCart ? 'Hide cart' : 'View cart' }}</button></div>
</div>

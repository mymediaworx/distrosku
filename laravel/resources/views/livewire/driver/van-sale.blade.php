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
</div>

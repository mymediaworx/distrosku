<div>
    <div class="card"><h3>Pack order {{ $so->number }} · {{ $so->customer->name }} @if($list)<span class="pill">{{ $list->status }}</span>@endif</h3>
        @if($list && $list->status === 'picked')
            @include('livewire.warehouse.scanbox', ['action' => 'add'])
            <div class="row" style="margin-top:.5rem"><input wire:model="weight" placeholder="Carton weight (lb, optional)" inputmode="decimal">
                <select wire:model="carrierId" aria-label="Carrier"><option value="">Carrier (optional)</option>@foreach(\App\Models\Carrier::where('active', true)->orderBy('name')->get() as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
                <input wire:model="tracking" placeholder="Tracking # (optional)" data-test="tracking"><button class="btn2" wire:click="closeCarton">Close carton</button><button wire:click="complete">Complete packing</button></div>
        @else
            @if($err)<p class="err">{{ $err }}</p>@endif @if($ok)<p class="ok" data-test="ok">{{ $ok }}</p>@endif
        @endif
        @if($list)<p><a href="{{ route('wh.print.pack', $so) }}" target="_blank">Print packing list</a></p>@endif
    </div>
    <div class="card"><table class="tbl"><tr><th>SKU</th><th>Item</th><th>Picked</th><th>Packed</th></tr>
        @foreach($lines as $l)<tr class="{{ (float) $l->qty_packed >= (float) $l->qty_picked && (float) $l->qty_picked > 0 ? 'done' : '' }}"><td>{{ $l->product->sku }}</td><td>{{ $l->product->name }}</td><td>{{ (float) $l->qty_picked }}</td><td>{{ (float) $l->qty_packed }}</td></tr>@endforeach
    </table></div>
    @foreach($packages as $p)
        <div class="card"><strong>Carton {{ $p->seq }}</strong> <span class="pill">{{ $p->status }}</span> @if($p->weight_lb) · {{ $p->weight_lb }} lb @endif
            @if($p->status === 'closed') · {{ $p->carrier?->name ?? 'no carrier' }} @if($p->tracking_number)@if($p->trackingUrl())<a href="{{ $p->trackingUrl() }}" target="_blank" rel="noopener">{{ $p->tracking_number }}</a>@else{{ $p->tracking_number }}@endif @endif · <a href="{{ route('wh.print.label', $p) }}" target="_blank">Label</a>@endif
            <table class="tbl">@foreach($p->lines as $pl)<tr><td>{{ $pl->pickLine->product->sku }}</td><td>{{ $pl->pickLine->product->name }}</td><td>{{ (float) $pl->qty }}</td></tr>@endforeach</table></div>
    @endforeach
</div>

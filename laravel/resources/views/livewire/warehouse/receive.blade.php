<div>
    <div class="card"><h3>Receive PO {{ $po->number }} · {{ $po->vendor->name }} <span class="pill">{{ $po->status }}</span></h3>
        <p data-test="inbound">Expected {{ $po->expected_at ? $po->expected_at->format('D M j') : 'not set' }}@if($c = \App\Purchasing\InboundTracking::carrierLabel($po)) · {{ $c }}@endif @if($t = \App\Purchasing\InboundTracking::html($po)) · {!! $t !!}@endif</p>
        @if($session && $session->status === 'open')
            <div class="row"><label>Lot <input wire:model="lot" placeholder="lot # (lot-tracked items)" data-test="lot"></label>
                <label>Expires <input type="date" wire:model="expires" data-test="expires"></label>
                @php($pct = \App\Services\PurchaseOrderService::allowancePct($po))@if((float) $pct > 0)<span class="pill">Over-receipt allowance {{ (float) $pct }}%</span>@endif</div>
            @include('livewire.warehouse.scanbox', ['action' => 'add'])
        @else
            @if($ok)<p class="ok" data-test="ok">{{ $ok }}</p>@endif @if($err)<p class="err">{{ $err }}</p>@endif
            <p>No open receiving session.</p>
        @endif
    </div>
    <div class="card"><div class="tblwrap"><table class="tbl"><tr><th>SKU</th><th>Item</th><th>Ordered</th><th>Received before</th><th>Scanned</th><th>Left</th><th>Lots</th></tr>
        @foreach($rows as $r)<tr class="{{ (float) $r['remaining'] <= 0 ? 'done' : '' }}"><td>{{ $r['line']->product->sku }}</td><td>{{ $r['line']->product->name }}</td><td>{{ (float) $r['ordered'] }}</td><td>{{ (float) $r['received'] }}</td><td>{{ (float) $r['scanned'] }}</td><td>{{ (float) $r['remaining'] }}</td><td>{{ $r['line']->product->track_lots ? ($session?->scans->where('purchase_order_line_id', $r['line']->id)->whereNotNull('lot_number')->pluck('lot_number')->unique()->implode(', ') ?: 'lot required') : '' }}</td></tr>@endforeach
    </table></div>
    @if($session && $session->status === 'open')
        <div class="row" style="margin-top:.6rem"><button class="btn2" wire:click="undo">Undo last scan</button><button wire:click="post" wire:confirm="Post the scanned quantities to stock and the GL?">Post receipt</button></div>
    @endif
    </div>
</div>

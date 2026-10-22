<div>
    <div class="card"><h3>Pick {{ $list?->number }} · order {{ $so->number }} · {{ $so->customer->name }} @if($list)<span class="pill">{{ $list->status }}</span>@endif</h3>
        @if($list && $list->status === 'open')
            <div class="row"><label>Lot <input wire:model="lot" placeholder="lot # (lot-tracked items)" data-test="lot"></label></div>
            @include('livewire.warehouse.scanbox', ['action' => 'add'])
        @else
            @if($err)<p class="err">{{ $err }}</p>@endif @if($ok)<p class="ok" data-test="ok">{{ $ok }}</p>@endif
        @endif
        @if($list)<div class="acts" style="margin-top:.6rem;justify-content:flex-start"><a class="btn sec" href="{{ route('wh.print.pick', $so) }}" target="_blank">Print pick list</a>@if($list->status === 'picked')<a class="btn" href="{{ route('wh.pack', $so) }}">Pack</a>@endif</div>@endif
    </div>
    <div class="card"><div class="tblwrap"><table class="tbl"><tr><th>Bin</th><th>SKU</th><th>Item</th><th>Qty</th><th>Picked</th><th>Lots (FEFO)</th><th></th></tr>
        @foreach($lines as $l)<tr class="{{ (float) $l->qty_picked >= (float) $l->qty ? 'done' : '' }}"><td>{{ $l->bin?->code ?? '—' }}</td><td>{{ $l->product->sku }}</td><td>{{ $l->product->name }}</td><td>{{ (float) $l->qty }}</td><td>{{ (float) $l->qty_picked }}</td>
            <td>@foreach($l->lots as $a){{ $a->lot->lot_number }} exp {{ $a->lot->expires_on?->format('Y-m-d') ?? '—' }} ({{ (float) $a->qty_picked }}/{{ (float) $a->qty_allocated }})@if(! $loop->last)<br>@endif @endforeach</td>
            <td>@if($list->status === 'open' && (float) $l->qty_picked < (float) $l->qty)<button class="btn2" wire:click="startShort({{ $l->id }})">Short pick</button>@endif</td></tr>@endforeach
    </table></div>
    @if($shortLineId)
        <div class="card" data-test="short"><h3>Short pick (manager approval)</h3>
            <div class="row"><label>New order qty <input wire:model="shortQty"></label><label>Reason <input wire:model="shortReason" placeholder="e.g. damaged, not on shelf"></label></div>
            @unless(auth()->user()->isAdmin())<div class="row"><label>Manager email <input wire:model="approverEmail"></label><label>Password <input type="password" wire:model="approverPassword" autocomplete="off"></label></div>@endunless
            <div class="row"><button class="btn2" wire:click="$set('shortLineId', null)">Cancel</button><button wire:click="shortPick">Approve short pick</button></div>
        </div>
    @endif
    @if($list && $list->status === 'open')<button style="margin-top:.6rem" wire:click="complete">Complete pick</button>@endif
    </div>
</div>

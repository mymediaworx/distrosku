<div>
    <div class="card"><h3>Count {{ $count->number }} · {{ $count->warehouse->code }} · {{ $count->type }}@if($count->blind) · blind @endif <span class="pill">{{ $count->status }}</span></h3>
        @if($count->status === 'counting')
            <div class="row"><label>Lot <input wire:model="lot" placeholder="lot # (lot-tracked items)" data-test="lot"></label>
                <label>Expires <input type="date" wire:model="expires"></label></div>
            @include('livewire.warehouse.scanbox', ['action' => 'add'])
        @else
            @if($ok)<p class="ok" data-test="ok">{{ $ok }}</p>@endif @if($err)<p class="err">{{ $err }}</p>@endif
            <p>Counting is closed for this count.</p>
        @endif
        <p><a href="{{ route('wh.print.count', $count) }}" target="_blank">Print count sheet</a></p>
    </div>
    <div class="card"><table class="tbl"><tr><th>Bin</th><th>SKU</th><th>Item</th><th>Lot</th>@if($showExpected)<th>Expected</th>@endif<th>Counted</th><th></th></tr>
        @foreach($lines as $l)<tr class="{{ $l->counted !== null ? 'done' : '' }}"><td>{{ $l->bin?->code ?? '—' }}</td><td>{{ $l->product->sku }}</td><td>{{ $l->product->name }}</td>
            <td>{{ $l->lot?->lot_number ?? ($l->product->track_lots ? 'unlotted' : '') }}</td>@if($showExpected)<td>{{ (float) $l->expected }}</td>@endif
            <td>{{ $l->counted === null ? '—' : (float) $l->counted }}@if($l->counted !== null && $l->counted > 0) <small>({{ \App\Warehouse\Uom::describe($l->product, $l->counted) }})</small>@endif</td>
            <td>@if($count->status === 'counting')<span x-data="{v: ''}"><input x-model="v" style="width:70px" inputmode="decimal" aria-label="Set total"><button class="btn2" @click="$wire.setLine({{ $l->id }}, v); v=''">Set</button></span>@endif</td></tr>@endforeach
    </table>
    @if($count->status === 'counting')
        <div class="row" style="margin-top:.6rem"><button class="btn2" wire:click="undo">Undo last scan</button>
            <label><input type="checkbox" wire:model="zero"> treat uncounted as zero</label>
            <button wire:click="submit" wire:confirm="Submit this count for approval? Counting will close.">Submit count</button></div>
    @endif
    </div>
</div>

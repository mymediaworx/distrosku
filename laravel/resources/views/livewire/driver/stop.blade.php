<div>
    <p><a href="{{ route('driver.today', ['date' => $stop->manifest->delivery_date->toDateString()]) }}">&larr; All stops</a></p>
    <div class="card"><div class="stop"><span class="seq">{{ $stop->sequence }}</span><div style="flex:1"><strong>{{ $so->customer->name }}</strong>
        <div class="muted">{{ $so->shipTo?->name }} · {{ $so->shipTo?->address }}, {{ $so->shipTo?->city }} {{ $so->shipTo?->state }} {{ $so->shipTo?->zip }}</div>
        <div class="muted">{{ $so->number }} · {{ $so->customer->terms }} · ${{ number_format((float) $so->total, 2) }}</div></div><span class="pill {{ $stop->status }}" data-test="stop-status">{{ $stop->status }}</span></div>
        @if($url = $stop->mapUrl())<p style="margin:.7rem 0 0"><a class="btn btn2" href="{{ $url }}" target="_blank" rel="noopener" style="width:100%">Map / directions</a></p>@endif
        @if($so->customer->phone)<p style="margin:.5rem 0 0"><a href="tel:{{ $so->customer->phone }}">Call {{ $so->customer->phone }}</a></p>@endif
    </div>
    <div class="card"><table class="tbl"><tr><th>Item</th><th>Qty</th>@if($stop->status === 'pending' && $outcome === 'partial')<th>Delivered</th>@endif</tr>
        @foreach($so->lines as $l)<tr><td>{{ $l->product->name }}<br><span class="muted">{{ $l->product->sku }}@if($l->product->tax_class?->isRegulated()) · 21+@endif</span></td><td>{{ (float) $l->qty }}</td>
            @if($stop->status === 'pending' && $outcome === 'partial')<td style="width:90px"><input inputmode="decimal" wire:model="qty.{{ $l->id }}" data-test="qty-{{ $l->id }}"></td>@endif</tr>@endforeach
    </table></div>
    @if($err)<div class="card err" data-test="err">{{ $err }}</div>@endif
    @if($ok)<div class="card ok" data-test="ok">{{ $ok }}</div>@endif
    @if($stop->status === 'pending')
        <div class="card">
            <label>Outcome</label>
            <div class="grid">
                @foreach(['delivered' => 'Delivered', 'partial' => 'Partial', 'refused' => 'Refused'] as $k => $v)
                    <button type="button" wire:click="$set('outcome','{{ $k }}')" class="{{ $outcome === $k ? '' : 'btn2' }}" data-test="outcome-{{ $k }}">{{ $v }}</button>
                @endforeach
            </div>
            @if($outcome === 'refused')<p class="muted">Refused only records the status. The office will re-deliver or cancel the order.</p>@endif
            <label for="notes">Notes</label><textarea id="notes" wire:model="notes" rows="2"></textarea>
            <label for="photo">Photo proof (optional)</label><input id="photo" type="file" accept="image/*" capture="environment" wire:model="photo">
            <div wire:loading wire:target="photo" class="muted">Uploading photo…</div>
        </div>
        @if($needsSig && $outcome !== 'refused')<div class="card">@include('partials.signature-pad', ['acks' => $acks])</div>@endif
        <button wire:click="complete" wire:loading.attr="disabled" data-test="complete" class="{{ $outcome === 'refused' ? 'bad' : '' }}">Complete stop</button>
    @else
        @foreach($so->signatures as $sg)<div class="card"><strong>Signed by {{ $sg->signer_name }}</strong> <span class="muted">{{ $sg->signed_at->timezone(config('app.timezone'))->format('M j g:i A') }}</span></div>@endforeach
        @if($stop->notes)<div class="card">{{ $stop->notes }}</div>@endif
    @endif
</div>

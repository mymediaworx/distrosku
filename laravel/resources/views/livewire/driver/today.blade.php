<div>
    <div class="card" style="display:flex;gap:.5rem;align-items:center"><strong style="flex:1">Stops for {{ \Illuminate\Support\Carbon::parse($date)->format('D M j') }}</strong>
        <input type="date" wire:model.live="date" style="width:auto"></div>
    @forelse($manifests as $m)
        <div class="card"><strong>{{ $m->number }}</strong> @if($m->route_name)· {{ $m->route_name }}@endif <span class="pill">{{ $m->status }}</span>
            <span class="muted">· {{ $m->stops->where('status', 'pending')->count() }} of {{ $m->stops->count() }} left</span></div>
        @foreach($m->stops as $s)
            <div class="card" data-test="stop-{{ $s->id }}">
                <a class="stop" href="{{ route('driver.stop', $s) }}"><span class="seq">{{ $s->sequence }}</span>
                    <span style="flex:1"><strong>{{ $s->salesOrder->customer->name }}</strong><br>
                        <span class="muted">{{ $s->salesOrder->shipTo?->address }} {{ $s->salesOrder->shipTo?->city }} · {{ $s->salesOrder->number }} · ${{ number_format((float) $s->salesOrder->total, 2) }}</span></span>
                    <span class="pill {{ $s->status }}">{{ $s->status }}</span></a>
                @if($url = $s->mapUrl())<p style="margin:.5rem 0 0"><a href="{{ $url }}" target="_blank" rel="noopener">Open map</a></p>@endif
            </div>
        @endforeach
    @empty
        <div class="card muted">No manifests assigned to you for this day.</div>
    @endforelse
</div>

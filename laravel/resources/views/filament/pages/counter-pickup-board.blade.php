@php
    use App\Sales\PickupBoard as B;
    $cols = $this->columns();
    $tv = $this->tv;
    $fs = $tv ? 1.35 : 1;
    $canMove = B::canMove(); $canHand = B::canHandOver(); $canSign = B::canSign();
    $ageBg = ['gray' => '#f3f4f6', 'amber' => '#fef3c7', 'red' => '#fee2e2'];
    $ageFg = ['gray' => '#374151', 'amber' => '#92400e', 'red' => '#991b1b'];
    $head = ['to_pick' => '#6b7280', 'picking' => '#2563eb', 'ready' => '#d97706', 'done' => '#16a34a'];
@endphp
<x-filament-panels::page>
<style>.cpb-cols{grid-template-columns:repeat(4,minmax(0,1fr))}@media(max-width:1100px){.cpb-cols{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:640px){.cpb-cols{grid-template-columns:1fr}}</style>
    <div wire:poll.30s style="font-size: {{ $fs }}rem">
        <div style="display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; margin-bottom:1rem">
            <select wire:model.live="warehouseId" style="border:1px solid #d1d5db; border-radius:.5rem; padding:.4rem .6rem; font-size:inherit">
                <option value="">All warehouses</option>
                @foreach($this->warehouses() as $id => $code)
                    <option value="{{ $id }}">{{ $code }}</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search order #, customer, phone"
                   style="border:1px solid #d1d5db; border-radius:.5rem; padding:.4rem .6rem; min-width:0; width:min(16rem,100%); font-size:inherit">
 <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-arrow-top-right-on-square" data-test="tv-popout" target="_blank" rel="noopener"
                href="{{ route('pickup.tv', array_filter(['wh' => $this->warehouseId])) }}"
                onclick="window.open(this.href,'pickupTv','popup=yes,width=1600,height=900');return false;">Open TV view (new window)</x-filament::button>
            <x-filament::button color="gray" wire:click="toggleTv" icon="heroicon-o-tv" size="sm">
                {{ $tv ? 'Normal view' : 'TV mode (large type)' }}
            </x-filament::button>
            <span style="color:#6b7280; font-size:.85em">Refreshes every 30 seconds · amber over {{ B::AMBER_HOURS }}h, red over {{ B::RED_HOURS }}h or past the requested pickup date</span>
        </div>

        <div class="cpb-cols" style="display:grid; gap:1rem; align-items:start">
            @foreach(B::STAGES as $key => $label)
                <div style="background:#f9fafb; border-radius:.75rem; padding:.6rem; min-height:12rem" data-stage="{{ $key }}">
                    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid {{ $head[$key] }}; padding-bottom:.35rem; margin-bottom:.6rem">
                        <strong style="font-size:1.1em">{{ $label }}</strong>
                        <span style="background:{{ $head[$key] }}; color:#fff; border-radius:999px; padding:0 .6rem; font-weight:700">{{ $cols[$key]->count() }}</span>
                    </div>
                    @forelse($cols[$key] as $o)
                        @php
                            $c = B::ageColor($o); $sig = B::needsSignature($o); $signed = $sig && B::signed($o);
                            $bal = (float) $o->balance(); $phone = B::phone($o);
                        @endphp
                        <div wire:key="card-{{ $o->id }}" style="background:#fff; border:1px solid #e5e7eb; border-left:5px solid {{ $c === 'gray' ? '#d1d5db' : $ageFg[$c] }}; border-radius:.6rem; padding:.6rem; margin-bottom:.6rem">
                            <div style="display:flex; justify-content:space-between; gap:.5rem">
                                <a href="{{ B::orderUrl($o) }}" style="font-weight:700; color:#1d4ed8">{{ $o->number }}</a>
                                <span style="background:{{ $ageBg[$c] }}; color:{{ $ageFg[$c] }}; border-radius:.4rem; padding:0 .4rem; font-weight:600; white-space:nowrap">
                                    {{ $key === 'done' ? $o->delivered_at?->format('g:i a') : B::ageLabel($o) }}
                                </span>
                            </div>
                            <div style="font-weight:600">{{ $o->customer?->name }}</div>
                            @if($phone)<div style="color:#4b5563; font-size:.9em">{{ $phone }}</div>@endif
                            <div style="color:#4b5563; font-size:.9em">
                                {{ $o->lines->count() }} items · {{ B::units($o) }} units · ${{ number_format((float) $o->total, 2) }}
                                @if($bal > 0.004) · <span style="color:#b91c1c; font-weight:600">due ${{ number_format($bal, 2) }}</span>@else · <span style="color:#15803d">paid</span>@endif
                            </div>
                            @if(! $this->warehouseId && $o->warehouse)<div style="color:#6b7280; font-size:.8em">{{ $o->warehouse->code }}</div>@endif
                            @if($o->ship_date)<div style="font-size:.85em; color:{{ $o->ship_date->lt(now()->startOfDay()) ? '#b91c1c' : '#4b5563' }}">Pickup by {{ $o->ship_date->format('D M j') }}</div>@endif
                            @if($sig)
                                <div style="margin-top:.25rem"><span style="background:{{ $signed ? '#dcfce7' : '#ede9fe' }}; color:{{ $signed ? '#166534' : '#5b21b6' }}; border-radius:.4rem; padding:0 .4rem; font-size:.85em; font-weight:600">
                                    {{ $signed ? 'Regulated · signed' : 'Regulated · signature needed' }}</span></div>
                            @endif
                            @if($o->notes)<div style="margin-top:.25rem; font-size:.85em; color:#374151; font-style:italic">{{ \Illuminate\Support\Str::limit($o->notes, 140) }}</div>@endif

                            <div style="display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.5rem">
                                @if(in_array($key, ['to_pick', 'picking', 'ready']))
                                    <x-filament::button tag="a" href="{{ route('wh.print.pick', $o) }}" target="_blank" color="gray" size="xs" icon="heroicon-o-printer">Pick list</x-filament::button>
                                @endif
                                @if($key === 'to_pick' && $canMove) {{ ($this->startPickingAction)(['id' => $o->id]) }} @endif
                                @if(in_array($key, ['to_pick', 'picking']) && $canMove) {{ ($this->markReadyAction)(['id' => $o->id]) }} @endif
                                @if($key === 'ready' && $canHand)
                                    @if($sig && ! $signed)
                                        @if($canSign)
                                            <x-filament::button tag="a" href="{{ route('driver.pickup', $o) }}" color="warning" size="xs" icon="heroicon-o-pencil-square">Sign & pick up</x-filament::button>
                                        @endif
                                    @else
                                        {{ ($this->pickedUpAction)(['id' => $o->id]) }}
                                        {{ ($this->collectAction)(['id' => $o->id]) }}
                                    @endif
                                @endif
                                @if($key === 'done' && $canHand && $bal > 0.004) {{ ($this->collectAction)(['id' => $o->id]) }} @endif
                                @if($key === 'picking' && $canMove) {{ ($this->unstartAction)(['id' => $o->id]) }} @endif
                            </div>
                        </div>
                    @empty
                        <div style="color:#9ca3af; text-align:center; padding:1.5rem 0">Nothing here</div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>

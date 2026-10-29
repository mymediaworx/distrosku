<x-filament-panels::page>
    <div class="flex gap-2 items-end">
        <label class="text-sm">Lot, SKU / UPC, or order number<br><input wire:model.live.debounce.500ms="q" class="rounded border-gray-300 dark:bg-gray-900" data-test="trace-q" placeholder="e.g. L2401, HEMP-10, POS-000123"></label>
        @if(trim($q) !== '')<x-filament::button tag="a" :href="route('wh.trace.csv', ['q' => $q])" icon="heroicon-o-arrow-down-tray">CSV</x-filament::button>@endif
    </div>
    @php($t = $this->getTrace())
    @if(trim($q) !== '' && ! $t['lots'])<p class="text-sm">No lots found for "{{ $q }}".</p>@endif
    @foreach($t['lots'] as $row)
        @php($lot = $row['lot'])
        <x-filament::section>
            <x-slot name="heading">{{ $lot->product->sku }} {{ $lot->product->name }} · lot {{ $lot->lot_number }}
                · exp {{ $lot->expires_on?->toDateString() ?? 'none' }} · on hand {{ (float) $row['on_hand'] }}
                @if($lot->on_hold)<span class="text-danger-600 font-bold"> · ON HOLD</span>@endif
                @if($row['coa_missing'])<span class="text-danger-600 font-bold" data-test="coa-missing"> · COA missing</span>@endif
            </x-slot>
            @if($this->canHold())
                <x-slot name="headerEnd"><x-filament::button size="sm" color="{{ $lot->on_hold ? 'gray' : 'danger' }}" wire:click="toggleHold({{ $lot->id }})" wire:confirm="{{ $lot->on_hold ? 'Release this lot?' : 'Put this lot on hold? It will not be picked or sold.' }}">{{ $lot->on_hold ? 'Release' : 'Hold' }}</x-filament::button></x-slot>
            @endif
            <p class="text-sm">Received: @forelse($row['received'] as $r){{ $r['date'] }} {{ $r['po'] }} ({{ (float) $r['qty'] }})@if(! $loop->last); @endif @empty none recorded @endforelse
                · COAs: @forelse($row['coas'] as $c){{ $c->lab ?? 'COA' }} {{ $c->tested_on?->toDateString() }}@if(! $loop->last), @endif @empty none linked @endforelse</p>
            <div class="overflow-x-auto"><table class="w-full text-sm mt-2">
                <thead><tr><th class="text-left p-1 border-b">Shipped</th><th class="text-left p-1 border-b">Order</th><th class="text-left p-1 border-b">Customer</th><th class="text-left p-1 border-b">Ship to</th><th class="text-left p-1 border-b">Phone</th><th class="text-left p-1 border-b">Qty</th><th class="text-left p-1 border-b">Contacted</th></tr></thead>
                <tbody>
                @forelse($row['sales'] as $s)
                    @php($k = $lot->id.'|'.($s['customer_id'] ?? 0))
                    @php($done = $row['contacts']->where('customer_id', $s['customer_id']))
                    <tr>
                        <td class="p-1 border-b">{{ $s['date'] }}</td><td class="p-1 border-b">{{ $s['order'] }}</td><td class="p-1 border-b">{{ $s['customer'] }}</td>
                        <td class="p-1 border-b">{{ $s['ship_to'] }}</td><td class="p-1 border-b">{{ $s['phone'] }}</td><td class="p-1 border-b">{{ (float) $s['qty'] }}</td>
                        <td class="p-1 border-b">
                            @foreach($done as $c)<div>{{ $c->contacted_at->format('Y-m-d H:i') }} · {{ $this->methods()[$c->method] ?? $c->method }} · {{ $c->user?->name }}@if($c->note) · {{ $c->note }}@endif</div>@endforeach
                            <div class="flex gap-1 items-center">
                                <select wire:model="method.{{ $k }}" class="rounded border-gray-300 text-xs dark:bg-gray-900"><option value="">how?</option>@foreach($this->methods() as $mk => $ml)<option value="{{ $mk }}">{{ $ml }}</option>@endforeach</select>
                                <input wire:model="note.{{ $k }}" placeholder="note" class="rounded border-gray-300 text-xs dark:bg-gray-900">
                                <x-filament::button size="xs" wire:click="logContact({{ $lot->id }}, {{ $s['customer_id'] ?? 'null' }}, {{ $s['ship_to_id'] ?? 'null' }})">Log contact</x-filament::button>
                            </div>
                        </td>
                    </tr>
                @empty<tr><td class="p-1" colspan="7">No shipments of this lot.</td></tr>@endforelse
                </tbody>
            </table></div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>

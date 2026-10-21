<x-filament-panels::page>
    @php($d = $this->getData())
    <div class="flex flex-wrap gap-4 items-end text-sm">
        <label>Ship-to state<br><select wire:model.live="state" class="rounded border-gray-300 dark:bg-gray-900">@foreach($d['states'] as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select></label>
        <label>Look ahead (days)<br><input type="number" min="1" max="730" wire:model.live.debounce.500ms="horizon" class="rounded border-gray-300 dark:bg-gray-900 w-24"></label>
        <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="includeDrafts" class="rounded"> Include draft (disabled) rules</label>
        <span>Sell-through = units sold into this state in the last 30 days ÷ 30. Value at current product cost.</span>
    </div>
    <x-filament::section heading="SKUs that become blocked">
        @if($d['rows'])
            <p class="text-sm mb-2">On hand at risk: <b>${{ number_format($d['total'], 2) }}</b> · projected unsold at cutoff: <b>${{ number_format($d['stranded'], 2) }}</b></p>
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <thead><tr class="text-left border-b"><th class="p-1">SKU</th><th class="p-1">Item</th><th class="p-1">Rule</th><th class="p-1">Blocked from</th><th class="p-1">Days left</th><th class="p-1">On hand</th><th class="p-1">Value</th><th class="p-1">Sold/day</th><th class="p-1">Unsold at cutoff</th><th class="p-1">Why</th></tr></thead>
                <tbody>@foreach($d['rows'] as $r)<tr class="border-b">
                    <td class="p-1">{{ $r['product']->sku }}</td><td class="p-1">{{ $r['product']->name }}</td>
                    <td class="p-1">{{ $r['rule']->name }} @if($r['draft'])<span class="text-xs rounded bg-gray-200 px-1 dark:bg-gray-700">draft</span>@endif</td>
                    <td class="p-1">{{ $r['date'] }}</td><td class="p-1 font-bold">{{ $r['days_left'] }}</td><td class="p-1">{{ (float) $r['on_hand'] }}</td>
                    <td class="p-1">${{ number_format($r['value'], 2) }}</td><td class="p-1">{{ $r['sold_per_day'] }}</td>
                    <td class="p-1">{{ (float) $r['stranded_qty'] }} (${{ number_format($r['stranded_value'], 2) }})</td><td class="p-1">{{ $r['why'] }}</td></tr>@endforeach</tbody>
            </table></div>
        @else
            <p class="text-sm">No stocked SKU becomes blocked in {{ $this->state }} within {{ $this->horizon }} days{{ $this->includeDrafts ? '' : ' (enabled rules only; tick "Include draft rules" to preview drafts)' }}.</p>
        @endif
    </x-filament::section>
    <x-filament::section heading="COAs expired or expiring within {{ $d['warnDays'] }} days">
        @if($d['coas']->isNotEmpty())
            <table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-1">SKU</th><th class="p-1">Item</th><th class="p-1">Lab</th><th class="p-1">Batch / lot</th><th class="p-1">Expires</th><th class="p-1">Status</th></tr></thead>
                <tbody>@foreach($d['coas'] as $c)<tr class="border-b"><td class="p-1">{{ $c->product?->sku }}</td><td class="p-1">{{ $c->product?->name }}</td><td class="p-1">{{ $c->lab }}</td><td class="p-1">{{ $c->batch_lot }}</td>
                    <td class="p-1">{{ $c->expires_on?->toDateString() }}</td><td class="p-1">{{ $c->state() === 'expired' ? 'Expired' : 'Expiring soon' }}</td></tr>@endforeach</tbody></table>
        @else
            <p class="text-sm">No COA expires within {{ $d['warnDays'] }} days.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>

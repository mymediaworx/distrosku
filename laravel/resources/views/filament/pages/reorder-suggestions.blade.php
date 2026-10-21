<x-filament-panels::page>
    @php($d = $this->getData())
    <div class="flex flex-wrap gap-4 items-end text-sm">
        <label>Warehouse<br><select wire:model.live="warehouseId" class="rounded border-gray-300 dark:bg-gray-900">@foreach($d['warehouses'] as $id => $code)<option value="{{ $id }}">{{ $code }}</option>@endforeach</select></label>
        <span>Velocity over {{ $d['cfg']['window'] }} days · default lead {{ $d['cfg']['lead'] }} days · order {{ $d['cfg']['cover'] }} days of cover (Settings &gt; Warehouse)</span>
        @if(static::canOrder() && $d['groups']->except(0)->isNotEmpty())<x-filament::button wire:click="createPo" wire:confirm="Create one draft PO per vendor for all suggestions?" icon="heroicon-o-document-plus">Draft POs for all vendors</x-filament::button>@endif
    </div>
    @forelse($d['groups'] as $vid => $rows)
        <x-filament::section :heading="$rows->first()['vendor']?->name ?? 'No vendor (set a preferred vendor on the product)'">
            <x-slot name="headerEnd">
                @if($vid && static::canOrder())
                    <x-filament::button size="sm" wire:click="createPo({{ $vid }})">Create draft PO</x-filament::button>
                @endif
            </x-slot>
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <thead><tr class="text-left border-b"><th class="p-1">SKU</th><th class="p-1">Item</th><th class="p-1">On hand</th><th class="p-1">Reserved</th><th class="p-1">On order</th><th class="p-1">Sold/day</th><th class="p-1">Lead days</th><th class="p-1">Reorder level</th><th class="p-1">Suggested</th><th class="p-1">Value</th></tr></thead>
                <tbody>@foreach($rows as $r)<tr class="border-b"><td class="p-1">{{ $r['product']->sku }}</td><td class="p-1">{{ $r['product']->name }}</td><td class="p-1">{{ (float) $r['on_hand'] }}</td><td class="p-1">{{ (float) $r['reserved'] }}</td>
                    <td class="p-1">{{ (float) $r['on_order'] }}</td><td class="p-1">{{ round((float) $r['velocity'], 2) }}</td><td class="p-1">{{ $r['lead_days'] }}</td><td class="p-1">{{ (float) $r['reorder_level'] }}</td>
                    <td class="p-1 font-bold">{{ (float) $r['qty'] }} <small>({{ \App\Warehouse\Uom::describe($r['product'], $r['qty']) }})</small></td><td class="p-1">${{ number_format((float) $r['value'], 2) }}</td></tr>@endforeach</tbody>
            </table></div>
        </x-filament::section>
    @empty
        <x-filament::section>Nothing needs reordering in this warehouse.</x-filament::section>
    @endforelse
</x-filament-panels::page>

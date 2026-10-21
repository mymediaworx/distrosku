<x-filament-panels::page>
    <div class="flex gap-2 items-end">
        <label class="text-sm">Lot number<br><input wire:model.live.debounce.500ms="lot" class="rounded border-gray-300 dark:bg-gray-900" data-test="lot"></label>
        @if(trim($lot) !== '')<x-filament::button tag="a" :href="route('wh.recall.csv', ['lot' => $lot, 'product' => $product])" icon="heroicon-o-arrow-down-tray">CSV</x-filament::button>@endif
    </div>
    @php($rep = $this->getReport())
    @if($rep)
        <x-filament::section :heading="$rep['title']">
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <thead><tr>@foreach($rep['columns'] as $c)<th class="text-left p-1 border-b">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>@forelse($rep['rows'] as $r)<tr>@foreach($r as $v)<td class="p-1 border-b">{{ $v }}</td>@endforeach</tr>@empty<tr><td class="p-1" colspan="9">No shipments of this lot.</td></tr>@endforelse</tbody>
                <tfoot><tr>@foreach($rep['totals'] as $v)<td class="p-1 font-bold">{{ $v }}</td>@endforeach</tr></tfoot>
            </table></div>
            @foreach($rep['notes'] as $n)<p class="text-sm mt-2">{{ $n }}</p>@endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>

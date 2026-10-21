<x-filament-panels::page>
    <div class="flex flex-wrap gap-2 text-sm">
        @foreach($this->lines() as [$code, $label, $val])
            <button type="button" wire:click="$set('line', @js($code))"
                class="rounded-lg px-2 py-1 border {{ $line === (string) $code ? 'bg-primary-600 text-white' : 'border-gray-300' }}">{{ $code }} · {{ $val }}</button>
        @endforeach
    </div>
    @if($line !== '')
        <x-filament::section :heading="'Line '.$line.' - '.count($this->refs()).' source lines'">
            <table class="w-full text-sm">
                <thead><tr class="text-left border-b"><th class="p-1">Type</th><th class="p-1">Document</th><th class="p-1">Date</th><th class="p-1">Customer / vendor</th><th class="p-1">SKU</th><th class="p-1 text-right">Qty</th><th class="p-1 text-right">Amount</th></tr></thead>
                <tbody>
                @forelse($this->refs() as $r)
                    @php($u = \App\Filament\Pages\ReturnDrill::url($r))
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="p-1">{{ ['so' => 'Invoice', 'cm' => 'Credit memo', 'po' => 'Purchase order'][$r['type']] ?? $r['type'] }}</td>
                        <td class="p-1">@if($u)<a href="{{ $u }}" class="text-primary-600 underline">{{ $r['number'] }}</a>@else{{ $r['number'] }} @endif</td>
                        <td class="p-1">{{ $r['date'] }}</td><td class="p-1">{{ $r['party'] }}</td><td class="p-1">{{ $r['sku'] }}</td>
                        <td class="p-1 text-right font-mono">{{ $r['qty'] }}</td><td class="p-1 text-right font-mono">{{ $r['amount'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-1">No source documents for this line.</td></tr>
                @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @else
        <p class="text-sm text-gray-500">Pick a line to see the invoices, credit memos and purchase orders behind it.</p>
    @endif
</x-filament-panels::page>

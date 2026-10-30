<x-filament-panels::page>
    <x-filament::section heading="Reconciliation" description="Stamps bought minus stamps affixed (one stamp per pack), by state and pack size.">
        <div class="flex gap-4 mb-3 items-end">
            <label class="text-sm">From <input type="date" wire:model.live="from" class="fi-input rounded border-gray-300 dark:bg-gray-900"></label>
            <label class="text-sm">To <input type="date" wire:model.live="to" class="fi-input rounded border-gray-300 dark:bg-gray-900"></label>
        </div>
        <table class="w-full text-sm" data-test="stamp-recon">
            <thead><tr class="border-b"><th class="text-left px-2">State</th><th class="text-left px-2">Pack</th><th class="text-right px-2">On hand at start</th><th class="text-right px-2">Bought</th><th class="text-right px-2">Affixed</th><th class="text-right px-2">On hand at end</th></tr></thead>
            <tbody>
            @forelse($rows as $r)
                <tr class="border-b border-gray-100"><td class="px-2">{{ $r['state'] }}</td><td class="px-2">{{ $r['pack_size'] }}s</td><td class="text-right px-2">{{ number_format($r['opening']) }}</td><td class="text-right px-2">{{ number_format($r['bought']) }}</td><td class="text-right px-2">{{ number_format($r['affixed']) }}</td><td class="text-right px-2 font-semibold">{{ number_format($r['closing']) }}</td></tr>
            @empty
                <tr><td colspan="6" class="px-2 py-2 text-gray-500">No stamps recorded yet. Use "Record stamp purchase" above.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
    <x-filament::section heading="Recent stamping">
        <table class="w-full text-sm"><thead><tr class="border-b"><th class="text-left px-2">Date</th><th class="text-left px-2">From</th><th class="text-left px-2">To</th><th class="text-right px-2">Packs</th><th class="text-left px-2">Where</th><th class="text-left px-2">By</th></tr></thead><tbody>
        @forelse($affixings as $a)<tr class="border-b border-gray-100"><td class="px-2">{{ $a->affixed_on->format('m/d/Y') }}</td><td class="px-2">{{ $a->fromProduct?->sku }}</td><td class="px-2">{{ $a->toProduct?->sku }} ({{ $a->state }})</td><td class="text-right px-2">{{ number_format($a->packs) }}</td><td class="px-2">{{ $a->warehouse?->code }}</td><td class="px-2">{{ $a->user?->name }}</td></tr>
        @empty<tr><td colspan="6" class="px-2 py-2 text-gray-500">None yet.</td></tr>@endforelse
        </tbody></table>
    </x-filament::section>
    <x-filament::section heading="Recent stamp purchases">
        <table class="w-full text-sm"><thead><tr class="border-b"><th class="text-left px-2">Date</th><th class="text-left px-2">State</th><th class="text-left px-2">Pack</th><th class="text-right px-2">Stamps</th><th class="text-right px-2">Total</th><th class="text-left px-2">Reference</th></tr></thead><tbody>
        @forelse($purchases as $p)<tr class="border-b border-gray-100"><td class="px-2">{{ $p->purchased_on->format('m/d/Y') }}</td><td class="px-2">{{ $p->state }}</td><td class="px-2">{{ $p->pack_size }}s</td><td class="text-right px-2">{{ number_format($p->stamps) }}</td><td class="text-right px-2">${{ number_format((float) $p->total, 2) }}</td><td class="px-2">{{ $p->reference }}</td></tr>
        @empty<tr><td colspan="6" class="px-2 py-2 text-gray-500">None yet.</td></tr>@endforelse
        </tbody></table>
    </x-filament::section>
</x-filament-panels::page>

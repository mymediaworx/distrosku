<x-filament-panels::page>
    <form wire:submit="saveSettings">
        {{ $this->form }}
        <div class="mt-3"><x-filament::button type="submit" size="sm">Save settings</x-filament::button></div>
    </form>

    <x-filament::section>
        <label class="text-sm">Week ending
            <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="week_ending" /></x-filament::input.wrapper>
        </label>
    </x-filament::section>

    @php($r = $this->getReport())
    <x-filament::section heading="Pre-check" :description="($r['file_name'] ?? '').' - '.(($r['test'] ?? true) ? 'TEST' : 'LIVE')">
        @if (empty($r['errors']) && empty($r['warnings']))
            <p class="text-success-600 text-sm" data-test="msa-ok">No problems found.</p>
        @endif
        @foreach ($r['errors'] as $e)<p class="text-danger-600 text-sm" data-test="msa-error">Error: {{ $e }}</p>@endforeach
        @foreach ($r['warnings'] as $w)<p class="text-warning-600 text-sm">Warning: {{ $w }}</p>@endforeach
        @if (! empty($r['totals']))
            <p class="text-sm mt-2">BID {{ $r['totals']['bid'] }} · SID {{ $r['totals']['sid'] }} · PUR {{ $r['totals']['pur'] }} ·
                qty {{ $r['totals']['qty'] }} · inventory {{ $r['totals']['inventory'] }}@if($r['totals']['dollars'] !== null) · ${{ $r['totals']['dollars'] }}@endif</p>
        @endif
    </x-filament::section>

    @foreach (['bid' => ['Products (BID)', ['SKU', 'UPC', 'Description', 'Items/unit', 'MSA cat', 'Inventory']],
               'sid' => ['Ship-tos (SID)', ['Cust #', 'Ship #', 'Name', 'Address', 'Class of trade']],
               'pur' => ['Purchases (PUR)', ['Cust #', 'Ship #', 'SKU', 'Txn date', 'Kind', 'Selling units', 'Dollars']]] as $k => [$h, $cols])
        <x-filament::section :heading="$h" collapsible>
            <div class="overflow-x-auto max-w-full" data-test="msa-table-wrap"><table class="w-full text-sm">
                <thead><tr class="border-b">@foreach ($cols as $c)<th class="px-2 py-1 text-left">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>@forelse ($r['preview'][$k] ?? [] as $row)<tr class="border-b border-gray-100">@foreach ($row as $c)<td class="px-2 py-1">{{ $c }}</td>@endforeach</tr>@empty<tr><td class="px-2 py-2 text-gray-500" colspan="{{ count($cols) }}">None.</td></tr>@endforelse</tbody>
            </table></div>
        </x-filament::section>
    @endforeach

    @if (! empty($r['lines']))
        <x-filament::section heading="Raw file preview" collapsible collapsed>
            <pre class="text-xs overflow-x-auto max-w-full max-h-96" data-test="msa-raw">{{ implode("\n", $r['lines']) }}</pre>
        </x-filament::section>
    @endif

    <x-filament::section heading="Generated files">
        <div class="overflow-x-auto max-w-full" data-test="msa-files-wrap"><table class="w-full text-sm">
            <thead><tr class="border-b"><th class="text-left px-2">Week ending</th><th class="text-left px-2">File</th><th class="text-left px-2">Mode</th><th class="text-left px-2">BID/SID/PUR</th><th class="text-left px-2">Qty</th><th class="text-left px-2">Status</th><th class="text-left px-2">Created</th><th></th></tr></thead>
            <tbody>
            @forelse ($this->submissions() as $s)
                <tr class="border-b border-gray-100">
                    <td class="px-2">{{ $s->week_ending->toDateString() }}</td><td class="px-2">{{ $s->file_name }}</td><td class="px-2">{{ $s->test ? 'TEST' : 'LIVE' }}{{ $s->altria ? ' + Altria' : '' }}</td>
                    <td class="px-2">{{ $s->bid_count }}/{{ $s->sid_count }}/{{ $s->pur_count }}</td><td class="px-2">{{ rtrim(rtrim((string) $s->qty_total, '0'), '.') }}</td>
                    <td class="px-2">{{ $s->status }}{{ $s->uploaded_at ? ' '.$s->uploaded_at->format('Y-m-d') : '' }}</td><td class="px-2">{{ $s->created_at->format('Y-m-d H:i') }}</td>
                    <td class="px-2 whitespace-nowrap">
                        <x-filament::link tag="button" wire:click="download({{ $s->id }})">Download</x-filament::link>
                        @if ($s->status !== 'uploaded')· <x-filament::link tag="button" wire:click="markUploaded({{ $s->id }})">Mark uploaded</x-filament::link>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-2 py-2 text-gray-500">No files generated yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </x-filament::section>
</x-filament-panels::page>

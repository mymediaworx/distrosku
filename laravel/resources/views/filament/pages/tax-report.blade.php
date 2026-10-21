<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm font-medium">Period
            <select wire:model.live="period" class="block mt-1 rounded-lg border-gray-300 dark:bg-gray-800 text-sm">
                @foreach($this->periodOptions() as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
            </select>
        </label>
        @php($f = $this->existingFiling())
        <span class="text-sm">Filing record:
            @if($f)<strong>#{{ $f->id }} v{{ $f->version }} {{ strtoupper($f->status) }}</strong>@if($f->isLocked()) <span class="text-danger-600">(month locked)</span>@endif@if($f->filed_at) on {{ $f->filed_at->toDateString() }}@endif
            @else <em>none yet</em>@endif
        </span>
    </div>

    @if($xmlErrors)
        <x-filament::section heading="XML download blocked: KY schema errors" class="border-danger-600">
            <ul class="list-disc ps-5 text-sm text-danger-600">@foreach($xmlErrors as $e)<li>{{ $e }}</li>@endforeach</ul>
        </x-filament::section>
    @endif

    @if($report)
        <x-filament::section :heading="$report['form'].' · '.$report['period_start'].' to '.$report['period_end']">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left border-b"><th class="p-1">Line</th><th class="p-1">Description</th><th class="p-1 text-right">Value</th></tr></thead>
                    <tbody>
                    @foreach($report['summary'] as [$line, $label, $val])
                        <tr class="border-b border-gray-100 dark:border-gray-700"><td class="p-1 font-mono">@if(isset($report['drill'][$line]))<a class="text-primary-600 underline" title="Drill down" href="{{ \App\Filament\Pages\ReturnDrill::getUrl(['type' => $this->reportKey(), 'period' => $period, 'line' => $line]) }}">{{ $line }}</a>@else{{ $line }} @endif</td><td class="p-1">{{ $label }}</td><td class="p-1 text-right font-mono">{{ $val }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 font-semibold">Tax due: ${{ $report['tax_due'] }}</p>
        </x-filament::section>

        @foreach($report['sections'] as $s)
            <x-filament::section :heading="$s['title'].' ('.count($s['rows']).')'" collapsible>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs whitespace-nowrap">
                        <thead><tr class="text-left border-b">@foreach($s['columns'] as $c)<th class="p-1">{{ $c }}</th>@endforeach</tr></thead>
                        <tbody>
                        @forelse($s['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-700">@foreach($row as $cell)<td class="p-1">{{ $cell }}</td>@endforeach</tr>
                        @empty
                            <tr><td class="p-1" colspan="{{ count($s['columns']) }}">No transactions.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach

        <x-filament::section heading="Notes / assumptions" collapsible collapsed>
            <ul class="list-disc ps-5 text-sm">@foreach($report['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>

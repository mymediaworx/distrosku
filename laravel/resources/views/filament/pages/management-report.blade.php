<x-filament-panels::page>
    @php($used = $this->filtersUsed())
    <x-filament::section>
        <div class="grid gap-3 md:grid-cols-6 text-sm">
            @if (in_array('from', $used))
                <label>From<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
            @endif
            @if (in_array('to', $used))
                <label>To<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
            @endif
            @if (in_array('as_of', $used))
                <label>As of<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="as_of" /></x-filament::input.wrapper></label>
            @endif
            @if (in_array('customer', $used))
                <div class="md:col-span-2" data-test="report-customer">
                    <livewire:customer-picker wire:model.live="customer_id" label="Customer" :active-only="false" placeholder="All customers (search to pick one)" wire:key="report-customer-picker" />
                </div>
            @endif
            @if (in_array('department', $used))
                <label>Department<x-filament::input.wrapper><x-filament::input.select wire:model.live="department_id">
                    <option value="">All departments</option>
                    @foreach ($this->departmentOptions() as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></label>
            @endif
            @if (in_array('product', $used))
                <label class="md:col-span-2">Product<x-filament::input.wrapper><x-filament::input.select wire:model.live="product_id">
                    <option value="">All products</option>
                    @foreach ($this->productOptions() as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></label>
            @endif
            @if (in_array('window', $used))
                <label>No order in the last<x-filament::input.wrapper><x-filament::input.select wire:model.live="window">
                    @foreach (\App\Analytics\DroppedCustomers::WINDOWS as $d)<option value="{{ $d }}">{{ $d }} days</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></label>
            @endif
            @if (in_array('compare', $used))
                <label>Compare<x-filament::input.wrapper><x-filament::input.select wire:model.live="compare">
                    @foreach (\App\Accounting\FinancialStatements::COMPARE as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></label>
            @endif
            @if (in_array('group', $used))
                <label>Group by<x-filament::input.wrapper><x-filament::input.select wire:model.live="group">
                    @foreach (\App\Analytics\ReportService::SALES_DIMENSIONS as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper></label>
            @endif
        </div>
    </x-filament::section>

    @php($r = $this->getReport())
    @if (! $r)
        <x-filament::section><p class="text-danger-600">The report could not be built. Check the filters and try again.</p></x-filament::section>
    @else
        <x-filament::section :heading="$r['title']" :description="$r['period']">
            <div class="overflow-x-auto">
            <table class="w-full text-sm" data-test="report-table">
                <thead><tr class="border-b">@foreach ($r['columns'] as $c)<th class="px-2 py-1 text-left font-semibold">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($r['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-white/5">@foreach ($row as $c)<td class="px-2 py-1">{{ $c }}</td>@endforeach</tr>
                    @empty
                        <tr><td class="px-2 py-3 text-gray-500" colspan="{{ count($r['columns']) }}">No data for these filters.</td></tr>
                    @endforelse
                </tbody>
                @if ($r['totals'])
                    <tfoot><tr class="font-semibold bg-gray-50 dark:bg-white/5">@foreach ($r['totals'] as $c)<td class="px-2 py-1">{{ $c }}</td>@endforeach</tr></tfoot>
                @endif
            </table>
            </div>
            @if (! empty($r['notes']))
                <ul class="mt-3 text-xs text-gray-500 list-disc ps-5">@foreach ($r['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>
            @endif
            @if (! empty($r['links']))
                <div class="mt-3 flex gap-3 text-sm">
                    <x-filament::link :href="\App\Filament\Pages\KyTobaccoDistributorReport::getUrl()">KY Tobacco Distributor report (73A422)</x-filament::link>
                    <x-filament::link :href="\App\Filament\Pages\KySubjobberReport::getUrl()">KY Subjobber cigarette report</x-filament::link>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>

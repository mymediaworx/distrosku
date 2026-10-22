<x-filament-widgets::widget>
<x-filament::section heading="Expected inbound" data-test="expected-inbound-widget">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-3 text-sm">
        @foreach (['overdue' => 'Overdue', 'today' => 'Today', 'week' => 'This week', 'later' => 'Later'] as $k => $label)
            <div class="rounded border border-gray-200 dark:border-white/10 p-2"><div class="text-gray-500">{{ $label }}</div><div class="text-xl font-semibold {{ $k === 'overdue' && $counts[$k] ? 'text-danger-600' : '' }}">{{ $counts[$k] }}</div></div>
        @endforeach
    </div>
    @forelse ($next as $po)
        <div class="flex flex-wrap justify-between gap-2 py-1 text-sm border-b border-gray-100 dark:border-white/5">
            <span><strong>{{ $po->number }}</strong> · {{ $po->vendor?->name }} → {{ $po->warehouse?->code }} · {{ $po->expected_at ? $po->expected_at->format('D M j') : 'No ETA' }}
                @if ($c = \App\Purchasing\InboundTracking::carrierLabel($po)) · {{ $c }} @endif</span>
            <span>{!! \App\Purchasing\InboundTracking::html($po) !!}
                @if ($canScan) · <a class="text-primary-600 underline" href="{{ route('wh.receive', $po) }}">Start receiving</a>@endif</span>
        </div>
    @empty
        <p class="text-sm text-gray-500">No open purchase orders.</p>
    @endforelse
    <p class="mt-2 text-sm"><a class="text-primary-600 underline" href="{{ $url }}">All expected inbound</a></p>
</x-filament::section>
</x-filament-widgets::widget>

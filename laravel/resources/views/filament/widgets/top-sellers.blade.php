<x-filament-widgets::widget>
<div wire:poll.60s class="grid gap-4 md:grid-cols-2">
@foreach (['customers' => 'Top 5 customers this month', 'products' => 'Top 5 products this month'] as $key => $label)
    @if (! is_null($$key))
    <x-filament::section :heading="$label">
        @forelse ($$key as $i => [$name, $net])
            <div class="flex justify-between py-1 text-sm border-b border-gray-100 dark:border-white/5">
                <span>{{ $i + 1 }}. {{ $name }}</span>
                @if ($showMoney)<span class="font-medium">${{ number_format((float) $net, 2) }}</span>@endif
            </div>
        @empty
            <p class="text-sm text-gray-500">No invoiced sales yet this month.</p>
        @endforelse
    </x-filament::section>
    @endif
@endforeach
</div>
</x-filament-widgets::widget>

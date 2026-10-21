<x-filament-panels::page>
    <x-filament::section>
        <dl class="grid gap-2 sm:grid-cols-2">
            @foreach($this->status() as $k => $v)
                <div><dt class="text-sm text-gray-500">{{ $k }}</dt><dd class="font-medium break-all">{{ $v }}</dd></div>
            @endforeach
        </dl>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>

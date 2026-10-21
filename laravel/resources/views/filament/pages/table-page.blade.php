<x-filament-panels::page>
    @if(method_exists($this, 'intro') && ($intro = $this->intro()))
        <x-filament::section>{!! $intro !!}</x-filament::section>
    @endif
    {{ $this->table }}
</x-filament-panels::page>

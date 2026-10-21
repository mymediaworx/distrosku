<x-filament-panels::page>
    <x-filament::section heading="Employee Quick Tools link (no login)">
        @if($url = $this->link())
            <p class="break-all font-mono" data-test="quick-link">{{ $url }}</p>
            <p class="text-sm text-gray-500 mt-2">Anyone with this link can create and look up SKUs and print labels. Regenerate it if it leaks.</p>
        @else
            <p>No link yet. Use "Generate link".</p>
        @endif
    </x-filament::section>
    <form wire:submit="save" class="space-y-4">
        {{ $this->form }}
        <x-filament::button type="submit">Save logo</x-filament::button>
    </form>
</x-filament-panels::page>

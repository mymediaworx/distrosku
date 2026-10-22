<x-filament-panels::page>
    <style>
        .dsk-tiles{display:grid;gap:.9rem;grid-template-columns:repeat(2,minmax(0,1fr))}
        @media(min-width:640px){.dsk-tiles{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(min-width:1024px){.dsk-tiles{grid-template-columns:repeat(5,minmax(0,1fr))}}
        .dsk-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.6rem;min-height:128px;padding:1rem .6rem;border-radius:1rem;
            background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);border:1px solid rgba(0,0,0,.06);text-align:center;font-weight:600;color:#1f2937;text-decoration:none;transition:transform .1s, box-shadow .1s}
        .dsk-tile:hover,.dsk-tile:focus{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.12);outline:none}
        .dsk-tile svg{width:44px;height:44px;color:rgb(var(--primary-600))}
        .dark .dsk-tile{background:rgb(255 255 255 / .05);color:#f3f4f6;border-color:rgb(255 255 255 / .1)}
    </style>
    <div style="display:flex;justify-content:flex-end">
        <x-filament::button size="sm" color="gray" wire:click="toggleHome" data-test="toggle-home" icon="heroicon-o-home">
            {{ $this->isHome() ? 'Use Dashboard as my home' : 'Make Modules my home page' }}
        </x-filament::button>
    </div>
    @foreach(collect(static::tiles())->groupBy('section') as $section => $group)
    <div class="dsk-tiles" data-test="module-tiles" style="margin-bottom:1.6rem">
        @foreach($group as $t)
            <a href="{{ $t['url'] }}" class="dsk-tile" data-test="tile">
                @svg($t['icon'])
                <span>{{ $t['label'] }}</span>
            </a>
        @endforeach
    </div>
    @endforeach
</x-filament-panels::page>

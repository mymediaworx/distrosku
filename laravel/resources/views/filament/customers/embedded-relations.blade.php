{{-- Mid-form relation tabs on Edit Customer (0.20.13). Uses page activeRelationManager for tab state. --}}
@php
    /** @var \Filament\Resources\Pages\EditRecord $livewire */
    $livewire = $this;
    $record = $livewire->getRecord();
    $managers = method_exists($livewire, 'getEmbeddedRelationManagers')
        ? $livewire->getEmbeddedRelationManagers()
        : $livewire->getRelationManagers();
    $active = $livewire->activeRelationManager;
    if ($active === null || ! array_key_exists((string) $active, $managers)) {
        $active = array_key_first($managers);
    }
@endphp

@if ($record && count($managers))
    <div class="fi-customer-embedded-relations -mx-0" wire:key="customer-embedded-relations-{{ $record->getKey() }}">
        <x-filament-panels::resources.relation-managers
            :active-manager="$active"
            :managers="$managers"
            :owner-record="$record"
            :page-class="$livewire::class"
        />
    </div>
@endif

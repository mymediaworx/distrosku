<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <livewire:customer-picker
        wire:model.live="{{ $getStatePath() }}"
        :label="''"
        :active-only="$getActiveOnly()"
        :clearable="! $isDisabled()"
        :name="$getName()"
        wire:key="customer-picker-{{ $getStatePath() }}" />
</x-dynamic-component>

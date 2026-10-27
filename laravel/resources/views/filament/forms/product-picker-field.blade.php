<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <livewire:product-picker
        wire:model.live="{{ $getStatePath() }}"
        :multi="$isMultiple()"
        :show-cost="$getShowCost()"
        :label="$getLabel() ?: 'Product'"
        :name="$getName()"
        wire:key="picker-{{ $getStatePath() }}" />
</x-dynamic-component>

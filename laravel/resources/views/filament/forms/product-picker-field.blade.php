<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <livewire:product-picker
        wire:model.live="{{ $getStatePath() }}"
        :multi="$isMultiple()"
        :show-cost="$getShowCost()"
        :label="$getLabel() ?: 'Product'"
        :name="$getName()"
        :restrict-regulated="$getRestrictRegulated()"
        wire:key="picker-{{ $getStatePath() }}-{{ (int) $getRestrictRegulated() }}" />
    @if($getRestrictRegulated())
        <p class="mt-1 text-xs text-warning-600" data-test="picker-walkin-note">Walk-in / cash customer: regulated products (tobacco, vapor, CBD/hemp, kratom) are hidden.</p>
    @endif
</x-dynamic-component>

<x-filament-panels::page>
    @php($p = $this->progress())
    @php($c = $this->current())
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-2" data-test="conflict-progress">
            <span><strong>{{ $p['open'] }}</strong> left to review · <strong>{{ $p['done'] }}</strong> done</span>
            <x-filament::button size="sm" color="gray" wire:click="rescan">Re-scan for conflicts</x-filament::button>
        </div>
        <p class="mt-2 text-sm">Resolve each one by manual research: look the license up, fix the record, attach the license documents, then Approve or Save. Skip anything you cannot settle now; skipped items come back at the end.</p>
    </x-filament::section>

    @if(! $c)
        <x-filament::section><p data-test="conflict-empty">No conflicts waiting for review. Use "Re-scan for conflicts" to check again.</p></x-filament::section>
    @else
        <x-filament::section :heading="$c->kindLabel()" data-test="conflict-card">
            <p data-test="conflict-summary">{{ $c->summary }}</p>
            @if($c->status === 'skipped')<p class="text-sm text-warning-600">Skipped earlier.</p>@endif
            <ul class="mt-2 list-disc pl-5 text-sm">
                @foreach($this->customerLinks() as $l)<li><a class="underline" href="{{ $l['url'] }}" target="_blank" rel="noopener">{{ $l['label'] }}</a></li>@endforeach
            </ul>

            @if($c->license)
                <form wire:submit="saveEdits" class="mt-4">
                    {{ $this->form }}
                </form>
            @endif

            <div class="mt-4">
                <label class="text-sm font-medium">Decision note (optional)</label>
                <textarea wire:model="note" rows="2" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:bg-gray-900"></textarea>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <x-filament::button color="success" wire:click="approve" data-test="conflict-approve">Approve as is</x-filament::button>
                @if($c->license)<x-filament::button wire:click="saveEdits" data-test="conflict-save">Save edits &amp; attachments</x-filament::button>@endif
                <x-filament::button color="gray" wire:click="skip" data-test="conflict-skip">Skip</x-filament::button>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

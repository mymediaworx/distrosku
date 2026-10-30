<x-filament-panels::page>
    <x-filament::section>
        Downloads the general ledger for the dates you pick as QuickBooks journal entries (one entry per DistroSKU journal, numbered DS&lt;id&gt;).
        QuickBooks Online: Settings &gt; Import data &gt; Journal entries, then upload the CSV. QuickBooks Desktop: File &gt; Utilities &gt; Import &gt; IIF Files.
        Each account exports under its <strong>QuickBooks name</strong> (Accounting &gt; Chart of accounts); blank uses the DistroSKU name, so create matching accounts in QuickBooks first. Nothing in DistroSKU changes.
    </x-filament::section>
    <form wire:submit="download">
        {{ $this->form }}
        <div class="mt-4"><x-filament::button type="submit" icon="heroicon-o-arrow-down-tray">Download</x-filament::button></div>
    </form>
</x-filament-panels::page>

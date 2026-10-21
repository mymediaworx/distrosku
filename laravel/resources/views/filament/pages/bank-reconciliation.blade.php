<x-filament-panels::page>
    <x-filament::section>
        <div class="grid gap-3 md:grid-cols-3 text-sm">
            <label>Bank account<x-filament::input.wrapper><x-filament::input.select wire:model.live="bank">
                @foreach ($this->bankOptions() as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper></label>
            <label class="md:col-span-2">Statement<x-filament::input.wrapper><x-filament::input.select wire:model.live="statement">
                <option value="">Choose a statement…</option>
                @foreach ($this->statementOptions() as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper></label>
        </div>
    </x-filament::section>
    @if ($s = $this->summary())
        <x-filament::section heading="Summary">
            <dl class="grid gap-2 md:grid-cols-4 text-sm" data-test="rec-summary">
                <div><dt class="text-gray-500">Statement closing</dt><dd class="font-semibold">{{ \App\Analytics\ReportService::d2($s['statement_closing']) }}</dd></div>
                <div><dt class="text-gray-500">Deposits in transit</dt><dd>{{ \App\Analytics\ReportService::d2($s['deposits_in_transit']) }}</dd></div>
                <div><dt class="text-gray-500">Outstanding payments</dt><dd>{{ \App\Analytics\ReportService::d2($s['outstanding_payments']) }}</dd></div>
                <div><dt class="text-gray-500">Adjusted bank</dt><dd>{{ \App\Analytics\ReportService::d2($s['adjusted_bank']) }}</dd></div>
                <div><dt class="text-gray-500">Book balance (GL)</dt><dd>{{ \App\Analytics\ReportService::d2($s['book_balance']) }}</dd></div>
                <div><dt class="text-gray-500">Difference</dt><dd class="font-semibold {{ \App\Services\Money::of($s['difference'])->isZero() ? 'text-success-600' : 'text-danger-600' }}">{{ \App\Analytics\ReportService::d2($s['difference']) }}</dd></div>
                <div><dt class="text-gray-500">Unmatched lines</dt><dd>{{ $s['unmatched_lines'] }}</dd></div>
                <div><dt class="text-gray-500">Opening + lines = closing</dt><dd>{{ $s['statement_math_ok'] ? 'Yes' : 'No' }}</dd></div>
            </dl>
        </x-filament::section>
    @else
        <x-filament::section><p class="text-sm text-gray-500">Import a CSV bank statement to start. GL entries on the bank's account are matched by amount within {{ \App\Accounting\BankReconService::MATCH_DAYS }} days.</p></x-filament::section>
    @endif
    {{ $this->table }}
</x-filament-panels::page>

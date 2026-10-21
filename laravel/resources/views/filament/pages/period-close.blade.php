<x-filament-panels::page>
    <x-filament::section>Closing a month blocks every posting dated in it (invoices, payments, receipts, vendor bills, bank adjustments, manual entries). Reversals of old entries post on today's date. Only an owner/admin can close or reopen; every change is logged below.</x-filament::section>
    {{ $this->table }}
    <x-filament::section heading="Audit log">
        <table class="w-full text-sm" data-test="period-audit">
            <thead><tr class="border-b"><th class="text-left px-2">When</th><th class="text-left px-2">Period</th><th class="text-left px-2">Action</th><th class="text-left px-2">By</th><th class="text-left px-2">Reason</th></tr></thead>
            <tbody>
            @forelse ($this->audits() as $a)
                <tr class="border-b border-gray-100"><td class="px-2">{{ $a->created_at }}</td><td class="px-2">{{ $a->period->period }}</td><td class="px-2">{{ $a->action }}</td><td class="px-2">{{ $a->user?->name }}</td><td class="px-2">{{ $a->reason }}</td></tr>
            @empty
                <tr><td colspan="5" class="px-2 py-2 text-gray-500">No closes or reopens yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>

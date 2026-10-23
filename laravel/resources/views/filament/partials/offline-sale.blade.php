{{-- Issue #26: one offline sale as the device sent it. --}}
<div class="space-y-3 text-sm">
    <dl class="grid grid-cols-2 gap-x-4 gap-y-1">
        <dt class="text-gray-500">Sold</dt><dd>{{ $row->sold_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') ?? '—' }}</dd>
        <dt class="text-gray-500">Synced</dt><dd>{{ $row->synced_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }} @if($row->attempts > 1)({{ $row->attempts }} attempts)@endif</dd>
        <dt class="text-gray-500">Customer</dt><dd>{{ $row->customer?->name ?? '—' }}</dd>
        <dt class="text-gray-500">Cashier</dt><dd>{{ $row->user?->name ?? '—' }}</dd>
        <dt class="text-gray-500">Payment</dt><dd>{{ ucfirst((string) $row->tender) }} · ${{ number_format((float) $row->total, 2) }}</dd>
        <dt class="text-gray-500">Device</dt><dd class="truncate">{{ $row->device_id ?? '—' }}</dd>
        <dt class="text-gray-500">Sale ID</dt><dd class="font-mono text-xs">{{ $row->uuid }}</dd>
    </dl>
    <table class="w-full">
        <thead><tr class="text-left text-gray-500"><th class="py-1">Item</th><th class="py-1 text-right">Qty</th><th class="py-1 text-right">Price</th><th class="py-1 text-right">Total</th></tr></thead>
        <tbody>
        @foreach($lines as $l)
            <tr class="border-t border-gray-100"><td class="py-1">{{ $l['name'] }} <span class="text-gray-400">{{ $l['sku'] }}</span></td>
                <td class="py-1 text-right">{{ $l['qty'] }}</td><td class="py-1 text-right">${{ $l['price'] }}</td><td class="py-1 text-right">${{ $l['total'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
    @if($row->problems)<div class="rounded bg-red-50 p-2 text-red-700"><strong>Why it needs review:</strong> {{ implode(' ', $row->problems) }}</div>@endif
    @if($row->warnings)<div class="rounded bg-amber-50 p-2 text-amber-800"><strong>Notes:</strong> {{ implode(' ', $row->warnings) }}</div>@endif
</div>

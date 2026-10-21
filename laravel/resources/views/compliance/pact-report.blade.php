<!doctype html><html><head><meta charset="utf-8"><title>PACT Act report {{ $report->state }} {{ $report->period }}</title>@include('warehouse.print-style')
<style>body{font-size:9.5px}td,th{padding:3px}</style></head><body>
@unless($pdf)<div class="noprint"><button onclick="window.print()">Print</button> <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">PDF</a> · <a href="{{ request()->fullUrlWithQuery(['format' => 'csv']) }}">CSV</a></div>@endunless
<h1>Jenkins / PACT Act report of interstate shipments — {{ $report->state }} — {{ \Carbon\Carbon::parse($report->period.'-01')->format('F Y') }}</h1>
<p>Filed under 15 U.S.C. § 376(a)(2) with the {{ $report->state }} tobacco tax administrator{{ $reg?->administrator ? ' ('.$reg->administrator.')' : '' }}. Due {{ $report->due_on->toDateString() }} (10th day of the following month).
Status: <strong>{{ strtoupper($report->status) }}</strong>@if($report->filed_at) on {{ $report->filed_at->toDateString() }}@if($report->filed_reference) · ref {{ $report->filed_reference }}@endif @endif.</p>
<table class="meta"><tr><td><strong>Seller</strong> {{ $us?->legal_name ?? $company->name }}@if($us?->trade_name) d/b/a {{ $us->trade_name }}@endif</td><td><strong>Address</strong> {{ $us?->principal_address }}</td><td><strong>Phone</strong> {{ $us?->phone }}</td><td><strong>Email</strong> {{ $us?->email }}</td></tr>
<tr><td><strong>ATF registration</strong> {{ $us?->reference ?? 'NOT ON FILE' }}</td><td><strong>{{ $report->state }} registration</strong> {{ $reg?->reference ?? 'NOT ON FILE' }}</td><td colspan="2"><strong>Agent in {{ $report->state }}</strong> {{ $reg?->agent_name }} {{ $reg?->agent_address }} {{ $reg?->agent_phone }}</td></tr></table>
<table><thead><tr><th>City/Town</th><th>ZIP</th><th>Recipient</th><th>License</th><th>Address</th><th>Date</th><th>Invoice</th><th>Category</th><th>Brand</th><th>Description</th><th>Qty</th><th>Sticks/units</th><th>Delivered by (name, address, phone)</th><th>Tracking</th></tr></thead><tbody>
@php($city = null)
@forelse($lines as $l)
@if(mb_strtoupper((string) $l->city) !== $city)@php($city = mb_strtoupper((string) $l->city))<tr><th colspan="14">{{ $l->city ?: '(no city)' }}</th></tr>@endif
<tr><td>{{ $l->city }}</td><td>{{ $l->zip }}</td><td>{{ $l->customer }}</td><td>{{ $l->license }}</td><td>{{ $l->address }}</td><td>{{ $l->shipped_on->toDateString() }}</td><td>{{ $l->invoice }}</td>
<td>{{ \App\Compliance\PactCategory::LABELS[$l->category] ?? $l->category }}</td><td>{{ $l->brand }}</td><td>{{ $l->description }}</td><td>{{ rtrim(rtrim((string) $l->qty, '0'), '.') }} {{ $l->unit }}</td>
<td>{{ $l->sticks_or_units !== null ? rtrim(rtrim((string) $l->sticks_or_units, '0'), '.') : '' }}</td><td>{{ $l->carrier }}@if($l->carrier_address), {{ $l->carrier_address }}@endif @if($l->carrier_phone), {{ $l->carrier_phone }}@endif</td><td>{{ $l->tracking }}</td></tr>
@empty<tr><td colspan="14">No covered shipments into {{ $report->state }} this month (a "no shipments" report may still be required by the state).</td></tr>@endforelse
</tbody></table>
<h2>Totals</h2><p>@forelse($report->totals ?? [] as $cat => $q){{ \App\Compliance\PactCategory::LABELS[$cat] ?? $cat }}: {{ rtrim(rtrim((string) $q, '0'), '.') }} &nbsp; @empty none @endforelse</p>
<p>Also send copies to local / tribal tobacco tax administrators in {{ $report->state }} that levy their own tax (15 U.S.C. § 376(a)(3)). Information here is confidential to the recipient agencies (§ 376(c)).</p>
</body></html>

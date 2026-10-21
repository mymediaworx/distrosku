<!doctype html><html><head><meta charset="utf-8"><title>PACT Act registration statement {{ $reg->jurisdiction }}</title>@include('warehouse.print-style')</head><body>
@unless($pdf)<div class="noprint"><button onclick="window.print()">Print</button> <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">PDF</a></div>@endunless
<h1>PACT Act registration statement — {{ $reg->jurisdiction === 'US' ? 'U.S. Attorney General (ATF, Form 5070.1)' : $reg->jurisdiction.' tobacco tax administrator' }}</h1>
<p>Statement under 15 U.S.C. § 376(a)(1). This is the record kept in the ERP; file on the official ATF Form 5070.1 / the state's own form.</p>
<table>
<tr><th>Legal name</th><td>{{ $reg->legal_name }}</td></tr><tr><th>Trade name</th><td>{{ $reg->trade_name }}</td></tr>
<tr><th>Principal place of business</th><td>{{ $reg->principal_address }}</td></tr><tr><th>Phone</th><td>{{ $reg->phone }}</td></tr>
<tr><th>Other places of business (with phones)</th><td style="white-space:pre-line">{{ $reg->other_places }}</td></tr>
<tr><th>Principal email</th><td>{{ $reg->email }}</td></tr><tr><th>Websites</th><td>{{ $reg->websites }}</td></tr>
@if($reg->jurisdiction !== 'US')<tr><th>Agent in {{ $reg->jurisdiction }} authorised to accept service</th><td>{{ $reg->agent_name }}, {{ $reg->agent_address }}, {{ $reg->agent_phone }}</td></tr>
<tr><th>Tobacco tax administrator</th><td>{{ $reg->administrator }} {{ $reg->administrator_contact }}</td></tr>@endif
<tr><th>Filed on</th><td>{{ $reg->filed_on?->toDateString() }}</td></tr><tr><th>Reference</th><td>{{ $reg->reference }}</td></tr><tr><th>Expires</th><td>{{ $reg->expires_on?->toDateString() ?? '—' }}</td></tr>
</table>
</body></html>

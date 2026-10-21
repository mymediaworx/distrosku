<!doctype html><html><head><meta charset="utf-8"><title>Count sheet {{ $count->number }}</title>@include('warehouse.print-style')</head><body>
@unless($pdf)<div class="noprint"><button onclick="window.print()">Print</button> <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">PDF</a></div>@endunless
<h1>Count sheet {{ $count->number }}</h1>
<table class="meta"><tr><td><strong>Warehouse</strong> {{ $count->warehouse->code }}</td><td><strong>Type</strong> {{ $count->type }}@if($count->blind) (blind)@endif</td><td><strong>Status</strong> {{ $count->status }}</td><td><strong>Started</strong> {{ $count->started_at?->toDateString() }}</td></tr></table>
<table><thead><tr><th>Bin</th><th>SKU</th><th>UPC</th><th>Item</th><th>Lot</th><th>Expires</th>@if($showExpected)<th>Expected</th>@endif<th>Units</th><th>Counted</th></tr></thead><tbody>
@foreach($lines as $l)<tr><td>{{ $l->bin?->code ?? '—' }}</td><td>{{ $l->product->sku }}</td><td>{{ $l->product->upc }}</td><td>{{ $l->product->name }}</td>
<td>{{ $l->lot?->lot_number ?? ($l->product->track_lots ? 'unlotted' : '') }}</td><td>{{ $l->lot?->expires_on?->toDateString() }}</td>
@if($showExpected)<td>{{ rtrim(rtrim((string) $l->expected, '0'), '.') }}</td>@endif
<td>{{ $l->product->units->sortByDesc('factor')->map(fn ($u) => $u->code.'='.(float) $u->factor)->implode(' ') ?: $l->product->base_unit_name }}</td>
<td style="width:90px">{{ $l->counted !== null ? (float) $l->counted : '' }}</td></tr>@endforeach
</tbody></table>
<p>Counted by: ____________________ &nbsp; Date: __________ &nbsp; Checked by: ____________________</p>
</body></html>

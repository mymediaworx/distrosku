<!doctype html>
<html><head><meta charset="utf-8"><title>Pick list {{ $pl->number }}</title>@include('warehouse.print-style')</head><body>
@unless($pdf)<div class="noprint"><button onclick="window.print()">Print</button> <a href="?format=pdf">PDF</a></div>@endunless
<h1>{{ $company->name ?: config('erp.company') }} · Pick list {{ $pl->number }}</h1>
<table class="meta"><tr><td><strong>Order</strong> {{ $so->number }}</td><td><strong>Date</strong> {{ $so->order_date?->toDateString() }}</td><td><strong>Warehouse</strong> {{ $so->warehouse->code }}</td><td><strong>Status</strong> {{ $pl->status }}</td></tr>
<tr><td colspan="4"><strong>Customer</strong> {{ $so->customer->code }} {{ $so->customer->name }} · @if($so->isPickup())<strong>Counter pickup</strong>@else<strong>Ship to</strong> {{ $so->shipTo?->name }} {{ $so->shipTo?->address }} {{ $so->shipTo?->city }} {{ $so->shipTo?->state }}@endif</td></tr></table>
<table><thead><tr><th>Bin</th><th>SKU</th><th>UPC</th><th>Item</th><th>Qty</th><th>In units</th><th>Lot (FEFO) / exp</th><th>Picked</th><th>✓</th></tr></thead><tbody>
@foreach($lines as $l)<tr><td>{{ $l->bin?->code ?? '—' }}</td><td>{{ $l->product->sku }}</td><td>{{ $l->product->upc }}</td><td>{{ $l->product->name }}</td><td>{{ rtrim(rtrim((string) $l->qty, '0'), '.') }}</td><td>{{ \App\Warehouse\Uom::describe($l->product, $l->qty) }}</td><td>@foreach($l->lots as $a){{ $a->lot->lot_number }} · {{ $a->lot->expires_on?->toDateString() ?? '—' }} × {{ (float) $a->qty_allocated }}@if(! $loop->last)<br>@endif @endforeach</td><td>{{ (float) $l->qty_picked ?: '' }}</td><td><span class="box"></span></td></tr>@endforeach
</tbody></table>
<p>Picked by: ____________________ &nbsp; Date/time: ______________ &nbsp; Lines: {{ $lines->count() }}</p>
</body></html>

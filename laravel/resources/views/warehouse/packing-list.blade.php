<!doctype html>
<html><head><meta charset="utf-8"><title>Packing list {{ $so->number }}</title>@include('warehouse.print-style')</head><body>
@unless($pdf)<div class="noprint"><button onclick="window.print()">Print</button> <a href="?format=pdf">PDF</a></div>@endunless
<h1>{{ $company->name ?: config('erp.company') }} · Packing list</h1>
<table class="meta"><tr><td><strong>Order</strong> {{ $so->number }}</td><td><strong>Order date</strong> {{ $so->order_date?->toDateString() }}</td><td><strong>Pick</strong> {{ $pl->number }}</td><td><strong>Cartons</strong> {{ $packages->count() }}</td></tr>
<tr><td colspan="4">@if($so->isPickup())<strong>Counter pickup</strong> {{ $so->customer->name }}@else<strong>Ship to</strong> {{ $so->customer->name }} · {{ $so->shipTo?->name }} {{ $so->shipTo?->address }} {{ $so->shipTo?->city }} {{ $so->shipTo?->state }} {{ $so->shipTo?->zip }}@endif</td></tr></table>
<h2>Summary</h2>
<table><thead><tr><th>SKU</th><th>UPC</th><th>Item</th><th>Ordered</th><th>Shipped</th></tr></thead><tbody>
@foreach($lines as $l)<tr><td>{{ $l->product->sku }}</td><td>{{ $l->product->upc }}</td><td>{{ $l->product->name }}</td><td>{{ (float) $l->qty }}</td><td>{{ (float) ($l->qty_packed > 0 ? $l->qty_packed : $l->qty_picked) }}</td></tr>@endforeach
</tbody></table>
@foreach($packages as $p)
<h2>Carton {{ $p->seq }} of {{ $packages->count() }} @if($p->weight_lb)· {{ $p->weight_lb }} lb @endif</h2>
<table><thead><tr><th>SKU</th><th>Item</th><th>Qty</th></tr></thead><tbody>
@foreach($p->lines as $pkl)<tr><td>{{ $pkl->pickLine->product->sku }}</td><td>{{ $pkl->pickLine->product->name }}</td><td>{{ (float) $pkl->qty }}</td></tr>@endforeach
</tbody></table>
@endforeach
<p>Packed by: ____________________ &nbsp; Received by: ____________________</p>
</body></html>

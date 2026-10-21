<!doctype html><html><head><meta charset="utf-8"></head><body>
@include('docs._head', ['docTitle' => 'DELIVERY RECEIPT', 'docSub' => $so->number])
<table style="border:0;margin:10px 0"><tr>
    <td style="border:0;width:50%" class="box"><strong>Customer</strong><br>{{ $so->customer->name }}<br>{{ $so->customer->code }} · Terms {{ $so->customer->terms }}</td>
    <td style="border:0;width:50%" class="box">@if($so->isPickup())<strong>Fulfillment</strong><br>Counter pickup @else<strong>Delivered to</strong><br>{{ $so->shipTo?->name }}<br>{{ $so->shipTo?->address }} {{ $so->shipTo?->city }} {{ $so->shipTo?->state }} {{ $so->shipTo?->zip }}@endif</td>
</tr></table>
@foreach($so->deliveryStops as $st)
<p>Manifest {{ $st->manifest?->number }} · stop {{ $st->sequence }} · <strong>{{ strtoupper($st->status) }}</strong>@if($st->delivered_at) · {{ $st->delivered_at->timezone(config('app.timezone'))->format('m/d/Y g:i A T') }}@endif @if($st->manifest?->driver) · driver {{ $st->manifest->driver->name }}@endif
    @if($st->notes)<br>Notes: {{ $st->notes }}@endif</p>
@endforeach
<table>
    <tr><th>SKU</th><th>Description</th><th class="r">Qty</th><th class="r">Amount</th></tr>
    @foreach($so->lines as $l)<tr><td>{{ $l->product?->sku }}</td><td>{{ $l->product?->name }}</td><td class="r">{{ rtrim(rtrim(number_format((float) $l->qty, 4, '.', ''), '0'), '.') }}</td><td class="r">{{ number_format((float) $l->line_total, 2) }}</td></tr>@endforeach
    <tr class="tot"><td colspan="3" class="r">Total</td><td class="r">${{ number_format((float) $so->total, 2) }}</td></tr>
</table>
@include('docs._signatures')
@foreach($photos as $ph)<p style="margin-top:10px"><strong>Photo proof</strong><br><img src="{{ $ph }}" style="max-width:320px;max-height:240px"></p>@endforeach
</body></html>

<!doctype html><html><head><meta charset="utf-8"></head><body>
@include('docs._head', ['docTitle' => 'INVOICE', 'docSub' => $so->number])
<table style="border:0;margin:10px 0"><tr>
    <td style="border:0;width:50%" class="box"><strong>Bill to</strong><br>{{ $so->customer->name }}<br>{{ $so->customer->code }}</td>
    <td style="border:0;width:50%" class="box"><strong>Ship to</strong><br>{{ $so->shipTo?->name }}<br>{{ $so->shipTo?->address }} {{ $so->shipTo?->city }} {{ $so->shipTo?->state }} {{ $so->shipTo?->zip }}</td>
</tr></table>
<p>Invoice date: {{ ($so->invoiced_at ?? $so->order_date)?->format('m/d/Y') }} · Order date: {{ $so->order_date?->format('m/d/Y') }} · Terms: {{ $so->customer->terms }}</p>
<table>
    <tr><th>SKU</th><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr>
    @foreach($so->lines as $l)
        <tr><td>{{ $l->product?->sku }}</td><td>{{ $l->product?->name }}</td><td class="r">{{ rtrim(rtrim(number_format((float) $l->qty, 4, '.', ''), '0'), '.') }}</td>
            <td class="r">{{ number_format((float) $l->unit_price, 2) }}</td><td class="r">{{ number_format((float) $l->line_total, 2) }}</td></tr>
    @endforeach
    <tr class="tot"><td colspan="4" class="r">Total</td><td class="r">${{ number_format((float) $so->total, 2) }}</td></tr>
    @if((float) $so->amount_credited)<tr><td colspan="4" class="r">Credits</td><td class="r">-{{ number_format((float) $so->amount_credited, 2) }}</td></tr>@endif
    <tr><td colspan="4" class="r">Paid</td><td class="r">-{{ number_format((float) $so->amount_paid, 2) }}</td></tr>
    <tr class="tot"><td colspan="4" class="r">Balance due</td><td class="r">${{ number_format((float) $so->balance(), 2) }}</td></tr>
</table>
@include('docs._signatures')
</body></html>

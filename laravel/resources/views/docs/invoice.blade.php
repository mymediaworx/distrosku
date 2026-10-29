<!doctype html><html><head><meta charset="utf-8"></head><body>
@if(! empty($screen))<p style="text-align:right" data-test="invoice-screen"><a href="{{ route('docs.invoice', $so) }}">Download PDF</a> · <a href="#" onclick="window.print();return false">Print</a></p>@endif
@include('docs._head', ['docTitle' => 'INVOICE', 'docSub' => $so->number])
<table style="border:0;margin:10px 0"><tr>
    <td style="border:0;width:50%" class="box"><strong>Bill to</strong><br>{{ $so->customer->name }}@if(filled($so->customer->dba))<br><span style="font-size:0.95em">DBA: {{ $so->customer->dba }}</span>@endif<br>{{ $so->customer->code }}</td>
    <td style="border:0;width:50%" class="box">@if($so->isPickup())<strong>Fulfillment</strong><br>Counter pickup @else<strong>Ship to</strong><br>{{ $so->shipTo?->name }}<br>{{ $so->shipTo?->address }} {{ $so->shipTo?->city }} {{ $so->shipTo?->state }} {{ $so->shipTo?->zip }}@endif</td>
</tr></table>
<p>Invoice date: {{ ($so->invoiced_at ?? $so->order_date)?->format('m/d/Y') }} · Order date: {{ $so->order_date?->format('m/d/Y') }} · Terms: {{ $so->customer->terms }}</p>
@php($tx = \App\Services\InvoiceTax::summary($so))
<table data-test="invoice-lines">
    <tr><th>SKU</th><th>Description</th><th class="r">Qty</th><th class="r">Unit cost</th><th class="r">Tax / unit</th><th class="r">Line total</th></tr>
    @foreach($so->lines as $l)
        <tr><td>{{ $l->product?->sku }}</td><td>{{ $l->product?->name }}@if(! empty($l->tax_detail))<br><span style="font-size:0.85em;color:#555">@foreach($l->tax_detail as $d){{ $d['name'] }} {{ \App\Services\InvoiceTax::fmt($d['per_unit']) }}@if(! $loop->last) · @endif @endforeach</span>@endif</td>
            <td class="r">{{ rtrim(rtrim(number_format((float) $l->qty, 4, '.', ''), '0'), '.') }}</td>
            <td class="r">{{ number_format((float) $l->unit_price, 2) }}</td><td class="r">{{ \App\Services\InvoiceTax::fmt($l->tax_per_unit) }}</td>
            <td class="r">{{ number_format((float) \App\Services\InvoiceTax::lineWithTax($l), 2) }}</td></tr>
    @endforeach
    <tr><td colspan="5" class="r">Subtotal (before tax)</td><td class="r">{{ number_format((float) $tx['subtotal'], 2) }}</td></tr>
    @foreach($tx['by_name'] as $name => $amt)<tr data-test="tax-row"><td colspan="5" class="r">{{ $name }}</td><td class="r">{{ number_format((float) $amt, 2) }}</td></tr>@endforeach
    @if(count($tx['by_name']) > 1 || (float) $tx['tax_total'] > 0)<tr><td colspan="5" class="r">Total tax</td><td class="r">{{ number_format((float) $tx['tax_total'], 2) }}</td></tr>@endif
    <tr class="tot"><td colspan="5" class="r">Total</td><td class="r">${{ number_format((float) $so->total, 2) }}</td></tr>
    @if((float) $so->amount_credited)<tr><td colspan="5" class="r">Credits</td><td class="r">-{{ number_format((float) $so->amount_credited, 2) }}</td></tr>@endif
    <tr><td colspan="5" class="r">Paid</td><td class="r">-{{ number_format((float) $so->amount_paid, 2) }}</td></tr>
    <tr class="tot"><td colspan="5" class="r">Balance due</td><td class="r">${{ number_format((float) $so->balance(), 2) }}</td></tr>
</table>
@include('docs._signatures')
</body></html>

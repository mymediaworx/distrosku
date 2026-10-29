<!doctype html><html><head><meta charset="utf-8"></head><body>
@include('docs._head', ['docTitle' => 'STATEMENT', 'docSub' => now()->format('m/d/Y')])
<p class="box"><strong>{{ $c->name }}</strong>@if(filled($c->dba))<br>DBA: {{ $c->dba }}@endif ({{ $c->code }}) · Terms: {{ $c->terms }}</p>
<h3>Open items</h3>
<table>
    <tr><th>Date</th><th>Reference</th><th class="r">Amount</th><th class="r">Open</th><th class="r">Days</th></tr>
    @if((float) $opening != 0)
        <tr><td>{{ $c->beginning_balance_date?->format('m/d/Y') }}</td><td>Beginning balance</td><td class="r">{{ number_format((float) $c->beginning_balance, 2) }}</td>
            <td class="r">{{ number_format((float) $opening, 2) }}</td><td class="r">{{ $c->beginning_balance_date ? (int) $c->beginning_balance_date->diffInDays(now()) : '' }}</td></tr>
    @endif
    @foreach($invoices as $so)
        @php($d = $so->invoiced_at ?? $so->created_at)
        <tr><td>{{ $d?->format('m/d/Y') }}</td><td>Invoice {{ $so->number }}</td><td class="r">{{ number_format((float) $so->total, 2) }}</td>
            <td class="r">{{ number_format((float) $so->balance(), 2) }}</td><td class="r">{{ $d ? (int) $d->diffInDays(now()) : '' }}</td></tr>
    @endforeach
    <tr class="tot"><td colspan="3" class="r">Balance due</td><td class="r">${{ number_format((float) $aging['total'], 2) }}</td><td></td></tr>
</table>
<h3>Aging</h3>
<table><tr><th class="r">Current (0-30)</th><th class="r">31-60</th><th class="r">61-90</th><th class="r">Over 90</th><th class="r">Total</th></tr>
    <tr>@foreach(['0-30', '31-60', '61-90', '90+', 'total'] as $k)<td class="r">{{ number_format((float) $aging[$k], 2) }}</td>@endforeach</tr></table>
@if($payments->count())
    <h3>Payments (last 90 days)</h3>
    <table><tr><th>Date</th><th>Method</th><th>Reference</th><th class="r">Amount</th></tr>
        @foreach($payments as $p)<tr><td>{{ $p->paid_on?->format('m/d/Y') }}</td><td>{{ $p->method }}</td><td>{{ $p->reference }}</td><td class="r">{{ number_format((float) $p->amount, 2) }}</td></tr>@endforeach</table>
@endif
</body></html>

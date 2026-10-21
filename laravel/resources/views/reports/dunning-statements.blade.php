<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:10px}h1{font-size:15px;margin:0}h2{font-size:12px;margin:6px 0}
table{border-collapse:collapse;width:100%;margin-top:8px}td,th{border:1px solid #999;padding:2px 4px;text-align:left}tfoot td{font-weight:bold;background:#eee}
.page{page-break-after:always}.page:last-child{page-break-after:auto}.box{border:1px solid #c00;padding:6px;margin-top:8px}
</style></head><body>
@forelse($items as $it)
<div class="page">
<h1>{{ $company }} · Statement</h1>
<h2>{{ $it['notice']->customer->code }} {{ $it['notice']->customer->name }}</h2>
<p>{{ $it['r']['period'] }} · as of {{ $batch->as_of->toDateString() }}</p>
<div class="box"><strong>{{ $it['notice']->subject }}</strong><br>Past due: ${{ number_format((float) $it['notice']->overdue_amount, 2) }} · Account balance: ${{ number_format((float) $it['notice']->balance, 2) }}</div>
<table><thead><tr>@foreach($it['r']['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
<tbody>@foreach($it['r']['rows'] as $row)<tr>@foreach($row as $c)<td>{{ $c }}</td>@endforeach</tr>@endforeach</tbody>
<tfoot><tr>@foreach($it['r']['totals'] as $c)<td>{{ $c }}</td>@endforeach</tr></tfoot></table>
<h2>Past-due items</h2>
<table><thead><tr><th>Item</th><th>Date</th><th>Due</th><th>Days late</th><th>Open</th></tr></thead>
<tbody>@foreach($it['notice']->invoices ?? [] as $i)<tr><td>{{ $i['label'] }}</td><td>{{ $i['date'] }}</td><td>{{ $i['due'] }}</td><td>{{ $i['days'] }}</td><td>{{ number_format((float) $i['open'], 2) }}</td></tr>@endforeach</tbody></table>
</div>
@empty
<p>No notices in this batch.</p>
@endforelse
</body></html>

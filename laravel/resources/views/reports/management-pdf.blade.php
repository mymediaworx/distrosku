<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:9px}h1{font-size:14px;margin:0}
table{border-collapse:collapse;width:100%;margin-top:8px}td,th{border:1px solid #999;padding:2px 3px;text-align:left}tfoot td{font-weight:bold;background:#eee}
</style></head><body>
<h1>{{ $company }} · {{ $r['title'] }}</h1>
<p>{{ $r['period'] }} · generated {{ now()->format('Y-m-d H:i') }}</p>
<table><thead><tr>@foreach($r['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
<tbody>@forelse($r['rows'] as $row)<tr>@foreach($row as $c)<td>{{ $c }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($r['columns']) }}">No data for this period.</td></tr>@endforelse</tbody>
@if($r['totals'])<tfoot><tr>@foreach($r['totals'] as $c)<td>{{ $c }}</td>@endforeach</tr></tfoot>@endif
</table>
@if(!empty($r['notes']))<ul>@foreach($r['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>@endif
</body></html>

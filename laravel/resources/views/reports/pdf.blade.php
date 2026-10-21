<!doctype html>
<html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:9px}h1{font-size:14px;margin:0}h2{font-size:11px;margin:12px 0 4px}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #999;padding:2px 3px;text-align:left}.r{text-align:right}.draft{color:#b00;font-weight:bold}
</style></head><body>
<h1>{{ $company }} · {{ $r['title'] }}</h1>
<p>{{ $r['form'] }} · Period {{ $r['period_start'] }} to {{ $r['period_end'] }} · <span class="draft">WORKING COPY - file through KY MyTaxes</span></p>
<table><tr><th>Line</th><th>Description</th><th class="r">Value</th></tr>
@foreach($r['summary'] as [$l, $d, $v])<tr><td>{{ $l }}</td><td>{{ $d }}</td><td class="r">{{ $v }}</td></tr>@endforeach
</table>
<p><strong>Tax due: ${{ $r['tax_due'] }}</strong></p>
@foreach($r['sections'] as $s)
<h2>{{ $s['title'] }}</h2>
<table><tr>@foreach($s['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr>
@forelse($s['rows'] as $row)<tr>@foreach($row as $c)<td>{{ $c }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($s['columns']) }}">No transactions.</td></tr>@endforelse
</table>
@endforeach
<h2>Notes / assumptions</h2><ul>@foreach($r['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>
</body></html>

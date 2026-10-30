<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{{ $s->isOpen() ? 'X-report' : 'Z-report' }} · {{ $s->register }}</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:420px;margin:1rem auto;padding:0 1rem;color:#111;background:#fff}h1{font-size:1.3rem;margin:.2rem 0}table{width:100%;border-collapse:collapse;margin:.5rem 0}td{padding:.3rem 0;border-bottom:1px dashed #ccc}.r{text-align:right}.tot td{font-weight:700;border-top:2px solid #111;border-bottom:0}.muted{color:#555;font-size:.9rem}@media print{a{display:none}}</style></head>
<body data-test="till-report">
<h1>{{ $s->isOpen() ? 'X-report (till still open)' : 'Z-report' }}</h1>
<p class="muted">{{ \App\Models\Company::current()->name }} · register {{ $s->register }}<br>
Opened {{ $s->opened_at->format('m/d/Y g:i A') }} by {{ $s->openedBy?->name }}@if($s->closed_at)<br>Closed {{ $s->closed_at->format('m/d/Y g:i A') }} by {{ $s->closedBy?->name }}@endif</p>
<h2 style="font-size:1rem">Sales</h2>
<table><tr><td>POS sales</td><td class="r">{{ $r['sales_count'] }}</td><td class="r">${{ number_format((float) $r['sales_total'], 2) }}</td></tr>
@foreach($r['by_tender'] as $t => $v)<tr><td>{{ ['cash' => 'Cash', 'check' => 'Check', 'card' => 'Card', 'ach' => 'ACH', 'account' => 'On account'][$t] ?? ucfirst($t) }}</td><td class="r">{{ $v['count'] }}</td><td class="r">${{ number_format((float) $v['amount'], 2) }}</td></tr>@endforeach
</table>
<h2 style="font-size:1rem">Drawer</h2>
<table>
<tr><td>Starting cash (float)</td><td class="r">${{ number_format((float) $r['float'], 2) }}</td></tr>
<tr><td>+ Cash sales</td><td class="r">${{ number_format((float) $r['cash_sales'], 2) }}</td></tr>
<tr><td>+ Paid in</td><td class="r">${{ number_format((float) $r['totals']['paid_in'], 2) }}</td></tr>
<tr><td>− Paid out</td><td class="r">${{ number_format((float) $r['totals']['paid_out'], 2) }}</td></tr>
<tr><td>− Safe drops</td><td class="r">${{ number_format((float) $r['totals']['drop'], 2) }}</td></tr>
<tr class="tot"><td>Expected in drawer</td><td class="r" data-test="z-expected">${{ number_format((float) $r['expected'], 2) }}</td></tr>
@unless($s->isOpen())
<tr><td>Counted</td><td class="r">${{ number_format((float) $s->counted_cash, 2) }}</td></tr>
<tr class="tot"><td>{{ (float) $s->variance < 0 ? 'Short' : ((float) $s->variance > 0 ? 'Over' : 'Balanced') }}</td><td class="r" data-test="z-variance">${{ number_format(abs((float) $s->variance), 2) }}</td></tr>
@endunless
</table>
@if($r['movements']->isNotEmpty())
<h2 style="font-size:1rem">Cash in / out</h2>
<table>@foreach($r['movements'] as $m)<tr><td>{{ $m->created_at->format('g:i A') }} {{ \App\Models\TillMovement::TYPES[$m->type] ?? $m->type }}<br><span class="muted">{{ $m->reason }} · {{ $m->user?->name }}</span></td><td class="r">${{ number_format((float) $m->amount, 2) }}</td></tr>@endforeach</table>
@endif
@if($s->notes)<p class="muted">Note: {{ $s->notes }}</p>@endif
<p><a href="javascript:window.print()">Print</a></p>
</body></html>

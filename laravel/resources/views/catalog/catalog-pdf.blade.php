<html><head><style>body{font-family:DejaVu Sans,sans-serif;font-size:9pt}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #ddd;padding:3pt;text-align:left;vertical-align:middle}th{background:#f2f2f2}.t{width:44pt;height:44pt;text-align:center}.t img{max-width:42pt;max-height:42pt}h1{font-size:14pt}</style></head><body>
<h1>{{ $company->name }} catalog ({{ now()->format('Y-m-d') }})</h1>
<table><thead><tr><th class="t">Image</th><th>SKU</th><th>Name</th><th>Department</th><th>UPC</th><th>Price</th></tr></thead><tbody>
@foreach($rows as $r)<tr><td class="t">@if($r['img'])<img src="{{ $r['img'] }}">@endif</td><td>{{ $r['p']->sku }}</td><td>{{ $r['p']->name }}</td><td>{{ $r['p']->department?->name }}</td><td>{{ $r['p']->upc }}</td><td>${{ number_format((float)$r['p']->price,2) }}</td></tr>@endforeach
</tbody></table></body></html>

<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SKU labels</title>
<style>
body{font-family:Arial,sans-serif;margin:0;padding:1rem}
.bar{margin-bottom:1rem;display:flex;gap:.5rem}.bar a,.bar button{padding:.6rem 1rem;font-size:1rem}
.labels{display:flex;flex-wrap:wrap;gap:6px}
.label{width:2.25in;height:1.25in;box-sizing:border-box;border:1px dashed #bbb;padding:4px 6px;text-align:center;overflow:hidden;page-break-inside:avoid}
.label .n{font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.label .c{font:bold 12px monospace}.label .p{font-size:11px}
.label svg{max-width:100%;height:44px}
@media print{.bar{display:none}body{padding:0}.label{border:none}@page{margin:.25in}}
</style></head><body>
<div class="bar"><button onclick="window.print()">Print</button><a href="{{ $pdfUrl }}">PDF</a></div>
<div class="labels">
@foreach($products as $p)@for($i=0;$i<$copies;$i++)
<div class="label"><div class="n">{{ $p->name }}</div>{!! \App\Catalog\Barcode::svg($p->barcodeValue(), 2, 44) !!}<div class="c">{{ $p->sku }}</div><div class="p">${{ number_format((float)$p->price,2) }}@if($p->upc) · UPC {{ $p->upc }}@endif</div></div>
@endfor @endforeach
</div></body></html>

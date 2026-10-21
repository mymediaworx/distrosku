<html><head><style>@page{margin:4pt}body{font-family:DejaVu Sans,sans-serif;margin:0}.l{text-align:center;page-break-after:always;height:80pt}.l:last-child{page-break-after:auto}.n{font-size:7pt;height:9pt;overflow:hidden}.c{font-size:8pt;font-weight:bold}.p{font-size:7pt}img{height:36pt;width:140pt}</style></head><body>
@foreach($products as $p)@for($i=0;$i<$copies;$i++)
<div class="l"><div class="n">{{ \Illuminate\Support\Str::limit($p->name, 38) }}</div><img src="{{ \App\Catalog\Barcode::pngDataUri($p->barcodeValue(), 2, 40) }}"><div class="c">{{ $p->sku }}</div><div class="p">${{ number_format((float)$p->price,2) }}@if($p->upc) UPC {{ $p->upc }}@endif</div></div>
@endfor @endforeach

</body></html>

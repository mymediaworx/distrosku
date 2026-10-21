<!doctype html><html><head><meta charset="utf-8"><title>Bin labels</title>
<style>
body{font-family:DejaVu Sans,Arial,sans-serif;margin:10px;color:#000}
.l{display:inline-block;width:3.1in;height:1.2in;border:1px dashed #999;margin:3px;padding:6px;text-align:center;vertical-align:top;overflow:hidden;box-sizing:border-box}
.c{font-size:20px;font-weight:bold;letter-spacing:1px}.w{font-size:9px;color:#333}
.noprint{margin-bottom:8px}@media print{.noprint{display:none}.l{border:0}}
</style></head><body>
@unless($pdf)<div class="noprint"><button onclick="window.print()">Print</button> <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">PDF</a> · {{ $bins->count() }} label(s)</div>@endunless
@foreach($bins as $b)<div class="l">
@if($pdf)<img src="{{ \App\Catalog\Barcode::pngDataUri($b->code, 2, 45) }}">@else{!! \App\Catalog\Barcode::svg($b->code, 2, 45) !!}@endif
<div class="c">{{ $b->code }}</div><div class="w">{{ $b->warehouse?->code }}@if($b->zone) · zone {{ $b->zone }}@endif</div>
</div>@endforeach
</body></html>

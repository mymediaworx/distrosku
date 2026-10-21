<!doctype html><html><head><meta charset="utf-8"><title>Label {{ $so->number }} carton {{ $pkg->seq }}</title>
<style>
@page{size:4in 6in;margin:0}body{font-family:DejaVu Sans,Arial,sans-serif;margin:0;color:#000}
.lbl{width:3.8in;height:5.8in;padding:.1in;box-sizing:border-box;border:1px solid #000}
.sec{border-bottom:2px solid #000;padding:4px 0}.sm{font-size:9px}.big{font-size:15px;font-weight:bold}.c{text-align:center}
.noprint{margin:6px}@media print{.noprint{display:none}}
</style></head><body>
@unless($pdf)<div class="noprint"><button onclick="window.print()">Print</button> <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">PDF (4x6)</a>@if($url) · <a href="{{ $url }}" target="_blank" rel="noopener">Track</a>@endif</div>@endunless
<div class="lbl">
<div class="sec sm"><strong>FROM</strong><br>{{ $company->name }}<br>{{ $so->warehouse->name }}</div>
<div class="sec"><span class="sm"><strong>SHIP TO</strong></span><br><span class="big">{{ $so->shipTo->name ?: $so->customer->name }}</span><br>{{ $so->shipTo->address }}<br>
<span class="big">{{ $so->shipTo->city }}, {{ $so->shipTo->state }} {{ $so->shipTo->zip }}</span>@if($so->customer->phone)<br><span class="sm">{{ $so->customer->phone }}</span>@endif</div>
<div class="sec"><span class="big">{{ $pkg->carrier?->name ?? 'CARRIER: ________' }}</span> {{ $pkg->service }}<br>
<span class="sm">Order {{ $so->number }} · Carton {{ $pkg->seq }} of {{ $count }}@if($pkg->weight_lb) · {{ (float) $pkg->weight_lb }} lb @endif</span></div>
<div class="sec c">@if($pkg->tracking_number)
@if($pdf)<img src="{{ \App\Catalog\Barcode::pngDataUri($pkg->tracking_number, 2, 60) }}">@else{!! \App\Catalog\Barcode::svg($pkg->tracking_number, 2, 60) !!}@endif
<div class="big">{{ $pkg->tracking_number }}</div>@else<div class="sm">No tracking number</div>@endif</div>
<div class="c" style="padding-top:4px">@if($pdf)<img src="{{ \App\Catalog\Barcode::pngDataUri($so->number.'-'.$pkg->seq, 1, 35) }}">@else{!! \App\Catalog\Barcode::svg($so->number.'-'.$pkg->seq, 1, 35) !!}@endif<div class="sm">{{ $so->number }}-{{ $pkg->seq }}</div></div>
</div></body></html>

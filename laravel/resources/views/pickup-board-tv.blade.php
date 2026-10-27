@php
    use App\Sales\PickupBoard as B;
    $ageBg = ['gray' => '#f3f4f6', 'amber' => '#fef3c7', 'red' => '#fee2e2'];
    $ageFg = ['gray' => '#374151', 'amber' => '#92400e', 'red' => '#991b1b'];
    $head = ['to_pick' => '#6b7280', 'picking' => '#2563eb', 'ready' => '#d97706', 'done' => '#16a34a'];
@endphp
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<meta http-equiv="refresh" content="{{ $refresh }}"><title>Counter pickup board</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#111827;color:#111827;font-family:system-ui,sans-serif;font-size:1.25vw}
.top{display:flex;justify-content:space-between;align-items:center;color:#f9fafb;padding:.8vw 1.5vw}.top h1{margin:0;font-size:2.2em}
.cols{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1vw;padding:0 1.5vw 1.5vw}
.col{background:#f9fafb;border-radius:.8vw;padding:.8vw;min-height:80vh}
.ch{display:flex;justify-content:space-between;align-items:center;padding-bottom:.4vw;margin-bottom:.8vw;font-size:1.3em;font-weight:700}
.n{color:#fff;border-radius:999px;padding:0 .8vw}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:.6vw;padding:.7vw;margin-bottom:.7vw}
@media(max-width:900px){body{font-size:2.6vw}.cols{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style></head><body>
<div class="top"><h1>Counter pickup @if($warehouse)· {{ $warehouse }}@endif</h1>
    <div style="text-align:right"><div style="font-size:2em;font-weight:700" id="clk">{{ now()->format('g:i a') }}</div><div style="opacity:.7;font-size:.8em">Updates every {{ $refresh }} seconds</div></div></div>
<div class="cols">
@foreach(B::STAGES as $key => $label)
    <div class="col" data-stage="{{ $key }}">
        <div class="ch" style="border-bottom:.35vw solid {{ $head[$key] }}"><span>{{ $label }}</span><span class="n" style="background:{{ $head[$key] }}">{{ $cols[$key]->count() }}</span></div>
        @forelse($cols[$key] as $o)
            @php($c = B::ageColor($o))
            <div class="card" style="border-left:.5vw solid {{ $c === 'gray' ? '#d1d5db' : $ageFg[$c] }}">
                <div style="display:flex;justify-content:space-between;gap:.5vw">
                    <strong>{{ $o->number }}</strong>
                    <span style="background:{{ $ageBg[$c] }};color:{{ $ageFg[$c] }};border-radius:.4vw;padding:0 .5vw;font-weight:600;white-space:nowrap">{{ $key === 'done' ? $o->delivered_at?->format('g:i a') : B::ageLabel($o) }}</span>
                </div>
                <div style="font-weight:600">{{ $o->customer?->name }}</div>
                <div style="color:#4b5563;font-size:.85em">{{ $o->lines->count() }} items · {{ B::units($o) }} units</div>
            </div>
        @empty
            <div style="color:#9ca3af;text-align:center;padding:2vw 0">Nothing here</div>
        @endforelse
    </div>
@endforeach
</div>
</body></html>

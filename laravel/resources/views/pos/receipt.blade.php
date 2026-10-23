@php
    $narrow = $format === '80mm';
    $methods = \App\Services\SalesOrderService::METHODS;
    $wh = $so->warehouse;
    $qty = fn ($v) => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v;
    $paid = \App\Services\Money::add($so->amount_paid ?? 0, $so->amount_credited ?? 0);
    $balance = $so->balance();
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Receipt {{ $so->number }}</title>
<style>
/* Issue #27: printed from Chrome. 80 mm thermal roll (72 mm printable) or US letter. */
@page { size: {{ $narrow ? '80mm 200mm' : 'letter' }}; margin: {{ $narrow ? '3mm' : '12mm' }}; } /* 80 mm: height is fitted to the receipt by the script below */
*{box-sizing:border-box}
body{margin:0;font-family:ui-monospace,"DejaVu Sans Mono",Menlo,Consolas,monospace;color:#000;background:#fff;
     font-size:{{ $narrow ? '12px' : '13px' }};line-height:1.35}
.r{width:{{ $narrow ? '72mm' : '100%' }};max-width:{{ $narrow ? '72mm' : '7.5in' }};margin:0 auto;padding:{{ $narrow ? '0' : '0 0 1rem' }}}
.c{text-align:center}.right{text-align:right}.b{font-weight:700}.sm{font-size:.9em}
.logo{max-width:{{ $narrow ? '48mm' : '2.2in' }};max-height:{{ $narrow ? '18mm' : '.8in' }};filter:grayscale(1)}
hr{border:0;border-top:1px dashed #000;margin:.45rem 0}
table{width:100%;border-collapse:collapse}td{vertical-align:top;padding:.08rem 0}
.item td{padding-top:.25rem}.sub td{padding:0 0 .15rem}.num{text-align:right;white-space:nowrap}
.tot td{padding:.1rem 0}.grand td{font-size:1.25em;font-weight:700;padding-top:.25rem}
.toolbar{display:flex;gap:.5rem;justify-content:center;padding:.75rem;font-family:system-ui,sans-serif}
.toolbar button,.toolbar a{font:600 15px system-ui,sans-serif;min-height:44px;padding:0 1rem;border-radius:8px;border:0;background:#1e3a8a;color:#fff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
.toolbar a{background:#e5e7eb;color:#111}
@media print { .toolbar{display:none} body{background:#fff} }
@media screen { body{background:#f4f5f7} .r{background:#fff;padding:{{ $narrow ? '4mm' : '12mm' }};margin:1rem auto;box-shadow:0 1px 4px #0002} }
</style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('pos.receipt', ['so' => $so, 'format' => $narrow ? 'letter' : '80mm'] + array_filter(['tendered' => $tendered])) }}">{{ $narrow ? 'Letter size' : '80 mm roll' }}</a>
</div>
<div class="r" data-test="receipt" data-format="{{ $format }}">
    <div class="c">
        @if($company?->logoUrl())<img class="logo" src="{{ $company->logoUrl() }}" alt=""><br>@endif
        <div class="b">{{ $company->name ?? config('app.name') }}</div>
        @if($wh)
            <div class="sm">{{ $wh->name }}</div>
            @if($wh->address || $wh->city)<div class="sm">{{ $wh->address }}@if($wh->address && $wh->city), @endif{{ $wh->city }}@if($wh->state) {{ $wh->state }}@endif</div>@endif
        @endif
    </div>
    <hr>
    <table class="sm">
        <tr><td>Sale</td><td class="num b">{{ $so->number }}</td></tr>
        <tr><td>Date</td><td class="num">{{ $so->created_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</td></tr>
        <tr><td>Customer</td><td class="num">{{ $so->customer?->name }}</td></tr>
        @if($so->isPickup())<tr><td>Fulfillment</td><td class="num">Counter pickup</td></tr>@else<tr><td>Fulfillment</td><td class="num">Delivery</td></tr>@endif
    </table>
    <hr>
    <table>
        @foreach($so->lines as $l)
            <tr class="item"><td colspan="2">{{ $l->product?->name }}</td></tr>
            <tr class="sub"><td class="sm">{{ $qty($l->qty) }} × ${{ number_format((float) $l->unit_price, 2) }}@if(! $narrow) &nbsp;<span style="color:#444">{{ $l->product?->sku }}</span>@endif</td>
                <td class="num">${{ number_format((float) $l->line_total, 2) }}</td></tr>
        @endforeach
    </table>
    <hr>
    <table class="tot">
        <tr><td>Subtotal</td><td class="num">${{ number_format((float) $so->subtotal, 2) }}</td></tr>
        <tr class="grand"><td>TOTAL</td><td class="num" data-test="receipt-total">${{ number_format((float) $so->total, 2) }}</td></tr>
        @foreach($so->payments->where('kind', '!=', 'refund') as $p)
            <tr><td>{{ $methods[$p->method] ?? ucfirst((string) $p->method) }}@if($p->reference && $p->reference !== 'POS') #{{ $p->reference }}@endif</td><td class="num">${{ number_format((float) $p->amount, 2) }}</td></tr>
        @endforeach
        @if($tendered !== null)
            <tr><td>Cash tendered</td><td class="num">${{ number_format((float) $tendered, 2) }}</td></tr>
            <tr class="b"><td>Change</td><td class="num" data-test="receipt-change">${{ number_format((float) $change, 2) }}</td></tr>
        @endif
        @if((float) $balance > 0)
            <tr class="b"><td>Charged to account</td><td class="num" data-test="receipt-account">${{ number_format((float) $balance, 2) }}</td></tr>
        @endif
    </table>
    <hr>
    <div class="c sm">
        @if($so->signatures()->exists())Age-restricted products: 21+ verified at sale.<br>@endif
        Thank you!
    </div>
</div>
@if($narrow)
<script>
// A roll receipt is one page as long as its content (CSS has no "auto" page height), so fit it before printing.
(function () {
    const s = document.createElement('style');
    document.head.appendChild(s);
    function fit() {
        const el = document.querySelector('.r');
        const mm = Math.ceil(el.scrollHeight * 25.4 / 96) + 10; // px -> mm, plus top/bottom margin
        s.textContent = '@page { size: 80mm ' + mm + 'mm; margin: 3mm; }';
    }
    window.addEventListener('load', fit);
    window.addEventListener('beforeprint', fit);
})();
</script>
@endif
@if($autoPrint)
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 150));</script>
@endif
</body>
</html>

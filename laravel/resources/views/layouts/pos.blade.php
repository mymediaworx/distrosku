<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">
<title>{{ $title ?? 'POS' }} · {{ config('app.name') }}</title>
<style>
/* 0.14.1 tablet POS: touch targets >= 44px, no hover-only controls, portrait stacks, landscape = cart | tender. */
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f4f5f7;color:#111;font-size:17px}
header{background:#1e3a8a;color:#fff;padding:.5rem 1rem;display:flex;gap:.75rem;justify-content:space-between;align-items:center;min-height:56px}
header a,header .hbtn{color:#fff;background:#ffffff22;border-radius:10px;padding:0 1rem;min-height:44px;display:inline-flex;align-items:center;text-decoration:none;font-weight:600;border:0;font-size:1rem;width:auto}
main{max-width:1280px;margin:0 auto;padding:.75rem}
.card{background:#fff;border-radius:12px;padding:1rem;margin-bottom:.75rem;box-shadow:0 1px 2px #0001}
label{font-size:.85rem;font-weight:600;display:block;margin:.3rem 0 .25rem}
input,select,button{font-size:1.05rem;min-height:48px;padding:.6rem .75rem;border:1px solid #c7cbd1;border-radius:10px;width:100%;touch-action:manipulation}
button{background:#1e3a8a;color:#fff;border:0;font-weight:600;cursor:pointer}button:active{filter:brightness(.85)}button[disabled]{opacity:.4}
.row{display:grid;grid-template-columns:1fr;gap:.5rem}@media(min-width:700px){.row{grid-template-columns:repeat(3,1fr)}}
.pos{display:grid;grid-template-columns:1fr;gap:.75rem}
@media(min-width:1000px) and (orientation:landscape){.pos{grid-template-columns:3fr 2fr;align-items:start}.pos .side{position:sticky;top:.75rem}}
.scan{display:flex;gap:.5rem}.scan input{font-size:1.35rem;min-height:60px}.scan button{width:auto;min-width:110px;min-height:60px;font-size:1.15rem}
.line{border-bottom:1px solid #eee;padding:.6rem 0;display:grid;grid-template-columns:1fr auto 110px 52px;gap:.5rem;align-items:center}
.step{display:flex;align-items:center;gap:.25rem}.step button{width:52px;min-height:52px;font-size:1.5rem;padding:0}
.step input{width:64px;text-align:center;padding:.3rem}.line .price{text-align:right}
.x{background:#b91c1c;min-height:52px;font-size:1.3rem;padding:0}.blk{grid-column:1/-1;color:#b91c1c;font-size:.9rem}.lot{grid-column:1/-1;font-size:.9rem;display:flex;gap:.4rem;align-items:center}.lot select{min-height:44px;flex:1}
.src{color:#555}.total{font-size:1.9rem;font-weight:700;text-align:right;margin:.5rem 0}.ok{color:#047857;font-weight:600}.err{color:#b91c1c;font-weight:600}
.keypad{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin:.5rem 0}.keypad button{min-height:64px;font-size:1.6rem;background:#e5e7eb;color:#111}
.keypad button.alt{background:#cbd5e1}.tenders{display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem}
.tenders button{background:#e5e7eb;color:#111}.tenders button.on{background:#1e3a8a;color:#fff}
.posbar{display:grid;grid-template-columns:2fr 1fr 1fr;gap:.5rem;margin-top:.5rem}.posbar button{min-height:64px;font-size:1.1rem;background:#e5e7eb;color:#111}.posbar button.big{background:#1e3a8a;color:#fff;font-size:1.3rem}
.warn{color:#92400e;background:#fef3c7;padding:.35rem .5rem;border-radius:.4rem;margin:.3rem 0}
.ovl{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:2vh 2vw;z-index:50;overflow:auto}
.ovl-in{background:#fff;border-radius:.8rem;padding:1rem;width:100%;max-width:1100px}
/* #44 till */.tillbar{margin:0 0 .3rem;font-size:.9rem}.tillbar a{color:#1e3a8a;font-weight:600;min-height:44px;display:inline-flex;align-items:center}.till{max-width:720px;margin:0 auto}.tbl{width:100%;border-collapse:collapse}.tbl td{padding:.45rem;border-bottom:1px solid #e5e7eb}.tbl .r{text-align:right}.tbl .tot td{border-top:2px solid #111}.denoms{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.5rem}.denoms label{display:flex;gap:.4rem;align-items:center}.denoms input{min-height:44px}.muted{color:#64748b;font-size:.9rem}
.chips{display:flex;flex-wrap:wrap;gap:.4rem;margin:.6rem 0}.chips button{width:auto;min-height:48px;padding:0 1rem;background:#e5e7eb;color:#111}.chips button.on{background:#1e3a8a;color:#fff}.chips.sm button{min-height:40px;font-size:.9rem}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.6rem}
.tile{display:flex;flex-direction:column;align-items:flex-start;gap:.2rem;min-height:130px;padding:.7rem;background:#f8fafc;color:#111;border:1px solid #cbd5e1;text-align:left}
.tile .tp{font-size:1.3rem;font-weight:700;color:#047857}.tile small.out{color:#b91c1c}
.pay{min-height:64px;font-size:1.3rem;background:#047857;margin-top:.5rem}
.who{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.6rem}.who button{min-height:72px;background:#e5e7eb;color:#111;font-size:1.1rem}.who button.on{background:#1e3a8a;color:#fff}
.dots{display:flex;justify-content:center;gap:1rem;margin:1rem 0}.dots span{width:22px;height:22px;border-radius:50%;border:2px solid #1e3a8a}.dots span.f{background:#1e3a8a}
.lockbox{max-width:520px;margin:0 auto}
/* #27 receipt bar */
.receipt{display:flex;gap:.5rem;align-items:center;margin:.4rem 0}.receipt button{width:auto;min-width:150px;background:#047857}.receipt button.alt{min-width:0;background:#e5e7eb;color:#111}
.receipt a{min-height:44px;display:inline-flex;align-items:center;padding:0 .5rem;color:#1e3a8a;font-weight:600}
.autoprint{display:flex;align-items:center;gap:.5rem;font-weight:400;font-size:.9rem;color:#475569;margin:.4rem 0 0}.autoprint input{width:22px;min-height:22px;height:22px}
/* Checkout always reachable: order details sit with the cart; the side column holds only the tender card. */
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}[x-cloak]{display:none!important}
.details .dgrid{display:grid;grid-template-columns:1fr;gap:.5rem}@media(min-width:700px){.details .dgrid{grid-template-columns:2fr 1fr 1fr}}
.details .notes{margin-top:.4rem}.details button.link{background:none;color:#1e3a8a;width:auto;min-height:44px;padding:0;text-decoration:underline;font-weight:600}
.line{grid-template-columns:1fr auto 110px 92px 52px}.line .ext{text-align:right;font-weight:600;font-variant-numeric:tabular-nums}
.tendrow{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:.6rem;margin-top:.5rem}.tendrow label{margin:0}
.paybar{position:fixed;left:0;right:0;bottom:0;z-index:40;display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:.6rem 1rem calc(.6rem + env(safe-area-inset-bottom));background:#fff;box-shadow:0 -2px 10px #0002}
.paybar strong{font-size:1.3rem}.paybar button{width:auto;min-width:140px;min-height:52px;background:#047857;font-size:1.15rem}
main{padding-bottom:5.5rem}
@media(min-width:1000px) and (orientation:landscape){
  .paybar{display:none}main{padding-bottom:.75rem}
  /* 10rem = header + cashier bar above the column, so Complete sale stays on screen before any scrolling. */
  .pos .side{max-height:calc(100vh - 10rem);max-height:calc(100dvh - 10rem);overflow-y:auto;overscroll-behavior:contain;border-radius:12px}
  .checkout{margin-bottom:0}.checkout .total{margin-top:0}.checkout .keypad button{min-height:54px}
  .checkout .pay{position:sticky;bottom:0;box-shadow:0 -8px 12px #fff}
}
/* 0.20.6 phones */
/* overflow-x on html only: on body it makes body a scroll container and position:sticky (tender column) stops working. */
html{overflow-x:hidden}
@media(max-width:560px){body{font-size:16px}main{padding:.5rem}header{flex-wrap:wrap;padding:.4rem .6rem}.card{padding:.75rem;border-radius:10px}
.line{grid-template-columns:1fr auto;row-gap:.35rem}.line .price{grid-column:1}.line .x{grid-column:2;width:52px}
[data-test=cart] .line{grid-template-columns:1fr auto auto}[data-test=cart] .line>div:first-child{grid-column:1/3}[data-test=cart] .line .price{grid-column:1}[data-test=cart] .line .ext{grid-column:2}[data-test=cart] .line .x{grid-column:3}
.step button{width:46px;min-height:46px}.step input{width:56px}.scan button{min-width:84px}
.posbar{grid-template-columns:1fr 1fr}.posbar button.big{grid-column:1/-1}.tiles{grid-template-columns:repeat(2,1fr)}.who{grid-template-columns:repeat(2,1fr)}
.total{font-size:1.6rem}.ovl{padding:0}.ovl-in{border-radius:0;min-height:100vh}}
</style>
@livewireStyles
</head>
<body>
<header><strong>{{ config('app.name') }} · Counter POS</strong>
@if(auth()->check() && ! session(\App\Pos\PosPin::SESSION_USER))<a href="/admin">Admin</a>@endif
</header>
<main>{{ $slot }}</main>
@livewireScripts
@include('partials.powered-by')
</body>
</html>

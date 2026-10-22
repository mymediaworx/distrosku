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
.x{background:#b91c1c;min-height:52px;font-size:1.3rem;padding:0}.blk{grid-column:1/-1;color:#b91c1c;font-size:.9rem}
.src{color:#555}.total{font-size:1.9rem;font-weight:700;text-align:right;margin:.5rem 0}.ok{color:#047857;font-weight:600}.err{color:#b91c1c;font-weight:600}
.keypad{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin:.5rem 0}.keypad button{min-height:64px;font-size:1.6rem;background:#e5e7eb;color:#111}
.keypad button.alt{background:#cbd5e1}.tenders{display:grid;grid-template-columns:repeat(2,1fr);gap:.5rem}
.tenders button{background:#e5e7eb;color:#111}.tenders button.on{background:#1e3a8a;color:#fff}
.posbar{display:grid;grid-template-columns:2fr 1fr 1fr;gap:.5rem;margin-top:.5rem}.posbar button{min-height:64px;font-size:1.1rem;background:#e5e7eb;color:#111}.posbar button.big{background:#1e3a8a;color:#fff;font-size:1.3rem}
.warn{color:#92400e;background:#fef3c7;padding:.35rem .5rem;border-radius:.4rem;margin:.3rem 0}
.ovl{position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:flex-start;justify-content:center;padding:2vh 2vw;z-index:50;overflow:auto}
.ovl-in{background:#fff;border-radius:.8rem;padding:1rem;width:100%;max-width:1100px}
.chips{display:flex;flex-wrap:wrap;gap:.4rem;margin:.6rem 0}.chips button{width:auto;min-height:48px;padding:0 1rem;background:#e5e7eb;color:#111}.chips button.on{background:#1e3a8a;color:#fff}.chips.sm button{min-height:40px;font-size:.9rem}
.tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.6rem}
.tile{display:flex;flex-direction:column;align-items:flex-start;gap:.2rem;min-height:130px;padding:.7rem;background:#f8fafc;color:#111;border:1px solid #cbd5e1;text-align:left}
.tile .tp{font-size:1.3rem;font-weight:700;color:#047857}.tile small.out{color:#b91c1c}
.pay{min-height:64px;font-size:1.3rem;background:#047857;margin-top:.5rem}
.who{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.6rem}.who button{min-height:72px;background:#e5e7eb;color:#111;font-size:1.1rem}.who button.on{background:#1e3a8a;color:#fff}
.dots{display:flex;justify-content:center;gap:1rem;margin:1rem 0}.dots span{width:22px;height:22px;border-radius:50%;border:2px solid #1e3a8a}.dots span.f{background:#1e3a8a}
.lockbox{max-width:520px;margin:0 auto}
/* 0.20.6 phones */
html,body{overflow-x:hidden}
@media(max-width:560px){body{font-size:16px}main{padding:.5rem}header{flex-wrap:wrap;padding:.4rem .6rem}.card{padding:.75rem;border-radius:10px}
.line{grid-template-columns:1fr auto;row-gap:.35rem}.line .price{grid-column:1}.line .x{grid-column:2;width:52px}
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

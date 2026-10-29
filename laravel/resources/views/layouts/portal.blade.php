@php($company = \App\Models\Company::current())
@php($contact = \Illuminate\Support\Facades\Auth::guard('portal')->user())
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'Portal' }} · {{ $company->name ?? config('app.name') }}</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f4f5f7;color:#111}
header{background:#1e3a8a;color:#fff;padding:.6rem 1rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}header img{height:36px;max-width:140px;object-fit:contain;background:#fff;border-radius:4px}
header nav{margin-left:auto;display:flex;gap:.8rem;align-items:center}header a,header button.link{color:#fff;background:none;border:0;padding:0;font-size:.95rem;width:auto;text-decoration:underline;cursor:pointer}
main{max-width:960px;margin:0 auto;padding:1rem}.card{background:#fff;border-radius:10px;padding:1rem;margin-bottom:1rem;box-shadow:0 1px 2px #0001}
label{font-size:.8rem;font-weight:600;display:block;margin:.5rem 0 .2rem}
input,select,button,textarea{font-size:1rem;padding:.65rem;border:1px solid #ccc;border-radius:8px;width:100%}
button{background:#1e3a8a;color:#fff;border:0;font-weight:600;cursor:pointer}button.sec{background:#475569}button[disabled]{opacity:.45;cursor:not-allowed}
.inline{display:flex;gap:.5rem}.inline>*{flex:1}.inline button{flex:0 0 auto;width:auto}
.grid{display:grid;grid-template-columns:1fr;gap:.75rem}@media(min-width:640px){.grid{grid-template-columns:repeat(2,1fr)}}@media(min-width:900px){.grid{grid-template-columns:repeat(3,1fr)}}
.cat{background:#fff;border-radius:10px;padding:.5rem .7rem;margin:.4rem 0;box-shadow:0 1px 2px #0001}.cat summary{cursor:pointer;padding:.4rem 0;font-size:1.05rem}
.item{display:grid;grid-template-columns:64px 1fr;gap:.6rem;background:#fff;border-radius:10px;padding:.7rem;box-shadow:0 1px 2px #0001}
.thumb{width:64px;height:64px;object-fit:contain;background:#f1f5f9;border-radius:6px}
.price{font-weight:700}.ok{color:#047857}.err{color:#b91c1c;font-size:.9rem}.na{color:#b91c1c;font-size:.85rem}.muted{color:#64748b;font-size:.85rem}
table{width:100%;border-collapse:collapse}td,th{padding:.45rem .3rem;border-bottom:1px solid #eee;text-align:left;font-size:.92rem}td.r,th.r{text-align:right}
.badge{display:inline-block;padding:.1rem .45rem;border-radius:999px;background:#e2e8f0;font-size:.75rem}
.cartbar{position:sticky;bottom:0;bottom:env(safe-area-inset-bottom);z-index:20;background:#1e3a8a;color:#fff;padding:.7rem 1rem;border-radius:10px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 -4px 14px #0f172a26}
.cartbar button{width:auto;background:#fff;color:#1e3a8a}
.tabs{display:flex;gap:.25rem;margin:.5rem 0;padding:.25rem;background:#e2e8f0;border-radius:10px}
.tabs button{flex:1;background:transparent;color:#334155;min-height:44px}.tabs button.on{background:#fff;color:#1e3a8a;box-shadow:0 1px 3px #0f172a26}
/* #37 camera scanning */
[x-cloak]{display:none!important}
.scanbtn{margin-top:.5rem;background:#047857;min-height:48px}
.scanner{position:fixed;inset:0;z-index:60;background:#0f172a;color:#fff;display:flex;flex-direction:column;padding:.75rem;padding-bottom:calc(.75rem + env(safe-area-inset-bottom));overflow-y:auto}
.scanner-top{display:flex;justify-content:space-between;align-items:center;gap:.5rem;margin-bottom:.5rem}
.scan-done{width:auto;min-width:96px;min-height:44px;background:#fff;color:#0f172a}
.scanner-view{position:relative;width:100%;max-width:560px;margin:0 auto;aspect-ratio:4/3;background:#000;border-radius:10px;overflow:hidden}
.scanner-view video{width:100%;height:100%;object-fit:cover}
.scanner-aim{position:absolute;left:10%;right:10%;top:35%;bottom:35%;border:3px solid #22c55e;border-radius:8px;box-shadow:0 0 0 999px #0006}
.scanner-status{text-align:center;margin:.6rem 0;min-height:1.4em}
.scanner .inline{max-width:560px;margin-left:auto;margin-right:auto}.scanner input{color:#111}
.scanner-list{list-style:none;padding:0;margin:0 auto;width:100%;max-width:560px}.scanner-list li{padding:.45rem .6rem;border-radius:8px;margin-bottom:.35rem;background:#1e293b;color:#fff;font-size:.95rem}
.scanner-list li.ok{border-left:4px solid #22c55e}.scanner-list li.err{border-left:4px solid #ef4444}
/* 0.20.6 phones. overflow-x on html only: on body it turns body into a scroll container and position:sticky (cart bar) stops working. */
html{overflow-x:hidden}.card{overflow-x:auto}
@media(max-width:600px){header{padding:.5rem .75rem}header nav{margin-left:0;width:100%;overflow-x:auto;gap:1rem;padding-bottom:.2rem;white-space:nowrap}
main{padding:.6rem}.card{padding:.8rem}.inline{flex-wrap:wrap}td,th{font-size:.85rem}input,select,textarea{font-size:16px}}
</style>
@livewireStyles
</head>
<body>
<header>
    @if($company?->logoUrl())<img src="{{ $company->logoUrl() }}" alt="">@endif
    <strong>{{ $company->name ?? '' }} · Customer portal</strong>
    @if($contact)
        <nav>
            <a href="{{ route('portal.catalog') }}">Catalog</a>
            <a href="{{ route('portal.catalog', ['view' => 'again']) }}">Buy again</a>
            <a href="{{ route('portal.orders') }}">Orders</a>
            <a href="{{ route('portal.pay') }}">Pay</a>
            <form method="POST" action="{{ route('portal.logout') }}" style="display:inline">@csrf<button class="link" type="submit">Sign out</button></form>
        </nav>
    @endif
</header>
<main>
    @if(session('portal_ok'))<div class="card ok">{{ session('portal_ok') }}</div>@endif
    {{ $slot }}
</main>
@include('partials.powered-by')
@livewireScripts
@stack('scripts')
</body>
</html>

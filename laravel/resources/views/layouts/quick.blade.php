<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Quick Tools · {{ $company->name ?? config('app.name') }}</title>
@include('partials.tokens')
<style>
*{box-sizing:border-box}body{margin:0;font-family:var(--font);background:#f4f5f7;color:#111}
header{background:var(--brand);color:var(--on-brand);padding:.6rem 1rem;display:flex;gap:.6rem;align-items:center}header img{height:36px;max-width:140px;object-fit:contain;background:#fff;border-radius:4px}
main{max-width:720px;margin:0 auto;padding:1rem}.card{background:#fff;border-radius:10px;padding:1rem;margin-bottom:1rem;box-shadow:0 1px 2px #0001}
label{font-size:.8rem;font-weight:600;display:block;margin:.5rem 0 .2rem}
input,select,button,textarea{font-size:1rem;padding:.7rem;border:1px solid #ccc;border-radius:8px;width:100%}
button{background:var(--brand);color:var(--on-brand);border:0;font-weight:600;cursor:pointer}button.sec{background:#475569}button[disabled]{opacity:.5}
.inline{display:flex;gap:.5rem}.inline button{width:auto;white-space:nowrap}
.tabs{display:flex;gap:.5rem;margin-bottom:1rem}.tabs button{background:#cbd5e1;color:#111}.tabs button.on{background:var(--brand);color:var(--on-brand)}
.res{display:grid;grid-template-columns:60px 1fr auto;gap:.6rem;align-items:center;border-bottom:1px solid #eee;padding:.5rem 0}
.thumb{width:60px;height:60px;object-fit:contain;background:#f1f5f9;border-radius:6px}.ok{color:var(--ok)}.pill{display:inline-block;padding:.05rem .45rem;border-radius:999px;background:#fef3c7;color:#92400e;font-size:.8rem;font-weight:600}.staff{display:flex;justify-content:space-between;align-items:center;gap:.5rem}.err{color:var(--danger);font-size:.9rem}
#reader{width:100%;margin-top:.5rem}
</style>
@livewireStyles
</head>
<body>
<header>@if($company?->logoUrl())<img src="{{ $company->logoUrl() }}" alt="">@endif<strong>{{ $company->name ?? '' }} · Quick Tools</strong></header>
<main>{{ $slot }}</main>
@include('partials.powered-by')
<script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
@livewireScripts
</body>
</html>

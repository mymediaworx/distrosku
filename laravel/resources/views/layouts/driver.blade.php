<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#0f766e">
<title>{{ $title ?? 'Driver' }} · {{ config('app.name') }}</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f1f5f9;color:#0f172a;font-size:17px}
header{background:#0f766e;color:#fff;padding:.8rem 1rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem;position:sticky;top:0;z-index:5}
header a{color:#fff;text-decoration:none;font-weight:600}main{max-width:760px;margin:0 auto;padding:.8rem}
.card{background:#fff;border-radius:12px;padding:1rem;margin-bottom:.8rem;box-shadow:0 1px 2px #0002}
label{font-size:.85rem;font-weight:600;display:block;margin:.5rem 0 .25rem}
input,select,textarea,button{font-size:1.05rem;padding:.8rem;border:1px solid #cbd5e1;border-radius:10px;width:100%;font-family:inherit}
button,.btn{background:#0f766e;color:#fff;border:0;font-weight:700;cursor:pointer;min-height:48px;display:inline-block;text-align:center;text-decoration:none;border-radius:10px;padding:.8rem}
button[disabled]{opacity:.4}.btn2{background:#475569}.bad{background:#b91c1c}.warn{background:#b45309}
.grid{display:grid;grid-template-columns:1fr;gap:.5rem}@media(min-width:600px){.grid{grid-template-columns:repeat(3,1fr)}}
.stop{display:flex;gap:.8rem;align-items:center;text-decoration:none;color:inherit}.seq{flex:none;width:40px;height:40px;border-radius:50%;background:#0f766e;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700}
.pill{display:inline-block;padding:.15rem .6rem;border-radius:99px;background:#e2e8f0;font-size:.8rem}.pill.delivered{background:#d1fae5}.pill.partial{background:#fef3c7}.pill.refused{background:#fee2e2}
.tbl{width:100%;border-collapse:collapse;font-size:.95rem}.tbl td,.tbl th{padding:.5rem .3rem;border-bottom:1px solid #e2e8f0;text-align:left}
.tbl-wrap,.card{overflow-x:auto}html,body{overflow-x:hidden}
@media(max-width:420px){main{padding:.5rem}.card{padding:.75rem}.tbl{font-size:.88rem}header{padding:.6rem .75rem}}
.ok{color:#047857;font-weight:600}.err{color:#b91c1c;font-weight:600}.muted{color:#64748b;font-size:.9rem}
</style>
@livewireStyles
</head>
<body>
<header><a href="{{ route('driver.today') }}">{{ config('app.name') }} · Driver</a><span><a href="{{ route('driver.van') }}">Van sale</a></span></header>
<main>{{ $slot }}</main>
@livewireScripts
@include('partials.powered-by')
</body>
</html>

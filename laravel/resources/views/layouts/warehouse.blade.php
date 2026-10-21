<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title ?? 'Warehouse' }} · {{ config('app.name') }}</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f4f5f7;color:#111}
header{background:#1e3a8a;color:#fff;padding:.7rem 1rem;display:flex;justify-content:space-between;align-items:center}
header a{color:#fff}main{max-width:900px;margin:0 auto;padding:1rem}
.card{background:#fff;border-radius:10px;padding:1rem;margin-bottom:1rem;box-shadow:0 1px 2px #0001}
label{font-size:.8rem;font-weight:600;display:block;margin:.4rem 0 .2rem}
input,select,button{font-size:1rem;padding:.65rem;border:1px solid #ccc;border-radius:8px;width:100%}
.row{display:grid;grid-template-columns:1fr;gap:.5rem}@media(min-width:640px){.row{grid-template-columns:repeat(3,1fr)}}
.scan{display:flex;gap:.5rem}.scan button{width:auto}
button{background:#1e3a8a;color:#fff;border:0;font-weight:600;cursor:pointer}button[disabled]{opacity:.4}
.line{border-bottom:1px solid #eee;padding:.6rem 0;display:grid;grid-template-columns:1fr 70px 90px 36px;gap:.4rem;align-items:center}
.line input{padding:.4rem}.x{background:#b91c1c;padding:.4rem}.blk{grid-column:1/-1;color:#b91c1c;font-size:.85rem}
.tbl{width:100%;border-collapse:collapse;font-size:.9rem}.tbl td,.tbl th{padding:.4rem;border-bottom:1px solid #eee;text-align:left}.done td{color:#047857}.btn2{background:#475569}.pill{display:inline-block;padding:.1rem .5rem;border-radius:99px;background:#e2e8f0;font-size:.75rem}
.total{font-size:1.5rem;font-weight:700;text-align:right}.ok{color:#047857}.err{color:#b91c1c}
</style>
@livewireStyles
</head>
<body>
<header><strong><a href="{{ route('wh.hub') }}" style="text-decoration:none">{{ config('app.name') }} · Warehouse</a></strong><a href="/admin">Admin</a></header>
<main>{{ $slot }}</main>
@livewireScripts
@include('partials.powered-by')
</body>
</html>

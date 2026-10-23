<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Account suspended</title>
<style>body{font-family:Inter,system-ui,-apple-system,sans-serif;background:#f8fafc;color:#0f172a;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
.box{max-width:30rem;width:100%;background:#fff;border:1px solid #e2e8f0;border-radius:.85rem;box-shadow:0 1px 3px rgba(15,23,42,.06);padding:1.75rem}
h1{font-size:1.35rem;margin:0 0 .5rem;letter-spacing:-.02em}p{line-height:1.5;color:#334155}.tag{display:inline-block;background:#fff7ed;color:#c2410c;border-radius:999px;padding:.15rem .6rem;font-size:.75rem;font-weight:600;margin-bottom:.75rem}</style></head>
<body><div class="box" data-test="dba-suspended"><span class="tag">Suspended</span><h1>This account is suspended</h1>
<p>{{ $company->name ?? 'This' }} account is temporarily suspended. No data has been changed or removed.</p>
@if(!empty($reason))<p><strong>Reason:</strong> {{ $reason }}</p>@endif
<p>Please contact BrandWorx support to reactivate it.</p></div></body></html>

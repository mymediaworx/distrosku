<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('code') · @yield('title')</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;background:#f4f5f7;color:#111;margin:0;min-height:100vh;display:flex;flex-direction:column}main{flex:1;display:flex;align-items:center;justify-content:center;text-align:center;padding:2rem}h1{font-size:3rem;margin:0;color:#1e3a8a}p{color:#475569}@media (prefers-color-scheme:dark){body{background:#0f172a;color:#e5e7eb}p{color:#94a3b8}h1{color:#93c5fd}}</style></head>
<body><main><div><h1>@yield('code')</h1><p>@yield('message')</p><p><a href="{{ url('/') }}">Back to home</a></p></div></main>
@include('partials.powered-by')
</body></html>

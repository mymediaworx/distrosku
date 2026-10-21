<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account suspended</title>
<style>body{font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:1rem;color:#222}.box{border:1px solid #e5a3a3;background:#fff4f4;border-radius:.5rem;padding:1.25rem}</style></head>
<body><div class="box" data-test="suspended"><h1>Account suspended</h1>
<p>The {{ $company->name ?? 'company' }} account is suspended, so sign-in and the employee quick-tools link are disabled.</p>
@if(!empty($company?->suspended_reason))<p><strong>Reason:</strong> {{ $company->suspended_reason }}</p>@endif
<p>Please contact BrandWorx support to reactivate the account.</p></div>@include('partials.powered-by')
</body></html>

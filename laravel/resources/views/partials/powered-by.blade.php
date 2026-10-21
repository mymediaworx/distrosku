{{-- DistroSKU footer brand (screens only; hidden when printing - phase 7.3). Inline styles: works in Filament, portal, POS, quick tools, error pages and printables. --}}
<style>@media print { .distrosku-powered { display:none !important } }</style>
<footer data-test="powered-by" class="distrosku-powered" style="clear:both;text-align:center;padding:1rem .5rem 1.25rem;font:12px/1 system-ui,-apple-system,sans-serif;color:#6b7280;opacity:.85">
    <a href="https://distrosku.com" target="_blank" rel="noopener" title="DistroSKU" style="display:inline-flex;align-items:center;gap:.4rem;color:#6b7280;text-decoration:none;background:rgba(255,255,255,.92);padding:3px 10px;border-radius:999px;box-shadow:0 0 0 1px rgba(0,0,0,.06)">
        <picture><source srcset="{{ asset('images/distrosku-logo.webp') }}" type="image/webp"><img src="{{ asset('images/distrosku-logo.png') }}" alt="DistroSKU" width="54" height="24" style="height:24px;width:auto;display:block"></picture>
        <span>Powered by DistroSKU</span>
    </a>
</footer>

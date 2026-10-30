<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title }} · {{ config('app.name') }}</title>
{{-- #26: works with no signal. Catalog and queued sales live in this browser (IndexedDB); sales sync to pos.offline.sync. --}}
<style>
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#111;font-size:17px}
header{background:#1e3a8a;color:#fff;padding:.5rem 1rem;display:flex;gap:.75rem;justify-content:space-between;align-items:center;min-height:56px;flex-wrap:wrap}
header a{color:#fff}
.status{font-size:.9rem}.status b{display:inline-block;padding:.1rem .5rem;border-radius:999px;margin-right:.3rem}
.on b{background:#047857}.off b{background:#b45309}
main{display:grid;grid-template-columns:1fr;gap:1rem;padding:1rem;max-width:1200px;margin:0 auto}
@media(min-width:900px){main{grid-template-columns:3fr 2fr}}
.card{background:#fff;border-radius:12px;padding:1rem;box-shadow:0 1px 2px #0f172a1a}
input,select,button{font-size:1.05rem;min-height:48px;border-radius:10px;font-family:inherit}
input,select{width:100%;padding:.6rem;border:1px solid #cbd5e1}
button{background:#1e3a8a;color:#fff;border:0;font-weight:600;cursor:pointer;padding:0 1rem}button[disabled]{opacity:.4;cursor:not-allowed}
button.sec{background:#e5e7eb;color:#111}button.ok{background:#047857}
.row{display:flex;gap:.5rem;align-items:center}.row>input{flex:1}
.res,.line{display:flex;gap:.5rem;align-items:center;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #e2e8f0}
.res small,.line small{color:#64748b}.res button{min-height:44px}
.step{display:flex;gap:.25rem;align-items:center}.step button{min-width:44px;padding:0}.step input{width:4.5rem;text-align:center}
.x{background:#b91c1c;min-width:44px;padding:0}
.total{font-size:1.9rem;font-weight:700;text-align:right;margin:.5rem 0}
.tenders{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}.tenders button{background:#e5e7eb;color:#111}.tenders button.on{background:#1e3a8a;color:#fff}
.msg{padding:.6rem .8rem;border-radius:10px;margin:.5rem 0}.msg.err{background:#fee2e2;color:#991b1b}.msg.good{background:#dcfce7;color:#166534}.msg.warn{background:#fef3c7;color:#92400e}
.queue li{margin:.3rem 0}.muted{color:#64748b;font-size:.9rem}
.tag{display:inline-block;font-size:.75rem;padding:.05rem .4rem;border-radius:6px;background:#fee2e2;color:#991b1b;margin-left:.3rem}
</style>
</head>
<body>
<header>
    <strong>{{ $title }}</strong>
    <span class="status" id="status" data-test="status">Loading…</span>
    <a href="{{ $backUrl }}" id="back">{{ $backLabel }}</a>
</header>
<main>
    <section>
        <div class="card">
            <div id="msg" aria-live="polite"></div>
            <form id="scanForm" class="row" autocomplete="off">
                <input id="scan" placeholder="Scan or type UPC / SKU / name" enterkeyhint="search" autofocus aria-label="Scan or search">
                <button type="submit">Add</button>
            </form>
            <div id="results"></div>
        </div>
        <div class="card" style="margin-top:1rem">
            <h3 style="margin-top:0">Cart</h3>
            <div id="cart" data-test="cart"><p class="muted">Cart is empty. Scan or search above.</p></div>
        </div>
    </section>
    <aside>
        <div class="card">
            <label for="cust"><strong>Customer</strong></label>
            <input id="cust" list="custList" placeholder="Account #, name or DBA" aria-label="Customer">
            <datalist id="custList"></datalist>
            <p class="muted" id="custPicked">No customer picked.</p>
            <p class="total" id="total">$0.00</p>
            <div class="tenders" role="group" aria-label="Payment">
                @foreach($tenders as $t)<button type="button" data-tender="{{ $t }}">{{ ['cash' => 'Cash', 'check' => 'Check', 'account' => 'On account'][$t] ?? ucfirst($t) }}</button>@endforeach
            </div>
            <p class="muted">Card can't be taken offline. On-account sales are re-checked against the credit limit when they sync.</p>
            <button type="button" class="ok" id="complete" style="width:100%;margin-top:.5rem;min-height:64px;font-size:1.3rem" disabled>Complete sale</button>
        </div>
        <div class="card queue" style="margin-top:1rem">
            <div class="row" style="justify-content:space-between"><strong>Waiting to sync: <span id="qcount" data-test="queue-count">0</span></strong><button type="button" class="sec" id="syncBtn">Sync now</button></div>
            <ul id="qlist" class="muted"></ul>
        </div>
    </aside>
</main>
<script>
(() => {
    const CFG = { catalogUrl: @js($catalogUrl), syncUrl: @js($syncUrl), warehouseId: @js($warehouseId),
        maxAgeHours: @js($maxAgeHours), csrf: document.querySelector('meta[name=csrf-token]').content, batch: 25 };
    const $ = (id) => document.getElementById(id);
    const MODE = @js($mode); // 'pos' or 'van': separate saved catalog + queue per mode on a shared device
    const S = { catalog: null, byCode: new Map(), cart: [], customer: null, tender: 'cash', queue: [] };

    // ---- storage (IndexedDB; the page still works for one sale at a time if the browser refuses it) ----
    const idb = new Promise((ok) => {
        try {
            const r = indexedDB.open(MODE === 'van' ? 'distrosku-van' : 'distrosku-pos', 1);
            r.onupgradeneeded = () => { r.result.createObjectStore('kv'); r.result.createObjectStore('queue', { keyPath: 'uuid' }); };
            r.onsuccess = () => ok(r.result); r.onerror = () => ok(null);
        } catch (e) { ok(null); }
    });
    const tx = async (store, mode, fn) => { const db = await idb; if (!db) return null; return new Promise((ok, fail) => {
        const t = db.transaction(store, mode); const s = t.objectStore(store); const req = fn(s);
        t.oncomplete = () => ok(req ? req.result : null); t.onerror = () => fail(t.error); }); };
    const kvGet = (k) => tx('kv', 'readonly', (s) => s.get(k));
    const kvPut = (k, v) => tx('kv', 'readwrite', (s) => s.put(v, k));
    const qAll = async () => (await tx('queue', 'readonly', (s) => s.getAll())) || [];
    const qPut = (sale) => tx('queue', 'readwrite', (s) => s.put(sale));
    const qDel = (uuid) => tx('queue', 'readwrite', (s) => s.delete(uuid));

    const deviceId = (() => { try { let d = localStorage.getItem('pos-device'); if (!d) { d = crypto.randomUUID(); localStorage.setItem('pos-device', d); } return d; } catch (e) { return null; } })();
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
    const cents = (v) => Math.round(parseFloat(v || 0) * 100);
    const money = (c) => '$' + (c / 100).toFixed(2);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const say = (text, kind = 'good') => { $('msg').innerHTML = text ? `<div class="msg ${kind}">${esc(text)}</div>` : ''; };
    const norm = (c) => String(c || '').replace(/[^0-9A-Za-z]/g, '').replace(/^0+(?=\d)/, '').toUpperCase();
    const ageHours = () => S.catalog ? (Date.now() - Date.parse(S.catalog.at)) / 36e5 : Infinity;

    function index() {
        S.byCode = new Map();
        for (const p of S.catalog.products) {
            if (p.upc) S.byCode.set(norm(p.upc), { p, factor: 1, unit: null });
            S.byCode.set(norm(p.sku), { p, factor: 1, unit: null });
            for (const b of p.codes || []) S.byCode.set(norm(b.code), { p, factor: parseFloat(b.factor) || 1, unit: b.unit });
        }
        $('custList').innerHTML = S.catalog.customers.map((c) => `<option value="${esc(c.code)} · ${esc(c.name)}${c.dba ? ' (' + esc(c.dba) + ')' : ''}"></option>`).join('');
    }

    async function loadCatalog() {
        const saved = await kvGet('catalog').catch(() => null);
        if (saved) { S.catalog = saved; index(); }
        if (navigator.onLine) {
            try {
                const r = await fetch(CFG.catalogUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (r.ok) { S.catalog = await r.json(); index(); await kvPut('catalog', S.catalog).catch(() => {}); }
            } catch (e) { /* offline after all: keep the saved copy */ }
        }
        status();
    }

    function status() {
        const on = navigator.onLine; const age = ageHours();
        const asOf = S.catalog ? new Date(S.catalog.at).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'none';
        $('status').className = 'status ' + (on ? 'on' : 'off');
        $('status').innerHTML = `<b>${on ? 'Online' : 'Offline'}</b>Catalog as of ${esc(asOf)}`;
        if (!S.catalog) say('No saved catalog on this device. Open this page once while online.', 'err');
        else if (age > CFG.maxAgeHours) say(`Saved prices are older than ${CFG.maxAgeHours} hours. Offline selling is stopped until this device gets a fresh catalog.`, 'err');
        render();
    }

    // ---- products / cart ----
    function find(q) {
        const hit = S.byCode.get(norm(q)); if (hit) return [hit];
        const words = q.toLowerCase().split(/\s+/).filter(Boolean);
        return S.catalog.products.filter((p) => { const t = (p.name + ' ' + p.sku).toLowerCase(); return words.every((w) => t.includes(w)); })
            .slice(0, 12).map((p) => ({ p, factor: 1, unit: null }));
    }
    function add(hit) {
        const c = S.customer;
        if (c && c.walk_in && hit.p.regulated) { say(`${hit.p.name}: walk-in / cash customers can't buy tobacco, vapor, CBD or kratom.`, 'err'); return; }
        const line = S.cart.find((l) => l.id === hit.p.id);
        if (line) line.qty += hit.factor; else S.cart.push({ id: hit.p.id, sku: hit.p.sku, name: hit.p.name, price: hit.p.price, qty: hit.factor, regulated: hit.p.regulated });
        say(`Added ${hit.unit ? '1 ' + hit.unit + ' (' + hit.factor + ')' : '1'} × ${hit.p.name}.`); $('results').innerHTML = ''; render();
    }
    $('scanForm').addEventListener('submit', (e) => {
        e.preventDefault(); const q = $('scan').value.trim(); if (!q || !S.catalog) return;
        const hits = find(q);
        if (hits.length === 1 && S.byCode.has(norm(q))) { add(hits[0]); $('scan').value = ''; return; }
        if (!hits.length) { say(`No product for "${q}".`, 'err'); $('results').innerHTML = ''; return; }
        $('results').innerHTML = hits.map((h, i) => `<div class="res"><div><strong>${esc(h.p.sku)}</strong> ${esc(h.p.name)}${h.p.regulated ? '<span class="tag">regulated</span>' : ''}<br><small>${money(cents(h.p.price))} / ${esc(h.p.unit)}</small></div><button type="button" data-hit="${i}">Add</button></div>`).join('');
        $('results').querySelectorAll('[data-hit]').forEach((b) => b.onclick = () => { add(hits[+b.dataset.hit]); $('scan').value = ''; $('scan').focus(); });
    });
    $('cust').addEventListener('change', () => {
        const code = $('cust').value.split(' · ')[0].trim();
        S.customer = S.catalog?.customers.find((c) => c.code === code) || null;
        if (S.customer && S.customer.walk_in && S.cart.some((l) => l.regulated)) {
            S.cart = S.cart.filter((l) => !l.regulated); say('Regulated items were removed: walk-in / cash customers can\'t buy them.', 'warn');
        }
        render();
    });
    document.querySelectorAll('[data-tender]').forEach((b) => b.onclick = () => { S.tender = b.dataset.tender; render(); });

    function total() { return S.cart.reduce((t, l) => t + cents(l.price) * l.qty, 0); }
    function render() {
        $('cart').innerHTML = S.cart.length ? S.cart.map((l, i) => `<div class="line"><div><strong>${esc(l.sku)}</strong> ${esc(l.name)}<br><small>${money(cents(l.price))} each</small></div>
            <div class="step"><button type="button" class="sec" data-dec="${i}" aria-label="One less">−</button><input type="number" min="1" step="1" inputmode="numeric" value="${l.qty}" data-qty="${i}" aria-label="Qty"><button type="button" class="sec" data-inc="${i}" aria-label="One more">+</button></div>
            <strong>${money(cents(l.price) * l.qty)}</strong><button type="button" class="x" data-rm="${i}" aria-label="Remove">×</button></div>`).join('')
            : '<p class="muted">Cart is empty. Scan or search above.</p>';
        $('cart').querySelectorAll('[data-inc]').forEach((b) => b.onclick = () => { S.cart[+b.dataset.inc].qty++; render(); });
        $('cart').querySelectorAll('[data-dec]').forEach((b) => b.onclick = () => { const l = S.cart[+b.dataset.dec]; if (l.qty > 1) l.qty--; render(); });
        $('cart').querySelectorAll('[data-rm]').forEach((b) => b.onclick = () => { S.cart.splice(+b.dataset.rm, 1); render(); });
        $('cart').querySelectorAll('[data-qty]').forEach((inp) => inp.onchange = () => { const v = parseFloat(inp.value); if (v > 0) S.cart[+inp.dataset.qty].qty = v; render(); });
        $('total').textContent = money(total());
        $('custPicked').textContent = S.customer ? `${S.customer.code} · ${S.customer.name}${S.customer.walk_in ? ' (walk-in / cash)' : ''}` : 'No customer picked.';
        document.querySelectorAll('[data-tender]').forEach((b) => b.classList.toggle('on', b.dataset.tender === S.tender));
        $('complete').disabled = !S.cart.length || !S.customer || !S.catalog || ageHours() > CFG.maxAgeHours;
        $('qcount').textContent = S.queue.length;
        $('qlist').innerHTML = S.queue.map((s) => `<li>${new Date(s.sold_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })} · ${esc(s._customer)} · ${money(cents(s.total))}${s._problem ? ' · <span class="tag">' + esc(s._problem) + '</span>' : ''}</li>`).join('');
    }

    // ---- complete + queue + sync ----
    $('complete').onclick = async () => {
        if ($('complete').disabled) return;
        const sale = { uuid: uuid(), sold_at: new Date().toISOString(), catalog_at: S.catalog.at, user_id: S.catalog.cashier?.id ?? null,
            warehouse_id: CFG.warehouseId, customer_id: S.customer.id, fulfillment: 'pickup', tender: S.tender,
            total: (total() / 100).toFixed(2), lines: S.cart.map((l) => ({ product_id: l.id, qty: String(l.qty), price: String(l.price) })),
            _customer: S.customer.code };
        S.queue.push(sale);
        try { await qPut(sale); } catch (e) { /* kept in memory until sync */ }
        S.cart = []; say(`Sale saved on this device (${money(cents(sale.total))}). It will be sent when the connection is back.`); render();
        sync();
    };

    let syncing = false;
    async function sync() {
        if (syncing || !navigator.onLine || !S.queue.length) return;
        syncing = true; $('syncBtn').disabled = true; let retried = false;
        try {
            const pending = S.queue.slice(); // oldest first; a snapshot, so indexes don't move as sales leave the queue
            for (let i = 0; i < pending.length; i += CFG.batch) {
                const batch = pending.slice(i, i + CFG.batch);
                const r = await fetch(CFG.syncUrl, { method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CFG.csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({ device_id: deviceId, sales: batch.map(({ _customer, _problem, ...s }) => s) }) });
                if (r.status === 419 && !retried) { // page came from the offline cache with an old token: get a fresh one and retry once
                    retried = true; i -= CFG.batch;
                    try { const html = await (await fetch(location.pathname, { credentials: 'same-origin', cache: 'no-store' })).text();
                        const m = html.match(/name="csrf-token" content="([^"]+)"/); if (m) CFG.csrf = m[1]; } catch (e) { break; }
                    continue;
                }
                if (r.status === 401 || r.status === 419) { say('Sign in to the POS again to send the saved sales. Nothing is lost.', 'warn'); break; }
                if (!r.ok) { say('The server could not take the saved sales yet. They stay on this device.', 'warn'); break; }
                const { results } = await r.json();
                for (const res of results || []) {
                    const s = S.queue.find((q) => q.uuid === res.uuid); if (!s) continue;
                    if (['completed', 'needs_review', 'resolved'].includes(res.status)) {
                        S.queue = S.queue.filter((q) => q.uuid !== res.uuid); await qDel(res.uuid).catch(() => {});
                        say(res.status === 'completed' ? `Synced as ${res.number}.` : 'Synced. A manager will review one sale (Offline sales: needs review).', res.status === 'completed' ? 'good' : 'warn');
                    } else { s._problem = (res.problems || []).join(' ') || 'Not accepted'; }
                }
            }
        } catch (e) { /* lost the connection mid-sync: try again later */ }
        syncing = false; $('syncBtn').disabled = false; render();
    }
    $('syncBtn').onclick = sync;
    window.addEventListener('online', () => { status(); loadCatalog().then(sync); });
    window.addEventListener('offline', status);

    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/pos-sw.js', { scope: @js($swScope) }).catch(() => {});
    (async () => { S.queue = await qAll().catch(() => []); await loadCatalog(); sync(); })();
    window.__offlinePos = S; // for support / tests
})();
</script>
</body>
</html>

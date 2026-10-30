/* #26 offline POS service worker (scope /pos). Keeps a copy of the offline POS page so it opens with no signal.
   Online: everything goes to the network as usual (and the copy is refreshed). Offline: /pos and /pos/offline open the copy. */
const CACHE = 'distrosku-pos-v1';
const PAGE = '/pos/offline';

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.add(new Request(PAGE, { credentials: 'same-origin' }))).catch(() => {}).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('distrosku-pos-') && k !== CACHE).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET' || req.mode !== 'navigate') return; // data, Livewire and sync calls are never cached
    const path = new URL(req.url).pathname.replace(/\/$/, '');
    if (path !== '/pos' && path !== PAGE) return;
    e.respondWith(fetch(req).then((res) => {
        if (path === PAGE && res.ok && !res.redirected) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(PAGE, copy)).catch(() => {});
        }
        return res;
    }).catch(() => caches.match(PAGE).then((r) => r || new Response('Offline and no saved POS page on this device. Open the POS once while online.', { status: 503, headers: { 'Content-Type': 'text/plain' } }))));
});

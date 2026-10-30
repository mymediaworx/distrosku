/* #26 offline sales service worker. Registered twice: scope /pos (counter POS) and scope /driver (van sales).
   Keeps a copy of the offline sale page so it opens with no signal. Online: everything goes to the network as usual
   (and the copy is refreshed). Offline: the sale screens open the copy. Data, Livewire and sync calls are never cached. */
const VAN = self.registration.scope.replace(/\/$/, '').endsWith('/driver');
const PAGE = VAN ? '/driver/van/offline' : '/pos/offline';
const SCREENS = VAN ? ['/driver/van', PAGE] : ['/pos', PAGE];
const CACHE = 'distrosku-' + (VAN ? 'van' : 'pos') + '-v1';

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.add(new Request(PAGE, { credentials: 'same-origin' }))).catch(() => {}).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    const prefix = 'distrosku-' + (VAN ? 'van' : 'pos') + '-';
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith(prefix) && k !== CACHE).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET' || req.mode !== 'navigate') return;
    const path = new URL(req.url).pathname.replace(/\/$/, '');
    if (!SCREENS.includes(path)) return;
    e.respondWith(fetch(req).then((res) => {
        if (path === PAGE && res.ok && !res.redirected) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(PAGE, copy)).catch(() => {});
        }
        return res;
    }).catch(() => caches.match(PAGE).then((r) => r || new Response('Offline and no saved sale page on this device. Open it once while online.', { status: 503, headers: { 'Content-Type': 'text/plain' } }))));
});

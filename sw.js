// Service worker: makes the system installable as an app.
// Pages always come from the server (live stock data), so nothing is cached except the design files.
const CACHE = 'jennyding-v1';
const ASSETS = ['assets/style.css', 'assets/icon-192.png', 'assets/icon-512.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  if (req.mode === 'navigate') {
    // Always load pages from the server; show a simple message if offline
    e.respondWith(fetch(req).catch(() => new Response(
      '<meta name="viewport" content="width=device-width"><body style="font-family:sans-serif;padding:40px;text-align:center"><h2>No connection</h2><p>Connect to the internet (or the store Wi-Fi) and try again.</p></body>',
      { headers: { 'Content-Type': 'text/html' } })));
    return;
  }
  const url = new URL(req.url);
  if (url.origin === location.origin && url.pathname.includes('/assets/')) {
    e.respondWith(caches.match(req).then(r => r || fetch(req)));
  }
});

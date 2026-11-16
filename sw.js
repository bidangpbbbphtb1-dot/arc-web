/* Service worker ARC: membuat web bisa dipasang sebagai aplikasi dan tetap terbuka saat offline.
   Ganti VERSION bila ingin memaksa semua perangkat membuang cache lama. */
const VERSION = 'v2';
const STATIC = 'arc-static-' + VERSION;
const PAGES = 'arc-pages-' + VERSION;
const DATA = 'arc-data-' + VERSION;
const SHELL = 'shell.html';                       // kunci cache untuk halaman utama
const PRECACHE = ['manifest.json', 'icon-192.png', 'icon-512.png'];
const CDN_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.cloudflare.com'];

/* Hosting gratis kadang mengirim halaman pengaman (HTML) sebagai ganti berkas yang diminta.
   Fungsi ini memastikan isi sesuai jenis berkasnya sebelum disimpan atau dipakai dari cache. */
function isGood(url, res) {
    if (!res || res.type === 'opaque') return true;
    const ct = (res.headers.get('content-type') || '').toLowerCase();
    const p = url.pathname.toLowerCase();
    if (/\.json$/.test(p)) return ct.includes('json');
    if (/\.svg$/.test(p)) return ct.includes('svg');
    if (/\.(png|jpe?g|webp|gif|ico)$/.test(p)) return ct.startsWith('image/');
    if (/\.js$/.test(p)) return ct.includes('javascript');
    if (/\.css$/.test(p)) return ct.includes('css');
    return !ct.includes('text/html');
}

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(STATIC);
        await Promise.all(PRECACHE.map(async (path) => {
            try {
                const req = new Request(path, { cache: 'reload' });
                const res = await fetch(req);
                if (res.ok && isGood(new URL(req.url), res)) await cache.put(req, res);
            } catch (err) { /* lewati: akan tersimpan saat dipakai */ }
        }));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('arc-') && !k.endsWith(VERSION)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET' || req.headers.has('range')) return;
    const url = new URL(req.url);
    const same = url.origin === self.location.origin;

    // login/save dan berkas audio/video selalu langsung ke jaringan
    if (same && (/\.php$/i.test(url.pathname) || /\.(mp4|mp3|m4a|webm|ogg|wav)$/i.test(url.pathname))) return;

    if (req.mode === 'navigate') { event.respondWith(networkFirstPage(req)); return; }
    if (same && /dashboard_data\.json$/i.test(url.pathname)) { event.respondWith(networkFirstData(url)); return; }
    if (same ? /\.(png|jpe?g|webp|gif|svg|ico|woff2?|css|js|json)$/i.test(url.pathname) : CDN_HOSTS.includes(url.hostname)) {
        event.respondWith(staleWhileRevalidate(event, req));
    }
});

/* Halaman utama: selalu coba versi terbaru dari server; simpan hanya bila benar-benar halaman ARC
   (supaya halaman pengaman milik hosting tidak ikut tersimpan). */
async function networkFirstPage(req) {
    const cache = await caches.open(PAGES);
    try {
        const res = await fetch(req);
        if (res.ok) {
            res.clone().text().then((t) => {
                if (t.includes('id="ytPanel"')) {
                    cache.put(SHELL, new Response(t, { headers: { 'Content-Type': 'text/html; charset=utf-8' } }));
                }
            }).catch(() => {});
        }
        return res;
    } catch (err) {
        const cached = await cache.match(SHELL);
        return cached || new Response('<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><body style="font-family:sans-serif;background:#06030e;color:#facc15;display:grid;place-items:center;height:100vh;margin:0;text-align:center"><div><h2>ARC sedang offline</h2><p>Sambungkan internet lalu muat ulang.</p></div>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
    }
}

/* Data tautan & playlist: ambil yang terbaru, cadangan dari cache saat offline atau saat server mengirim HTML */
async function networkFirstData(url) {
    const cache = await caches.open(DATA);
    const key = url.origin + url.pathname;
    let res = null;
    try {
        res = await fetch(url.href, { cache: 'no-store' });
        if (res.ok && isGood(url, res)) { cache.put(key, res.clone()); return res; }
    } catch (err) { /* offline */ }
    const cached = await cache.match(key);
    return cached || res || Response.error();
}

/* Berkas pendukung: tampilkan dari cache dulu, perbarui di latar belakang */
async function staleWhileRevalidate(event, req) {
    const url = new URL(req.url);
    const cache = await caches.open(STATIC);
    const cached = await cache.match(req);
    const net = fetch(req).then((res) => {
        if (res && (res.ok || res.type === 'opaque') && isGood(url, res)) cache.put(req, res.clone());
        return res;
    }).catch(() => null);
    event.waitUntil(net);
    if (cached && isGood(url, cached)) return cached;
    return (await net) || Response.error();
}
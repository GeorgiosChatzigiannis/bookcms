const CACHE = 'flipbook-v1';
const STATIC = [
    'css/bookblock.css',
    'css/custom.css',
    'js/jquery.bookblock.js',
    'js/jquery.mousewheel.js',
    'js/jquerypp.custom.js',
    'js/modernizr.custom.79639.js',
    'js/app.js'
];

self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC)));
    self.skipWaiting();
});

self.addEventListener('activate', e => {
    e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))));
    self.clients.claim();
});

self.addEventListener('fetch', e => {
    const u = new URL(e.request.url);

    // Static assets + images: Cache First
    if (/\.(css|js|woff2?|ttf|jpg|jpeg|png|gif|webp|svg|ico)$/i.test(u.pathname)) {
        e.respondWith(caches.match(e.request).then(r => r || fetch(e.request).then(res => {
            if (res.ok) { const c = res.clone(); caches.open(CACHE).then(ca => ca.put(e.request, c)); }
            return res;
        })));
        return;
    }

    // API section: Network First, cache fallback
    if (u.pathname.includes('api.php') && u.searchParams.get('action') === 'section') {
        e.respondWith(fetch(e.request).then(res => {
            const c = res.clone(); caches.open(CACHE).then(ca => ca.put(e.request, c));
            return res;
        }).catch(() => caches.match(e.request)));
        return;
    }

    // HTML: Network First
    if (e.request.mode === 'navigate') {
        e.respondWith(fetch(e.request).then(res => {
            const c = res.clone(); caches.open(CACHE).then(ca => ca.put(e.request, c));
            return res;
        }).catch(() => caches.match(e.request)));
        return;
    }

    // Default
    e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
});

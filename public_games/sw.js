const CACHE_VERSION = 'gh-v11';
const CACHE_NAME = `kai-games-${CACHE_VERSION}`;

const PRECACHE_ASSETS = [
    './css/games-hub.css',
    './js/http-hub.js',
    './js/hub.js',
    './js/qwixx.js',
    './android-chrome-192x192.png',
    './android-chrome-512x512.png',
    './apple-touch-icon.png',
    './offline.html'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys.filter((k) => k.startsWith('kai-games-') && k !== CACHE_NAME).map((k) => caches.delete(k))
            )
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // API-Calls & POST immer direkt ans Netzwerk
    if (event.request.method !== 'GET' || url.pathname.includes('/api.php') || url.pathname.includes('/api/')) {
        return;
    }

    // HTML: Network First mit Offline Fallback
    if (event.request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(event.request)
                .catch(() => caches.match('./offline.html'))
        );
        return;
    }

    // Statische Assets: Stale-While-Revalidate
    event.respondWith(
        caches.match(event.request).then((cached) => {
            const networkFetch = fetch(event.request).then((response) => {
                if (response && response.status === 200) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            }).catch(() => cached);
            return cached || networkFetch;
        })
    );
});

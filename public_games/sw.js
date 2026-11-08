const CACHE_VERSION = 'gh-v18';
const CACHE_NAME = `kai-games-${CACHE_VERSION}`;

const PRECACHE_ASSETS = [
    './css/games-hub.css',
    './js/http-hub.js',
    './js/hub.js',
    './js/qwixx.js',
    './js/kniffel.js',
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

    // 1. Nur HTTP und HTTPS abfangen (keine chrome-extension://, blob:, data: etc.)
    if (!url.protocol.startsWith('http')) {
        return;
    }

    // 2. Keine Anfragen an fremde Origins abfangen (z. B. WhatsApp, externe Dienste)
    if (url.origin !== self.location.origin) {
        return;
    }

    // 3. API-Calls, POST & manifest.json immer direkt ans Netzwerk
    if (event.request.method !== 'GET' || url.pathname.includes('/api.php') || url.pathname.includes('/api/') || url.pathname.endsWith('manifest.json')) {
        return;
    }

    // 4. HTML: Network First mit abgesichertem Offline-Fallback
    if (event.request.mode === 'navigate' || event.request.headers.get('accept')?.includes('text/html')) {
        event.respondWith(
            fetch(event.request)
                .catch(async () => {
                    const fallback = await caches.match('./offline.html') || await caches.match('/offline.html');
                    return fallback || new Response('Offline - bitte Verbindung prüfen', {
                        status: 503,
                        statusText: 'Service Unavailable',
                        headers: { 'Content-Type': 'text/plain; charset=utf-8' }
                    });
                })
        );
        return;
    }

    // 5. Statische Assets: Stale-While-Revalidate
    event.respondWith(
        caches.match(event.request).then((cached) => {
            const networkFetch = fetch(event.request).then((response) => {
                if (response && response.status === 200 && response.type === 'basic') {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, clone).catch(() => {});
                    }).catch(() => {});
                }
                return response;
            }).catch(() => cached);

            return cached || networkFetch;
        })
    );
});

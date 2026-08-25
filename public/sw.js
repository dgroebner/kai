// public/sw.js
// Service Worker fuer die kai PWA
// Strategie: Network-First fuer HTML-Seiten, Cache-First fuer statische Assets

const CACHE_VERSION = 'v1';
const CACHE_NAME = `kai-${CACHE_VERSION}`;

// Statische Assets, die beim Install gecacht werden
const PRECACHE_ASSETS = [
    '/css/style.css',
    '/js/http.js',
    '/js/pwa-register.js',
    '/android-chrome-192x192.png',
    '/android-chrome-512x512.png',
    '/apple-touch-icon.png',
    '/offline.html',
];

// --- Install: statische Assets vorladen ---
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(PRECACHE_ASSETS);
        })
    );
    // Neuen SW sofort aktivieren, ohne auf Tab-Schliessen zu warten
    self.skipWaiting();
});

// --- Activate: alte Caches loeschen ---
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name.startsWith('kai-') && name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
        })
    );
    // Sofort alle Clients uebernehmen
    self.clients.claim();
});

// --- Fetch: Anfragen abfangen ---
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // POST-Anfragen und andere nicht-GET-Methoden immer direkt ans Netzwerk
    if (request.method !== 'GET') {
        return;
    }

    // Anfragen an andere Origins (z. B. Google OAuth) immer ans Netzwerk
    if (url.origin !== self.location.origin) {
        return;
    }

    // API-Endpunkte immer ans Netzwerk (api.php, ingest.php, api_live.php etc.)
    if (url.pathname.includes('/api') || url.pathname.includes('/ingest') || url.pathname.includes('/cron_')) {
        return;
    }

    // Statische Assets: Cache-First
    const isStaticAsset = url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|ico|webp|woff2?)$/);
    if (isStaticAsset) {
        event.respondWith(
            caches.match(request).then((cached) => {
                return cached || fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }

    // HTML-Seiten (PHP): Network-First mit Offline-Fallback
    event.respondWith(
        fetch(request)
            .then((response) => response)
            .catch(() => caches.match('/offline.html'))
    );
});
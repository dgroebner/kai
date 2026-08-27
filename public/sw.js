// public/sw.js
// Service Worker für die kai PWA
// Strategie: Network-First für HTML-Seiten, Cache-First für statische Assets

const CACHE_VERSION = 'v2';
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

// --- Activate: alte Caches löschen ---
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
    const {request} = event;
    const url = new URL(request.url);

    // POST-Anfragen und andere nicht-GET-Methoden immer direkt ans Netzwerk
    if (request.method !== 'GET') {
        return;
    }

    // Anfragen an andere Origins (z. B. Google OAuth) immer ans Netzwerk
    if (url.origin !== self.location.origin) {
        return;
    }

    // API-Endpunkte immer ans Netzwerk (api.php, ingest.php, api_live.php, push_*.php etc.)
    if (url.pathname.includes('/api') || url.pathname.includes('/ingest') || url.pathname.includes('/cron_') || url.pathname.includes('/push_')) {
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

// --- Push: Web-Push-Benachrichtigung empfangen ---
self.addEventListener('push', (event) => {
    let data = {title: 'Kai', body: 'Neue Aktivität', icon: '/android-chrome-192x192.png', url: '/'};

    if (event.data) {
        try {
            data = {...data, ...JSON.parse(event.data.text())};
        } catch (_) {
            data.body = event.data.text();
        }
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: data.icon,
            badge: data.badge || '/android-chrome-192x192.png',
            tag: 'kai-activity',
            renotify: true,
            data: {url: data.url},
        })
    );
});

// --- Notificationclick: Klick auf Benachrichtigung ---
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({type: 'window', includeUncontrolled: true}).then((windowClients) => {
            // Bereits offenes Fenster fokussieren, wenn vorhanden
            for (const client of windowClients) {
                const clientUrl = new URL(client.url);
                if (clientUrl.origin === self.location.origin) {
                    client.focus();
                    client.navigate(targetUrl);
                    return;
                }
            }
            // Kein offenes Fenster — neues öffnen
            return clients.openWindow(targetUrl);
        })
    );
});
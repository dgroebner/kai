// Service Worker für Projects (PWA)
const CACHE_VERSION = 'proj-v3';
const CACHE_NAME = `projects-${CACHE_VERSION}`;

const PRECACHE_ASSETS = [
    'styles.css',
    'http.js',
    'projects.js',
    'favicon.svg',
    'icon-192.png',
    'icon-512.png',
    'manifest.json',
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
            Promise.all(keys.filter((k) => k.startsWith('projects-') && k !== CACHE_NAME).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // API-Aufrufe und dynamische PHP-Seiten immer direkt ans Netzwerk (kein HTML/CSRF-Stale-Cache)
    if (url.pathname.includes('/api.php') || url.pathname.includes('/admin_api.php') || url.pathname.includes('/cron.php') || url.pathname.endsWith('.php')) {
        return;
    }

    // Statische Assets: Cache-First
    if (url.pathname.match(/\.(css|js|png|jpg|svg|ico|woff2?)$/)) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request))
        );
        return;
    }

    // HTML: Network-First
    event.respondWith(
        fetch(request).catch(() => caches.match('index.php'))
    );
});

// Push-Notifications
self.addEventListener('push', (event) => {
    let data = { title: 'Projects', body: 'Feierabend-Check', url: 'index.php' };
    if (event.data) {
        try {
            data = { ...data, ...JSON.parse(event.data.text()) };
        } catch (_) {
            data.body = event.data.text();
        }
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: 'icon-192.png',
            badge: 'icon-192.png',
            data: { url: data.url },
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || 'index.php';
    event.waitUntil(
        clients.matchAll({ type: 'window' }).then((windowClients) => {
            for (const client of windowClients) {
                if (client.url.includes('projects') || client.url.includes('index.php')) {
                    return client.focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});

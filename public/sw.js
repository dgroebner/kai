// public/sw.js
// Service Worker für die kai PWA
// Strategie: Network-First für HTML-Seiten, Cache-First für statische Assets

const CACHE_VERSION = 'v8';
const CACHE_NAME = `kai-${CACHE_VERSION}`;

// Statische Assets, die beim Install gecacht werden
const PRECACHE_ASSETS = [
    '/css/style.css',
    '/js/http.js',
    '/js/offline.js',
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

    // HTML-Seiten (PHP): Network-First mit Timeout, automatischem Retry und Offline-Fallback
    event.respondWith(
        fetchWithRetry(request)
            .catch(() => caches.match('/offline.html'))
    );
});

// Konfiguration für Timeouts und Wiederholungsversuche bei Seitenaufrufen
const PAGE_FETCH_TIMEOUT_MS = 8000; // 8 Sekunden Timeout pro Versuch
const PAGE_MAX_RETRIES = 2;         // Bis zu 2 automatische Wiederholungsversuche
const PAGE_RETRY_DELAY_MS = 400;    // 400ms Basis-Pause vor dem nächsten Versuch

/**
 * Führt einen Netzwerk-Fetch mit Timeout und automatischem Retry durch.
 * Verhindert, dass kurzzeitige Verbindungsabbrüche (Standby-Aufwachen des Handys,
 * Funkzellen- oder WLAN-Wechsel) sofort die Offline-Seite triggern.
 */
async function fetchWithRetry(request, maxRetries = PAGE_MAX_RETRIES, delayMs = PAGE_RETRY_DELAY_MS, timeoutMs = PAGE_FETCH_TIMEOUT_MS) {
    for (let attempt = 0; attempt <= maxRetries; attempt++) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

        try {
            // Bei Wiederholungen Request klonen, um Stream-Konflikte zu vermeiden
            const reqToFetch = attempt < maxRetries ? request.clone() : request;
            const response = await fetch(reqToFetch, { signal: controller.signal });
            clearTimeout(timeoutId);
            return response;
        } catch (error) {
            clearTimeout(timeoutId);
            if (attempt === maxRetries) {
                throw error;
            }
            // Kurze Pause vor dem nächsten Versuch mit leicht steigendem Backoff (400ms, 800ms)
            await new Promise((resolve) => setTimeout(resolve, delayMs * (attempt + 1)));
        }
    }
}

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

    // Nimmt die URL direkt aus den Benachrichtigungsdaten (sollte dank Backend absolut sein)
    const rawUrl = event.notification.data?.url || '/';
    // Zur Sicherheit absolut auflösen (falls doch mal nur ein relativer Pfad ankommt)
    const targetUrl = new URL(rawUrl, self.location.origin).href;

    event.waitUntil(
        clients.matchAll({type: 'window', includeUncontrolled: true}).then((windowClients) => {
            // Versuchen, ein passendes offenes Fenster zu finden
            for (const client of windowClients) {
                const clientUrl = new URL(client.url);
                if (clientUrl.origin === self.location.origin) {
                    // Auf dem Handy ist clients.openWindow oft stabiler als client.navigate,
                    // aber wir versuchen erst den Fokus + openWindow im Kontext
                    return client.focus().then(() => {
                        // Wenn client.navigate unterstützt wird, nutzen, sonst openWindow fallback
                        if ('navigate' in client) {
                            return client.navigate(targetUrl).catch(() => clients.openWindow(targetUrl));
                        }
                        return clients.openWindow(targetUrl);
                    });
                }
            }
            // Kein offenes Fenster vorhanden -> Neues Fenster öffnen
            return clients.openWindow(targetUrl);
        })
    );
});
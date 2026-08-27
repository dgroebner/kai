// public/js/pwa-register.js
// Registriert den Service Worker für die kai PWA

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then((reg) => {
                console.log('PWA: Service Worker registriert. Scope:', reg.scope);
            })
            .catch((err) => {
                console.error('PWA: Service Worker Registrierung fehlgeschlagen:', err);
            });
    });
}
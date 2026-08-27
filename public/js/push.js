// public/js/push.js
// Web-Push-Subscription-Verwaltung fuer die kai PWA
// Voraussetzung: http.js muss vor diesem Skript geladen sein (KaiHttp)

/**
 * Konvertiert einen Base64url-String in ein Uint8Array (benoetigt fuer applicationServerKey).
 * @param {string} base64String
 * @returns {Uint8Array}
 */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map((c) => c.charCodeAt(0)));
}

/**
 * Gibt den VAPID Public Key vom Server zurueck.
 * @returns {Promise<string|null>}
 */
async function fetchVapidPublicKey() {
    try {
        const res = await fetch('/system/push_vapid_key.php', { headers: { Accept: 'application/json' } });
        if (!res.ok) return null;
        const data = await res.json();
        return data.publicKey || null;
    } catch (_) {
        return null;
    }
}

/**
 * Sendet die Subscription an den Server (action: subscribe | unsubscribe).
 * @param {'subscribe'|'unsubscribe'} action
 * @param {PushSubscription} subscription
 * @returns {Promise<boolean>}
 */
async function sendSubscriptionToServer(action, subscription) {
    const subJson = subscription.toJSON();
    try {
        const data = await window.KaiHttp.postJson('/system/push_subscribe.php', {
            action,
            endpoint: subJson.endpoint,
            p256dh:   subJson.keys?.p256dh  ?? '',
            auth:     subJson.keys?.auth    ?? '',
        });
        return data?.success === true;
    } catch (_) {
        return false;
    }
}

/**
 * Aktiviert Web-Push fuer diesen Browser und speichert die Subscription auf dem Server.
 * @returns {Promise<boolean>} true bei Erfolg
 */
async function subscribeToPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        console.warn('push.js: Web Push wird von diesem Browser nicht unterstuetzt.');
        return false;
    }

    // Benachrichtigungsberechtigung einholen (falls noch nicht erteilt)
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        console.info('push.js: Benachrichtigungsberechtigung verweigert.');
        return false;
    }

    const vapidPublicKey = await fetchVapidPublicKey();
    if (!vapidPublicKey) {
        console.warn('push.js: VAPID Public Key nicht verfuegbar.');
        return false;
    }

    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        });
        return await sendSubscriptionToServer('subscribe', subscription);
    } catch (err) {
        console.warn('push.js: Fehler beim Abonnieren:', err);
        return false;
    }
}

/**
 * Deaktiviert Web-Push fuer diesen Browser und entfernt die Subscription vom Server.
 * @returns {Promise<boolean>} true bei Erfolg
 */
async function unsubscribeFromPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return false;
    }

    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) return true;

        await sendSubscriptionToServer('unsubscribe', subscription);
        await subscription.unsubscribe();
        return true;
    } catch (err) {
        console.warn('push.js: Fehler beim Abmelden:', err);
        return false;
    }
}

/**
 * Prueft, ob dieser Browser aktuell eine aktive Push-Subscription hat.
 * @returns {Promise<boolean>}
 */
async function isPushSubscribed() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return false;
    }
    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        return subscription !== null;
    } catch (_) {
        return false;
    }
}

// Push-Toggle-Button initialisieren, sobald DOM bereit ist
document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('push-toggle-btn');
    const statusText = document.getElementById('push-status-text');

    if (!toggleBtn) return;

    // Push-faehigkeit pruefen
    const pushSupported = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);

    if (!pushSupported) {
        toggleBtn.disabled = true;
        if (statusText) statusText.textContent = 'Web Push wird von diesem Browser nicht unterstützt.';
        return;
    }

    // Initalen Zustand setzen
    async function updateButtonState() {
        const subscribed = await isPushSubscribed();
        const permissionDenied = Notification.permission === 'denied';

        if (permissionDenied) {
            toggleBtn.disabled = true;
            toggleBtn.textContent = '🔕 Benachrichtigungen blockiert';
            toggleBtn.className = 'btn btn-outline';
            if (statusText) statusText.textContent = 'Benachrichtigungen wurden im Browser blockiert. Bitte in den Browser-Einstellungen erlauben.';
            return;
        }

        if (subscribed) {
            toggleBtn.textContent = '🔕 Web Push deaktivieren';
            toggleBtn.className = 'btn btn-outline';
            toggleBtn.dataset.subscribed = '1';
            if (statusText) statusText.textContent = '✅ Web Push ist aktiviert. Du erhältst Benachrichtigungen auf diesem Gerät.';
        } else {
            toggleBtn.textContent = '🔔 Web Push aktivieren';
            toggleBtn.className = 'btn';
            toggleBtn.dataset.subscribed = '0';
            if (statusText) statusText.textContent = 'Web Push ist deaktiviert. Klicke, um Benachrichtigungen auf diesem Gerät zu aktivieren.';
        }
    }

    updateButtonState();

    toggleBtn.addEventListener('click', async () => {
        toggleBtn.disabled = true;
        const wasSubscribed = toggleBtn.dataset.subscribed === '1';

        if (wasSubscribed) {
            const ok = await unsubscribeFromPush();
            if (statusText) statusText.textContent = ok ? 'Web Push wurde deaktiviert.' : 'Fehler beim Deaktivieren.';
        } else {
            const ok = await subscribeToPush();
            if (!ok && Notification.permission !== 'denied') {
                if (statusText) statusText.textContent = 'Web Push konnte nicht aktiviert werden.';
            }
        }

        await updateButtonState();
        toggleBtn.disabled = false;
    });
});

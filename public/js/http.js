/**
 * Geteilte HTTP-Helfer für alle AJAX-Aufrufe des Tool-Sets.
 *
 * Hängt automatisch den CSRF-Token aus dem <meta name="csrf-token">-Tag an
 * jeden state-verändernden Request an, damit kein Endpunkt vergessen wird.
 */
(function () {
    'use strict';

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Sendet einen JSON-POST-Request inklusive CSRF-Token.
     *
     * @param {string} url  Ziel-Endpunkt (relativ zur aktuellen Seite)
     * @param {object} data Zu übertragende Nutzdaten
     * @returns {Promise<object>} Antwort des Servers als Objekt
     */
    async function postJson(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify(data)
        });

        let payload;
        try {
            payload = await response.json();
        } catch (e) {
            payload = { success: false, message: 'Ungültige Serverantwort' };
        }

        if (!response.ok && payload.success !== false) {
            payload.success = false;
        }

        return payload;
    }

    window.KaiHttp = { getCsrfToken, postJson };
})();

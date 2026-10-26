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
     * Maskiert HTML-Sonderzeichen, damit Werte gefahrlos in innerHTML-Templates
     * und in HTML-Attribute eingesetzt werden können (Schutz vor DOM-XSS).
     *
     * @param {*} value Beliebiger Wert; null/undefined werden zu einem leeren String
     * @returns {string} Maskierter Text
     */
    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Normalisiert eine Farbangabe auf ein sicheres Hex-Format (#rrggbb).
     * Farbwerte landen in style- und value-Attributen und dürfen deshalb
     * ausschließlich aus einer strikten Whitelist stammen.
     *
     * @param {*} value Zu prüfender Farbwert
     * @param {string} [fallback] Ersatzfarbe für ungültige Angaben
     * @returns {string} Gültiger Hex-Farbcode
     */
    function hexColor(value, fallback = '#3b82f6') {
        const color = String(value ?? '').trim().toLowerCase();

        if (/^#[0-9a-f]{6}$/.test(color)) {
            return color;
        }
        if (/^#[0-9a-f]{3}$/.test(color)) {
            return '#' + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
        }

        return fallback;
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
    window.KaiHtml = { escape: escapeHtml, hexColor };
})();

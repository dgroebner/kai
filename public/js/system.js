document.addEventListener('DOMContentLoaded', function () {
    // Letzte bekannte ID initialisieren (kann beim Laden z.B. von einem data-Attribut im Body kommen oder bei 0 starten)
    let lastActivityId = parseInt(document.body.dataset.lastActivityId || 0, 10);

    function pollNewActivities() {
        fetch(`/system/api.php?last_id=${lastActivityId}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Netzwerkfehler beim Activity-Polling');
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.activities && data.activities.length > 0) {
                    data.activities.forEach(activity => {
                        // Bubble anzeigen
                        showNotificationBubble(activity.message, activity.link_url);

                        // Höchste ID merken, damit wir sie nicht doppelt abfragen
                        if (activity.id > lastActivityId) {
                            lastActivityId = parseInt(activity.id, 10);
                        }
                    });
                }
            })
            .catch(error => {
                // Fehler im Hintergrund unauffällig behandeln (z.B. bei temporärem Offline-Status)
                console.debug('Activity Polling Info:', error);
            });
    }

    function showNotificationBubble(text, link) {
        // Container für Bubbles sicherstellen
        let container = document.getElementById('activity-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'activity-toast-container';
            container.style.cssText = 'position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; max-width: 350px;';
            document.body.appendChild(container);
        }

        // Bubble-Element erstellen
        const bubble = document.createElement('div');
        bubble.style.cssText = 'background: #212529; color: #fff; padding: 12px 16px; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); font-size: 0.9rem; opacity: 0; transition: opacity 0.3s ease, transform 0.3s ease; transform: translateY(10px);';

        // Nutzung des zentralen Sanitizers aus http.js (mit Fallback falls http.js mal fehlen sollte)
        const escape = window.KaiHtml && window.KaiHtml.escape ? window.KaiHtml.escape : (str => String(str ?? ''));

        let content = `<div>${escape(text)}</div>`;
        if (link) {
            content += `<div style="margin-top: 6px;"><a href="${escape(link)}" style="color: #6ea8fe; text-decoration: underline; font-size: 0.85rem;">Details ansehen &rarr;</a></div>`;
        }
        bubble.innerHTML = content;

        container.appendChild(bubble);

        // Einblenden-Animation
        requestAnimationFrame(() => {
            bubble.style.opacity = '1';
            bubble.style.transform = 'translateY(0)';
        });

        // Nach 6 Sekunden automatisch ausblenden und entfernen
        setTimeout(() => {
            bubble.style.opacity = '0';
            bubble.style.transform = 'translateY(10px)';
            setTimeout(() => bubble.remove(), 300);
        }, 6000);
    }

    // Alle 10 Sekunden das Polling ausführen
    setInterval(pollNewActivities, 10000);
});
/**
 * school.js - Interaktionen für das Schul- & Vertretungsplan-Board
 */
document.addEventListener('DOMContentLoaded', () => {
    // 1. Automatische Navigation bei Klassen-Auswahl im Dropdown
    const classSelect = document.querySelector('.school-class-select');
    if (classSelect) {
        classSelect.addEventListener('change', (e) => {
            const form = e.target.closest('form');
            if (form) {
                form.submit();
            }
        });
    }

    // 2. Synchronisations-Button (Aktualisieren)
    document.addEventListener('click', async (e) => {
        const syncBtn = e.target.closest('.js-sync-school-btn');
        if (!syncBtn) return;

        const date = syncBtn.getAttribute('data-date') || '';
        const originalText = syncBtn.textContent;
        syncBtn.disabled = true;
        syncBtn.textContent = '⏳ Aktualisiere...';

        try {
            const response = await fetch(`cron.php?date=${encodeURIComponent(date)}`, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            // Wenn Cron-Token benötigt wird, alternativ über Session oder Reload
            if (response.ok) {
                window.location.reload();
            } else {
                // Bei 403 (Cron-Token fehlt) weisen wir darauf hin oder laden neu
                window.location.reload();
            }
        } catch (err) {
            console.error('Fehler bei Synchronisation:', err);
            window.location.reload();
        } finally {
            syncBtn.disabled = false;
            syncBtn.textContent = originalText;
        }
    });
});

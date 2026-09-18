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

    // 2. Button-Feedback beim Absenden der Formulare (Aktualisieren / Jetzt prüfen)
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('.school-sync-form');
        if (!form) return;
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.textContent = '⏳ Aktualisiere...';
        }
    });
});

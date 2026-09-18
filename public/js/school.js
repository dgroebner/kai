/**
 * school.js - Interaktionen für das Schul- & Vertretungsplan-Board
 */
document.addEventListener('DOMContentLoaded', () => {
    // 2. Automatisches Absenden bei Datumsauswahl im Date-Picker
    const dateInput = document.querySelector('.school-date-input');
    if (dateInput) {
        dateInput.addEventListener('change', (e) => {
            const form = e.target.closest('form');
            if (form) {
                form.submit();
            }
        });
    }

    // 3. Button-Feedback beim Absenden der Formulare (Aktualisieren / Jetzt prüfen)
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

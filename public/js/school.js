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

    // 3. Button-Feedback beim Absenden der Formulare (Aktualisieren / Jetzt prüfen / Fächer speichern)
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('.school-sync-form, .school-subjects-form');
        if (!form) return;
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.textContent = form.classList.contains('school-subjects-form') ? '⏳ Speichern...' : '⏳ Aktualisiere...';
        }
    });

    // 4. Ein- und Ausblenden des Fächer-Konfigurationspanels (Event Delegation)
    document.addEventListener('click', (e) => {
        const toggleBtn = e.target.closest('.js-school-config-toggle');
        if (toggleBtn) {
            e.preventDefault();
            const targetId = toggleBtn.getAttribute('data-target');
            if (targetId) {
                const panel = document.getElementById(targetId);
                if (panel) {
                    const isHidden = panel.style.display === 'none' || getComputedStyle(panel).display === 'none';
                    panel.style.display = isHidden ? 'block' : 'none';
                    toggleBtn.textContent = isHidden ? '✖️ Schließen' : '⚙️ Fächer anpassen';
                }
            }
            return;
        }

        const closeBtn = e.target.closest('.js-school-config-close');
        if (closeBtn) {
            e.preventDefault();
            const targetId = closeBtn.getAttribute('data-target');
            if (targetId) {
                const panel = document.getElementById(targetId);
                if (panel) {
                    panel.style.display = 'none';
                    const parentCard = panel.closest('.school-plan-card');
                    if (parentCard) {
                        const toggle = parentCard.querySelector('.js-school-config-toggle');
                        if (toggle) {
                            toggle.textContent = '⚙️ Fächer anpassen';
                        }
                    }
                }
            }
        }
    });

    // 5. Visuelle Rückmeldung bei Klick auf Fächer-Checkbox
    document.addEventListener('change', (e) => {
        const cb = e.target.closest('.school-subject-checkbox');
        if (!cb) return;
        const item = cb.closest('.school-subject-item');
        if (!item) return;

        const statusSpan = item.querySelector('.school-subject-status');
        if (cb.checked) {
            item.classList.add('is-excluded');
            item.classList.remove('is-included');
            if (statusSpan) statusSpan.textContent = '❌ Abgewählt';
        } else {
            item.classList.remove('is-excluded');
            item.classList.add('is-included');
            if (statusSpan) statusSpan.textContent = '✅ Belegt';
        }
    });
});


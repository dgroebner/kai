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


    // 6. Klick auf eine Note -> Stilvolles KAI-Modal mit Details
    function closeGradeModal() {
        const modal = document.querySelector('.js-grade-modal-overlay');
        if (modal) {
            modal.remove();
        }
    }

    document.addEventListener('click', (e) => {
        // Schließen-Button oder Klick auf den Backdrop
        if (e.target.closest('.js-close-grade-modal') || e.target.classList.contains('js-grade-modal-overlay')) {
            closeGradeModal();
            return;
        }

        const gradeDiv = e.target.closest('.js-grade-details');
        if (gradeDiv) {
            const subject = gradeDiv.getAttribute('data-subject') || '';
            const grade = gradeDiv.getAttribute('data-grade') || '';
            const date = gradeDiv.getAttribute('data-date') || '';
            const details = gradeDiv.getAttribute('data-details') || '';

            closeGradeModal();

            const escape = (str) => (window.KaiHtml ? window.KaiHtml.escape(str) : String(str ?? ''));

            const overlay = document.createElement('div');
            overlay.className = 'rule-modal-overlay js-grade-modal-overlay';
            overlay.innerHTML = `
                <div class="rule-modal-card" style="max-width: 380px;">
                    <div class="rule-modal-header">
                        <h3>📊 Notendetails</h3>
                        <button type="button" class="rule-modal-close js-close-grade-modal" aria-label="Schließen">&times;</button>
                    </div>
                    <div class="rule-modal-body" style="gap: 1.25rem; padding: 1.25rem;">
                        <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem;">
                            <div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Fach</div>
                                <div style="font-size: 1.2rem; font-weight: bold; margin-top: 0.2rem;">${escape(subject)}</div>
                            </div>
                            <div style="background: var(--primary-color, #2563eb); color: #fff; min-width: 44px; height: 44px; padding: 0 0.5rem; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.35rem; font-weight: bold; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);">
                                ${escape(grade)}
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Bezeichnung / Nachweis</div>
                                <div style="font-size: 1.05rem; font-weight: 500; margin-top: 0.2rem;">${escape(details || 'Leistungsnachweis')}</div>
                            </div>
                            <div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px;">Datum</div>
                                <div style="font-size: 0.95rem; margin-top: 0.2rem;">${escape(date)}</div>
                            </div>
                        </div>
                    </div>
                    <div class="rule-modal-footer" style="justify-content: flex-end; padding: 0.75rem 1.25rem;">
                        <button type="button" class="btn btn-outline js-close-grade-modal" style="padding: 0.35rem 1rem;">Schließen</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeGradeModal();
        }
    });

    // 7. Filter für Hausaufgaben-Tags (Klassenarbeit, Test, Notiz etc.)
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-hw-filter-btn');
        if (!btn) return;

        const filter = btn.getAttribute('data-filter');
        const allBtns = document.querySelectorAll('.js-hw-filter-btn');
        allBtns.forEach((b) => {
            b.classList.add('btn-outline');
            b.classList.remove('is-active');
        });
        btn.classList.remove('btn-outline');
        btn.classList.add('is-active');

        const items = document.querySelectorAll('.js-homework-item');
        let visibleCount = 0;
        items.forEach((item) => {
            const itemType = item.getAttribute('data-type');
            if (filter === 'all' || itemType === filter) {
                item.style.display = '';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        const emptyNotice = document.getElementById('js-hw-filter-empty');
        if (emptyNotice) {
            emptyNotice.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    });


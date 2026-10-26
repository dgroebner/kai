/**
 * Projects Companion — Frontend Client
 * CSP-konform, kein Inline-JS, Event-Delegation.
 */
(function () {
    'use strict';

    let currentDayData = null;
    let currentMatrixData = null;
    let currentYear = new Date().getFullYear();
    let currentMonth = new Date().getMonth() + 1;
    let selectedDate = new Date().toISOString().split('T')[0];

    async function init() {
        bindEvents();
        await loadDayDetails(selectedDate);
        await loadMonthMatrix(currentYear, currentMonth);
    }

    function bindEvents() {
        // Quick Command Enter
        const quickInput = document.getElementById('quick-command');
        if (quickInput) {
            quickInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleQuickCommand(quickInput.value);
                }
            });
        }

        const quickBtn = document.getElementById('quick-submit-btn');
        if (quickBtn && quickInput) {
            quickBtn.addEventListener('click', () => {
                handleQuickCommand(quickInput.value);
            });
        }

        // Datumsauswahl für Einzeltag
        const dateInput = document.getElementById('selected-date-picker');
        if (dateInput) {
            dateInput.addEventListener('change', (e) => {
                selectedDate = e.target.value;
                loadDayDetails(selectedDate);
            });
        }

        // Monatsmatrix Navigation
        const prevMonthBtn = document.getElementById('matrix-prev-month');
        if (prevMonthBtn) {
            prevMonthBtn.addEventListener('click', () => {
                currentMonth--;
                if (currentMonth < 1) {
                    currentMonth = 12;
                    currentYear--;
                }
                loadMonthMatrix(currentYear, currentMonth);
            });
        }

        const nextMonthBtn = document.getElementById('matrix-next-month');
        if (nextMonthBtn) {
            nextMonthBtn.addEventListener('click', () => {
                currentMonth++;
                if (currentMonth > 12) {
                    currentMonth = 1;
                    currentYear++;
                }
                loadMonthMatrix(currentYear, currentMonth);
            });
        }

        // Event-Delegation für dynamische Klicks
        document.addEventListener('click', (e) => {
            // Modal öffnen
            const modalTrigger = e.target.closest('[data-open-modal]');
            if (modalTrigger) {
                const modalId = modalTrigger.getAttribute('data-open-modal');
                const modal = document.getElementById(modalId);
                if (modal) modal.style.display = 'flex';
                return;
            }

            // Modal schließen
            const modalClose = e.target.closest('[data-close-modal]');
            if (modalClose) {
                const modal = modalClose.closest('.proj-modal-backdrop');
                if (modal) modal.style.display = 'none';
                return;
            }

            // Ausnahme löschen
            const delExBtn = e.target.closest('[data-delete-exception]');
            if (delExBtn) {
                const exId = delExBtn.getAttribute('data-delete-exception');
                deleteException(exId);
                return;
            }

            // Clipboard Kopie (Matrix Spalte oder Zeile)
            const copyCell = e.target.closest('[data-copy-val]');
            if (copyCell) {
                const val = copyCell.getAttribute('data-copy-val');
                copyToClipboard(val);
                showToast(`Wert ${val}h kopiert!`);
                return;
            }

            // Logout
            const logoutBtn = e.target.closest('#btn-logout');
            if (logoutBtn) {
                handleLogout();
                return;
            }
        });

        // Profil speichern
        const profileForm = document.getElementById('form-profile');
        if (profileForm) {
            profileForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(profileForm);
                const payload = {
                    action: 'save_profile',
                    name: fd.get('name'),
                    state_code: fd.get('state_code'),
                    reminder_enabled: fd.get('reminder_enabled') === '1',
                    reminder_time: fd.get('reminder_time'),
                };
                const res = await KaiHttp.postJson('api.php', payload);
                if (res.success) {
                    showToast('Profil aktualisiert.');
                    document.getElementById('modal-profile').style.display = 'none';
                    loadDayDetails(selectedDate);
                    loadMonthMatrix(currentYear, currentMonth);
                } else {
                    alert(res.message || 'Fehler beim Speichern');
                }
            });
        }

        // Hauptprojekt wechseln Formular
        const defaultProjForm = document.getElementById('form-default-project');
        if (defaultProjForm) {
            defaultProjForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(defaultProjForm);
                const payload = {
                    action: 'set_default_project',
                    project_id: fd.get('project_id'),
                    mode: fd.get('mode'),
                    valid_from: selectedDate,
                    valid_to: fd.get('valid_to') || selectedDate,
                };
                const res = await KaiHttp.postJson('api.php', payload);
                if (res.success) {
                    showToast('Hauptprojekt gewechselt.');
                    document.getElementById('modal-default-project').style.display = 'none';
                    loadDayDetails(selectedDate);
                    loadMonthMatrix(currentYear, currentMonth);
                } else {
                    alert(res.message || 'Fehler');
                }
            });
        }

        // Abwesenheit (Urlaub/Krankheit) Multitages-Formular
        const absenceForm = document.getElementById('form-absence-range');
        if (absenceForm) {
            absenceForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(absenceForm);
                const payload = {
                    action: 'save_absence_range',
                    start_date: fd.get('start_date'),
                    end_date: fd.get('end_date'),
                    type: fd.get('type'),
                    hours: parseFloat(fd.get('hours') || 8.0),
                    comment: fd.get('comment'),
                };
                const res = await KaiHttp.postJson('api.php', payload);
                if (res.success) {
                    showToast(res.message);
                    document.getElementById('modal-absence').style.display = 'none';
                    loadDayDetails(selectedDate);
                    loadMonthMatrix(currentYear, currentMonth);
                } else {
                    alert(res.message || 'Fehler');
                }
            });
        }

        // Neues Projekt anlegen
        const newProjForm = document.getElementById('form-new-project');
        if (newProjForm) {
            newProjForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(newProjForm);
                const payload = {
                    action: 'save_project',
                    name: fd.get('name'),
                    short_code: fd.get('short_code'),
                    external_code: fd.get('external_code'),
                    color: fd.get('color'),
                };
                const res = await KaiHttp.postJson('api.php', payload);
                if (res.success) {
                    showToast('Projekt gespeichert.');
                    newProjForm.reset();
                    document.getElementById('modal-new-project').style.display = 'none';
                    location.reload();
                } else {
                    alert(res.message || 'Fehler');
                }
            });
        }
    }

    /**
     * Parst Quick-Command (z.B. "SUP 1.5 Kundenmeeting" oder "MEET 0,5")
     */
    async function handleQuickCommand(text) {
        const raw = text.trim();
        if (!raw) return;

        // Regex für "CODE DAUER KOMMENTAR"
        const parts = raw.split(/\s+/);
        if (parts.length < 2) {
            alert('Format: KÜRZEL STUNDEN [KOMMENTAR] (z.B. "SUP 1.5 Daily")');
            return;
        }

        const shortCode = parts[0].toUpperCase();
        const hours = parseFloat(parts[1].replace(',', '.'));
        const comment = parts.slice(2).join(' ');

        if (isNaN(hours) || hours <= 0) {
            alert('Bitte eine gültige positive Stundenzahl angeben (z. B. 1.5)');
            return;
        }

        const res = await KaiHttp.postJson('api.php', {
            action: 'add_exception',
            date: selectedDate,
            short_code: shortCode,
            hours: hours,
            comment: comment,
        });

        if (res.success) {
            const input = document.getElementById('quick-command');
            if (input) input.value = '';
            showToast(res.message);
            renderDayView(res.data);
            loadMonthMatrix(currentYear, currentMonth);
        } else {
            alert(res.message || 'Fehler bei der Schnelleingabe');
        }
    }

    async function loadDayDetails(date) {
        try {
            const res = await fetch(`api.php?action=day_details&date=${date}`);
            const json = await res.json();
            if (json.success) {
                currentDayData = json.data;
                renderDayView(json.data);
            }
        } catch (e) {
            console.error('Fehler beim Laden der Tagesdetails', e);
        }
    }

    function renderDayView(d) {
        // Tagestitel & Status
        const titleEl = document.getElementById('day-status-title');
        if (titleEl) {
            if (d.is_holiday) {
                titleEl.textContent = `Feiertag: ${d.holiday_name} (Soll: 0,0h)`;
            } else if (d.is_weekend) {
                titleEl.textContent = `Wochenende (Soll: 0,0h)`;
            } else {
                titleEl.textContent = `Soll: 8,0h | Gebucht: ${d.actual_hours.toFixed(1).replace('.', ',')}h`;
            }
        }

        // Live-Tagesbalken rendern (8,0h = 100%)
        const barContainer = document.getElementById('day-live-bar');
        if (barContainer) {
            barContainer.innerHTML = '';

            const total = 8.0;

            // 1. Abwesenheit (Urlaub/Krankheit)
            if (d.absence_hours > 0) {
                const pct = (d.absence_hours / total) * 100;
                const seg = document.createElement('div');
                seg.className = 'proj-bar-segment';
                seg.style.width = `${pct}%`;
                seg.style.background = d.absence.type === 'sick' ? '#ef4444' : '#10b981';
                seg.textContent = `${d.absence_hours.toFixed(1)}h ${d.absence.type}`;
                barContainer.appendChild(seg);
            }

            // 2. Ausnahmen
            d.exceptions.forEach((ex) => {
                const pct = (parseFloat(ex.hours) / total) * 100;
                const seg = document.createElement('div');
                seg.className = 'proj-bar-segment';
                seg.style.width = `${pct}%`;
                seg.style.background = ex.color || '#f59e0b';
                seg.textContent = `${parseFloat(ex.hours).toFixed(1)}h ${ex.short_code}`;
                barContainer.appendChild(seg);
            });

            // 3. Hauptprojekt (Restzeit)
            if (d.default_project_hours > 0 && d.default_project) {
                const pct = (d.default_project_hours / total) * 100;
                const seg = document.createElement('div');
                seg.className = 'proj-bar-segment';
                seg.style.width = `${pct}%`;
                seg.style.background = d.default_project.color || '#0284c7';
                seg.textContent = `${d.default_project_hours.toFixed(1)}h ${d.default_project.short_code} (Haupt)`;
                barContainer.appendChild(seg);
            }
        }

        // Ausnahmen-Liste
        const listEl = document.getElementById('day-exceptions-list');
        if (listEl) {
            if (d.exceptions.length === 0) {
                listEl.innerHTML = '<div style="color: var(--proj-text-muted); font-size: 13px;">Keine Ausnahmen gebucht. Die volle Zeit verbleibt auf dem Hauptprojekt.</div>';
            } else {
                let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';
                d.exceptions.forEach((ex) => {
                    html += `
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: var(--proj-bg); border-radius: 8px; border: 1px solid var(--proj-border);">
                            <div>
                                <span style="font-weight: 700; color: ${KaiHtml.escape(ex.color)}">${KaiHtml.escape(ex.short_code)}</span>
                                <span style="font-weight: 600; margin-left: 6px;">${parseFloat(ex.hours).toFixed(1).replace('.', ',')}h</span>
                                ${ex.comment ? `<span style="color: var(--proj-text-muted); margin-left: 8px; font-size: 13px;">— ${KaiHtml.escape(ex.comment)}</span>` : ''}
                            </div>
                            <button type="button" class="proj-btn proj-btn-danger proj-btn-sm" data-delete-exception="${ex.id}">Löschen</button>
                        </div>
                    `;
                });
                html += '</div>';
                listEl.innerHTML = html;
            }
        }
    }

    async function loadMonthMatrix(year, month) {
        try {
            const res = await fetch(`api.php?action=month_matrix&year=${year}&month=${month}`);
            const json = await res.json();
            if (json.success) {
                currentMatrixData = json.data;
                renderMatrixView(json.data);
            }
        } catch (e) {
            console.error('Fehler beim Laden der Matrix', e);
        }
    }

    function renderMatrixView(m) {
        const titleEl = document.getElementById('matrix-title');
        if (titleEl) {
            titleEl.textContent = `${m.month_name} ${m.year}`;
        }

        const summaryEl = document.getElementById('matrix-summary');
        if (summaryEl) {
            summaryEl.textContent = `Soll: ${m.total_target_hours.toFixed(1).replace('.', ',')}h | Ist: ${m.total_actual_hours.toFixed(1).replace('.', ',')}h`;
        }

        const table = document.getElementById('matrix-table');
        if (!table) return;

        // THEAD
        let thead = '<thead><tr><th class="proj-th-project">Projekt / PSP</th>';
        for (let d = 1; d <= m.days_in_month; d++) {
            const dayInfo = m.days[d];
            let cls = '';
            if (dayInfo.is_holiday) cls = 'proj-col-holiday';
            else if (dayInfo.is_weekend) cls = 'proj-col-weekend';

            thead += `<th class="${cls}" title="${dayInfo.holiday_name || dayInfo.weekday_short}">
                        <div>${d}</div>
                        <div style="font-size: 10px; font-weight: normal;">${dayInfo.weekday_short}</div>
                      </th>`;
        }
        thead += '<th class="proj-total-col">Gesamt</th></tr></thead>';

        // TBODY
        let tbody = '<tbody>';
        m.rows.forEach((row) => {
            const isAbsence = (row.type === 'absence');
            tbody += `<tr>
                <td class="proj-td-project">
                    <span style="display:inline-block; width:10px; height:10px; border-radius:3px; background:${KaiHtml.escape(row.color)}"></span>
                    <span>${KaiHtml.escape(row.short_code || row.name)}</span>
                    <span style="font-size: 10px; color: var(--proj-text-muted);">${KaiHtml.escape(row.external_code || '')}</span>
                </td>`;

            for (let d = 1; d <= m.days_in_month; d++) {
                const dayInfo = m.days[d];
                const hrs = row.daily_hours[d] || 0.0;
                let cls = 'proj-matrix-cell';
                if (dayInfo.is_holiday) cls += ' proj-col-holiday';
                else if (dayInfo.is_weekend) cls += ' proj-col-weekend';

                const displayVal = hrs > 0 ? hrs.toFixed(1).replace('.', ',') : '';
                tbody += `<td class="${cls}" data-copy-val="${hrs > 0 ? hrs.toFixed(1) : '0.0'}">${displayVal}</td>`;
            }

            tbody += `<td class="proj-total-col">${row.total_hours.toFixed(1).replace('.', ',')}h</td></tr>`;
        });

        // TFOOT: Tagessummen
        let tfoot = '<tfoot><tr style="font-weight: 800; background: var(--proj-bg);"><td class="proj-td-project">Tagessumme</td>';
        for (let d = 1; d <= m.days_in_month; d++) {
            const sum = m.daily_totals[d] || 0.0;
            tfoot += `<td>${sum > 0 ? sum.toFixed(1).replace('.', ',') : ''}</td>`;
        }
        tfoot += `<td class="proj-total-col">${m.total_actual_hours.toFixed(1).replace('.', ',')}h</td></tr></tfoot>`;

        table.innerHTML = thead + tbody + tfoot;
    }

    async function deleteException(id) {
        if (!confirm('Ausnahme wirklich löschen?')) return;
        const res = await KaiHttp.postJson('api.php', {
            action: 'delete_exception',
            id: id,
            date: selectedDate,
        });
        if (res.success) {
            showToast('Ausnahme entfernt.');
            renderDayView(res.data);
            loadMonthMatrix(currentYear, currentMonth);
        } else {
            alert(res.message || 'Fehler beim Löschen');
        }
    }

    async function handleLogout() {
        const res = await KaiHttp.postJson('api.php', { action: 'logout' });
        window.location.href = res.redirect || 'login.php';
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        }
    }

    function showToast(msg) {
        let toast = document.getElementById('proj-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'proj-toast';
            toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#0f172a;color:#fff;padding:10px 18px;border-radius:8px;font-size:14px;font-weight:600;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,0.2);transition:opacity 0.2s;';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.style.opacity = '1';
        setTimeout(() => {
            toast.style.opacity = '0';
        }, 2500);
    }

    document.addEventListener('DOMContentLoaded', init);
})();

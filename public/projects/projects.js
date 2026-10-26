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
    let currentViewMode = localStorage.getItem('proj_view_mode') || 'week';
    let currentPtMode = localStorage.getItem('proj_pt_mode') || 'rounded';
    let cachedProjects = [];

    async function init() {
        bindEvents();
        applyViewMode();
        await loadDayDetails(selectedDate);
        await loadMonthMatrix(currentYear, currentMonth);
    }

    function switchViewMode(mode) {
        currentViewMode = mode;
        try {
            localStorage.setItem('proj_view_mode', mode);
        } catch (e) {
            // LocalStorage might be disabled
        }
        applyViewMode();
    }

    function switchPtMode(mode) {
        currentPtMode = mode;
        try {
            localStorage.setItem('proj_pt_mode', mode);
        } catch (e) {
            // LocalStorage might be disabled
        }
        if (currentMatrixData) {
            renderProjectSummary(currentMatrixData);
        }
    }

    function applyViewMode() {
        const btnWeek = document.getElementById('btn-view-week');
        const btnMatrix = document.getElementById('btn-view-matrix');
        const contWeek = document.getElementById('view-week-container');
        const contMatrix = document.getElementById('view-matrix-container');

        if (currentViewMode === 'week') {
            if (btnWeek) {
                btnWeek.classList.add('active');
                btnWeek.style.background = '';
                btnWeek.style.color = '';
            }
            if (btnMatrix) {
                btnMatrix.classList.remove('active');
                btnMatrix.style.background = 'transparent';
                btnMatrix.style.color = 'var(--proj-text-muted)';
            }
            if (contWeek) contWeek.style.display = 'block';
            if (contMatrix) contMatrix.style.display = 'none';
        } else {
            if (btnMatrix) {
                btnMatrix.classList.add('active');
                btnMatrix.style.background = '';
                btnMatrix.style.color = '';
            }
            if (btnWeek) {
                btnWeek.classList.remove('active');
                btnWeek.style.background = 'transparent';
                btnWeek.style.color = 'var(--proj-text-muted)';
            }
            if (contWeek) contWeek.style.display = 'none';
            if (contMatrix) contMatrix.style.display = 'block';
        }
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
                document.querySelectorAll('.proj-day-cell').forEach((c) => {
                    if (c.getAttribute('data-date') === selectedDate) {
                        c.classList.add('is-selected');
                    } else {
                        c.classList.remove('is-selected');
                    }
                });
            });
        }

        // Umschalter Wochenansicht / 31-Tage Matrix
        const btnViewWeek = document.getElementById('btn-view-week');
        if (btnViewWeek) {
            btnViewWeek.addEventListener('click', () => {
                switchViewMode('week');
            });
        }

        const btnViewMatrix = document.getElementById('btn-view-matrix');
        if (btnViewMatrix) {
            btnViewMatrix.addEventListener('click', () => {
                switchViewMode('matrix');
            });
        }

        // Umschalter 0,5 PT (Eintragung) vs. Exakt
        const btnPtRounded = document.getElementById('btn-pt-rounded');
        if (btnPtRounded) {
            btnPtRounded.addEventListener('click', () => {
                switchPtMode('rounded');
            });
        }

        const btnPtExact = document.getElementById('btn-pt-exact');
        if (btnPtExact) {
            btnPtExact.addEventListener('click', () => {
                switchPtMode('exact');
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
                if (modalId === 'modal-manage-projects') {
                    loadManageProjectsList();
                } else if (modalId === 'modal-project-form') {
                    if (modalTrigger.id === 'btn-open-create-project' || modalTrigger.id === 'btn-manage-add-project') {
                        resetProjectForm();
                    }
                }
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

            // Projekt bearbeiten
            const editBtn = e.target.closest('[data-edit-project]');
            if (editBtn) {
                const pid = editBtn.getAttribute('data-edit-project');
                editProject(pid);
                return;
            }

            // Projekt archivieren / wiederherstellen
            const archBtn = e.target.closest('[data-toggle-archive]');
            if (archBtn) {
                const pid = archBtn.getAttribute('data-toggle-archive');
                const archiveVal = archBtn.getAttribute('data-archive-val') === '1';
                toggleArchiveProject(pid, archiveVal);
                return;
            }

            // Farbauswahl Preset geklickt
            const colorPreset = e.target.closest('[data-color]');
            if (colorPreset) {
                const cVal = colorPreset.getAttribute('data-color');
                const picker = document.getElementById('p-color');
                const hexInput = document.getElementById('p-color-hex');
                if (picker) picker.value = cVal;
                if (hexInput) hexInput.value = cVal.toUpperCase();
                return;
            }

            // Ausnahme löschen
            const delExBtn = e.target.closest('[data-delete-exception]');
            if (delExBtn) {
                const exId = delExBtn.getAttribute('data-delete-exception');
                deleteException(exId);
                return;
            }

            // Klick auf Tag in Wochenansicht (Kalenderzelle)
            const dayCell = e.target.closest('.proj-day-cell[data-date]');
            if (dayCell) {
                const targetDate = dayCell.getAttribute('data-date');
                if (targetDate) {
                    selectedDate = targetDate;
                    const picker = document.getElementById('selected-date-picker');
                    if (picker) picker.value = targetDate;

                    document.querySelectorAll('.proj-day-cell').forEach((c) => c.classList.remove('is-selected'));
                    dayCell.classList.add('is-selected');

                    loadDayDetails(selectedDate);
                    document.querySelector('.proj-hero')?.scrollIntoView({ behavior: 'smooth' });
                }
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

        // Farb-Input & Hex-Feld Synchronisation
        const colorPicker = document.getElementById('p-color');
        const colorHex = document.getElementById('p-color-hex');
        if (colorPicker && colorHex) {
            colorPicker.addEventListener('input', (e) => {
                colorHex.value = e.target.value.toUpperCase();
            });
            colorHex.addEventListener('input', (e) => {
                let val = e.target.value.trim();
                if (val && !val.startsWith('#')) val = '#' + val;
                if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                    colorPicker.value = val;
                }
            });
        }

        // Projektverwaltung: Archivierte umschalten
        const showArchivedBox = document.getElementById('manage-show-archived');
        if (showArchivedBox) {
            showArchivedBox.addEventListener('change', renderManageProjectsList);
        }

        // Projektverwaltung: Button "+ Neues Projekt anlegen"
        const btnManageAdd = document.getElementById('btn-manage-add-project');
        if (btnManageAdd) {
            btnManageAdd.addEventListener('click', () => {
                resetProjectForm();
                const formModal = document.getElementById('modal-project-form');
                if (formModal) formModal.style.display = 'flex';
            });
        }

        // Projekt anlegen / bearbeiten
        const projForm = document.getElementById('form-project');
        if (projForm) {
            projForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(projForm);
                const projectId = fd.get('id') || null;
                const payload = {
                    action: 'save_project',
                    id: projectId,
                    name: fd.get('name'),
                    short_code: fd.get('short_code'),
                    external_code: fd.get('external_code'),
                    color: fd.get('color'),
                };
                const res = await KaiHttp.postJson('api.php', payload);
                if (res.success) {
                    showToast(projectId ? 'Projekt aktualisiert.' : 'Projekt angelegt.');
                    projForm.reset();
                    document.getElementById('modal-project-form').style.display = 'none';
                    await loadManageProjectsList();
                    location.reload();
                } else {
                    alert(res.message || 'Fehler beim Speichern');
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
            const targetPt = (m.total_target_hours / 8.0).toFixed(2).replace('.', ',');
            const actualPt = (m.total_actual_hours / 8.0).toFixed(2).replace('.', ',');
            summaryEl.textContent = `Soll: ${targetPt} PT (${m.total_target_hours.toFixed(1).replace('.', ',')}h) | Ist: ${actualPt} PT (${m.total_actual_hours.toFixed(1).replace('.', ',')}h)`;
        }

        renderWeekView(m);
        render31DayTable(m);
        applyViewMode();
    }

    function renderWeekView(m) {
        const table = document.getElementById('week-table');
        if (!table) return;

        if (!m.weeks || m.weeks.length === 0) {
            table.innerHTML = '<tbody><tr><td style="padding: 20px; text-align: center; color: var(--proj-text-muted);">Keine Daten für diesen Monat.</td></tr></tbody>';
            return;
        }

        let html = '<thead><tr>';
        html += '<th class="proj-kw-col">KW</th>';
        const dows = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        dows.forEach((d) => {
            html += `<th>${d}</th>`;
        });
        html += '<th class="proj-week-total-col">Woche</th></tr></thead>';

        html += '<tbody>';
        m.weeks.forEach((w) => {
            html += '<tr>';
            html += `<td class="proj-kw-col">KW ${w.kw}</td>`;

            w.days.forEach((d) => {
                let cellCls = 'proj-day-cell';
                if (!d.is_current_month) cellCls += ' is-other-month';
                if (d.is_weekend) cellCls += ' is-weekend';
                if (d.is_holiday) cellCls += ' is-holiday';
                if (d.is_today) cellCls += ' is-today';
                if (d.date === selectedDate) cellCls += ' is-selected';

                const clickableAttr = d.is_current_month ? `data-date="${d.date}"` : '';
                const titleAttr = d.holiday_name ? `title="${KaiHtml.escape(d.holiday_name)}"` : `title="${d.date}"`;

                html += `<td class="${cellCls}" ${clickableAttr} ${titleAttr}>`;
                html += `<div class="proj-day-num">`;
                html += `<span>${d.day}</span>`;
                if (d.is_current_month && d.actual_hours > 0) {
                    html += `<span style="color: var(--proj-text); font-variant-numeric: tabular-nums;">${d.actual_hours.toFixed(1).replace('.', ',')}h</span>`;
                }
                html += `</div>`;

                if (d.is_current_month && d.entries && d.entries.length > 0) {
                    d.entries.forEach((e) => {
                        const col = e.color || '#0284c7';
                        html += `
                            <div class="proj-day-entry-badge" style="background: ${KaiHtml.escape(col)}22; border: 1px solid ${KaiHtml.escape(col)}88; color: ${KaiHtml.escape(col)};" title="${KaiHtml.escape(e.name)}: ${e.hours.toFixed(1)}h">
                                <span>${KaiHtml.escape(e.short_code || e.name.substring(0, 4))}</span>
                                <span>${e.hours.toFixed(1).replace('.', ',')}h</span>
                            </div>
                        `;
                    });
                } else if (d.is_current_month && d.is_holiday) {
                    html += `
                        <div class="proj-day-entry-badge" style="background: rgba(245, 158, 11, 0.2); border: 1px solid rgba(245, 158, 11, 0.5); color: #f59e0b;" title="${KaiHtml.escape(d.holiday_name)}">
                            <span>Feiertag</span>
                        </div>
                    `;
                }

                html += '</td>';
            });

            html += `<td class="proj-week-total-col" title="Soll: ${w.target_hours.toFixed(1)}h">${w.actual_hours.toFixed(1).replace('.', ',')}h</td>`;
            html += '</tr>';
        });
        html += '</tbody>';
        table.innerHTML = html;

        renderProjectSummary(m);
    }

    function renderProjectSummary(m) {
        const btnRounded = document.getElementById('btn-pt-rounded');
        const btnExact = document.getElementById('btn-pt-exact');
        const hintEl = document.getElementById('pt-mode-hint');

        if (currentPtMode === 'rounded') {
            if (btnRounded) {
                btnRounded.classList.add('active');
                btnRounded.style.background = '';
                btnRounded.style.color = '';
            }
            if (btnExact) {
                btnExact.classList.remove('active');
                btnExact.style.background = 'transparent';
                btnExact.style.color = 'var(--proj-text-muted)';
            }
            if (hintEl) {
                const totalRounded = m.total_actual_rounded_pt !== undefined ? m.total_actual_rounded_pt.toFixed(1).replace('.', ',') : (Math.round((m.total_actual_hours / 8) * 2) / 2).toFixed(1).replace('.', ',');
                hintEl.textContent = `0,5 PT-Modus: Exakte Hare-Niemeyer-Verteilung auf halbe Tage ohne Rundungsverlust (Monatssumme: ${totalRounded} PT). Klicke eine Karte zum Kopieren.`;
            }
        } else {
            if (btnExact) {
                btnExact.classList.add('active');
                btnExact.style.background = '';
                btnExact.style.color = '';
            }
            if (btnRounded) {
                btnRounded.classList.remove('active');
                btnRounded.style.background = 'transparent';
                btnRounded.style.color = 'var(--proj-text-muted)';
            }
            if (hintEl) {
                const totalExact = m.total_actual_pt !== undefined ? m.total_actual_pt.toFixed(2).replace('.', ',') : (m.total_actual_hours / 8.0).toFixed(2).replace('.', ',');
                hintEl.textContent = `Exakt-Modus: Exakte Umrechnung der gebuchten Stunden (Monatssumme: ${totalExact} PT = ${m.total_actual_hours.toFixed(1).replace('.', ',')}h). Klicke eine Karte zum Kopieren.`;
            }
        }

        const summaryCont = document.getElementById('project-summary-container');
        if (!summaryCont) return;

        if (!m.project_summary || m.project_summary.length === 0) {
            summaryCont.innerHTML = '<div style="color: var(--proj-text-muted); font-size: 13px;">Noch keine gebuchten Stunden in diesem Monat.</div>';
            return;
        }

        let sHtml = '';
        m.project_summary.forEach((p) => {
            const col = p.color || '#0284c7';
            const isRounded = (currentPtMode === 'rounded');

            const exactPt = (p.exact_pt !== undefined) ? p.exact_pt : (p.total_hours / 8.0);
            const roundedPt = (p.rounded_pt !== undefined) ? p.rounded_pt : (Math.round(exactPt * 2) / 2);

            const displayPt = isRounded ? roundedPt.toFixed(1).replace('.', ',') : exactPt.toFixed(2).replace('.', ',');
            const copyVal = isRounded ? roundedPt.toFixed(1) : exactPt.toFixed(2);
            const hoursStr = p.total_hours.toFixed(1).replace('.', ',');

            const subline = isRounded
                ? `Exakt: ${exactPt.toFixed(2).replace('.', ',')} PT • ${hoursStr}h`
                : `${hoursStr}h (${p.percentage.toFixed(1).replace('.', ',')}%)`;

            const tooltip = isRounded
                ? `${p.name}: ${displayPt} PT gerundet (exakt ${exactPt.toFixed(2).replace('.', ',')} PT / ${hoursStr}h) — Klicke zum Kopieren von ${displayPt} PT`
                : `${p.name}: ${displayPt} PT exakt (${hoursStr}h • ${p.percentage.toFixed(1)}%) — Klicke zum Kopieren`;

            sHtml += `
                <div class="proj-summary-card" data-copy-val="${copyVal}" title="${KaiHtml.escape(tooltip)}" style="cursor: pointer;">
                    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px;">
                        <div style="display: flex; align-items: center; gap: 6px; overflow: hidden; max-width: 150px;">
                            <span style="width: 10px; height: 10px; border-radius: 3px; background: ${KaiHtml.escape(col)}; flex-shrink: 0;"></span>
                            <strong style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 13px;" title="${KaiHtml.escape(p.name)}">${KaiHtml.escape(p.name)}</strong>
                        </div>
                        <span style="font-weight: 800; font-size: 15px; font-variant-numeric: tabular-nums; flex-shrink: 0; color: var(--proj-text);">
                            ${displayPt} <span style="font-size: 11px; font-weight: 700; color: var(--proj-text-muted);">PT</span>
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--proj-text-muted);">
                        <span>${KaiHtml.escape(p.short_code)}</span>
                        <span>${subline}</span>
                    </div>
                    <div class="proj-summary-bar">
                        <div class="proj-summary-bar-fill" style="width: ${Math.min(p.percentage, 100)}%; background: ${KaiHtml.escape(col)};"></div>
                    </div>
                </div>
            `;
        });
        summaryCont.innerHTML = sHtml;
    }

    function render31DayTable(m) {
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

    async function loadManageProjectsList() {
        const listEl = document.getElementById('manage-projects-list');
        if (!listEl) return;
        listEl.innerHTML = '<div style="color: var(--proj-text-muted); font-size: 13px; padding: 12px; text-align: center;">Lade Projekte...</div>';

        try {
            const res = await fetch('api.php?action=projects&all=1');
            const json = await res.json();
            if (json.success) {
                cachedProjects = json.data;
                renderManageProjectsList();
            }
        } catch (e) {
            listEl.innerHTML = '<div style="color: var(--proj-danger); font-size: 13px;">Fehler beim Laden der Projekte.</div>';
        }
    }

    function renderManageProjectsList() {
        const listEl = document.getElementById('manage-projects-list');
        if (!listEl) return;

        const showArchived = document.getElementById('manage-show-archived')?.checked ?? false;
        const visible = cachedProjects.filter((p) => showArchived || Number(p.is_archived) === 0);

        if (visible.length === 0) {
            listEl.innerHTML = '<div style="color: var(--proj-text-muted); font-size: 13px; padding: 20px; text-align: center;">Keine Projekte vorhanden.</div>';
            return;
        }

        let html = '';
        visible.forEach((p) => {
            const isArchived = Boolean(Number(p.is_archived));
            const color = p.color || '#0284c7';
            html += `
                <div class="proj-manage-card ${isArchived ? 'is-archived' : ''}">
                    <div style="display: flex; align-items: center; gap: 10px; overflow: hidden; min-width: 0;">
                        <span class="proj-manage-color-badge" style="background: ${KaiHtml.escape(color)};"></span>
                        <div style="overflow: hidden;">
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <strong style="font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${KaiHtml.escape(p.name)}</strong>
                                <span style="font-weight: 700; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: rgba(255,255,255,0.08);">${KaiHtml.escape(p.short_code)}</span>
                                ${isArchived ? '<span style="font-size: 10px; color: #f59e0b; border: 1px solid #f59e0b; padding: 0 4px; border-radius: 3px;">Archiviert</span>' : ''}
                            </div>
                            ${p.external_code ? `<div style="font-size: 11px; color: var(--proj-text-muted); margin-top: 2px;">PSP: ${KaiHtml.escape(p.external_code)}</div>` : ''}
                        </div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                        <button type="button" class="proj-btn proj-btn-secondary proj-btn-sm" data-edit-project="${p.id}" title="Projekt bearbeiten">
                            ✏️ Bearbeiten
                        </button>
                        ${isArchived ? `
                            <button type="button" class="proj-btn proj-btn-ghost proj-btn-sm" data-toggle-archive="${p.id}" data-archive-val="0" title="Projekt wiederherstellen">
                                ♻️ Aktivieren
                            </button>
                        ` : `
                            <button type="button" class="proj-btn proj-btn-ghost proj-btn-sm" data-toggle-archive="${p.id}" data-archive-val="1" title="Projekt archivieren">
                                📦 Archivieren
                            </button>
                        `}
                    </div>
                </div>
            `;
        });
        listEl.innerHTML = html;
    }

    function resetProjectForm() {
        const form = document.getElementById('form-project');
        if (!form) return;
        form.reset();
        document.getElementById('p-id').value = '';
        document.getElementById('p-color').value = '#0284c7';
        document.getElementById('p-color-hex').value = '#0284c7';
        document.getElementById('project-form-title').textContent = 'Neues Projekt anlegen';
        document.getElementById('project-form-submit').textContent = 'Anlegen';
    }

    function editProject(id) {
        const p = cachedProjects.find((item) => Number(item.id) === Number(id));
        if (!p) return;

        document.getElementById('p-id').value = p.id;
        document.getElementById('p-name').value = p.name;
        document.getElementById('p-short').value = p.short_code;
        const color = p.color || '#0284c7';
        document.getElementById('p-color').value = color;
        document.getElementById('p-color-hex').value = color.toUpperCase();
        document.getElementById('p-code').value = p.external_code || '';

        document.getElementById('project-form-title').textContent = 'Projekt bearbeiten';
        document.getElementById('project-form-submit').textContent = 'Speichern';

        const formModal = document.getElementById('modal-project-form');
        if (formModal) formModal.style.display = 'flex';
    }

    async function toggleArchiveProject(id, archive) {
        const confirmMsg = archive ? 'Projekt wirklich archivieren?' : 'Projekt wieder aktivieren?';
        if (!confirm(confirmMsg)) return;

        const res = await KaiHttp.postJson('api.php', {
            action: 'archive_project',
            id: id,
            archive: archive,
        });

        if (res.success) {
            showToast(res.message || (archive ? 'Projekt archiviert.' : 'Projekt wiederhergestellt.'));
            await loadManageProjectsList();
            await loadDayDetails(selectedDate);
            await loadMonthMatrix(currentYear, currentMonth);
        } else {
            alert(res.message || 'Fehler beim Archivieren');
        }
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

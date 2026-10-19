/**
 * JavaScript für die ÖPNV- und Pendler-Ansicht (public/transit/index.php)
 *
 * Verwaltet Live-Reload, Richtungsumkehr, Haltestellensuche und Profil-Modals
 * unter Einhaltung strenger CSP (keine Inline-Handler, reine Event-Delegation).
 */
(function () {
    'use strict';

    let autoRefreshTimer = null;
    let searchDebounceTimer = null;

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        initEventListeners();
        startAutoRefresh();
    }

    function initEventListeners() {
        // Event-Delegation für Klick-Aktionen
        document.addEventListener('click', function (e) {
            // 1. Manuelle Aktualisierung
            const refreshBtn = e.target.closest('.js-refresh-transit');
            if (refreshBtn) {
                e.preventDefault();
                reloadProfileData();
                return;
            }

            // 2. Richtungsumkehr (⇄)
            const swapBtn = e.target.closest('.js-swap-direction');
            if (swapBtn) {
                e.preventDefault();
                const card = document.querySelector('.transit-profile-card');
                if (!card) return;
                const currentDir = card.dataset.direction || 'outbound';
                const newDir = (currentDir === 'outbound') ? 'return' : 'outbound';
                const profileId = card.dataset.profileId || '';
                const offset = card.dataset.offset || '0';
                window.location.href = `index.php?profile=${encodeURIComponent(profileId)}&dir=${encodeURIComponent(newDir)}&offset=${encodeURIComponent(offset)}`;
                return;
            }

            // 3. Haltestellensuche öffnen / schließen
            const openSearchBtn = e.target.closest('.js-open-search-tab');
            if (openSearchBtn) {
                e.preventDefault();
                const searchSec = document.querySelector('.js-search-section');
                if (searchSec) {
                    searchSec.classList.remove('hidden');
                    searchSec.scrollIntoView({ behavior: 'smooth' });
                    const input = document.getElementById('station-search-input');
                    if (input) input.focus();
                }
                return;
            }

            const closeSearchBtn = e.target.closest('.js-close-search');
            if (closeSearchBtn) {
                e.preventDefault();
                const searchSec = document.querySelector('.js-search-section');
                if (searchSec) searchSec.classList.add('hidden');
                return;
            }

            // 4. Schnellwahltaster für Haltestellen (Quick-Chips)
            const quickBtn = e.target.closest('.js-quick-station');
            if (quickBtn) {
                e.preventDefault();
                const stationId = quickBtn.dataset.id;
                const stationName = quickBtn.dataset.name;
                loadStationBoard(stationId, stationName);
                return;
            }

            // 5. Autocomplete-Ergebnis auswählen
            const searchItem = e.target.closest('.js-search-item');
            if (searchItem) {
                e.preventDefault();
                const targetInputId = searchItem.dataset.targetInput;
                const targetHiddenId = searchItem.dataset.targetHidden;
                const stationId = searchItem.dataset.id;
                const stationName = searchItem.dataset.name;

                if (targetInputId && targetHiddenId) {
                    const textInput = document.getElementById(targetInputId);
                    const hiddenInput = document.getElementById(targetHiddenId);
                    if (textInput) textInput.value = stationName;
                    if (hiddenInput) hiddenInput.value = stationId;
                } else {
                    // Hauptsuche
                    const searchInput = document.getElementById('station-search-input');
                    if (searchInput) searchInput.value = stationName;
                    loadStationBoard(stationId, stationName);
                }

                // Dropdowns schließen
                document.querySelectorAll('.transit-search-dropdown').forEach(d => d.classList.add('hidden'));
                return;
            }

            // 6. Profil anlegen
            const addProfileBtn = e.target.closest('.js-add-profile');
            if (addProfileBtn) {
                e.preventDefault();
                openProfileModal(null);
                return;
            }

            // 7. Profil bearbeiten
            const editProfileBtn = e.target.closest('.js-edit-profile');
            if (editProfileBtn) {
                e.preventDefault();
                try {
                    const data = JSON.parse(editProfileBtn.dataset.profile || '{}');
                    openProfileModal(data);
                } catch (err) {
                    console.error('Fehler beim Parsen der Profildaten', err);
                }
                return;
            }

            // 8. Modal schließen
            const closeModalBtn = e.target.closest('.js-close-profile-modal');
            if (closeModalBtn) {
                e.preventDefault();
                closeProfileModal();
                return;
            }

            // 9. Profil löschen
            const deleteProfileBtn = e.target.closest('.js-delete-profile');
            if (deleteProfileBtn) {
                e.preventDefault();
                handleDeleteProfile();
                return;
            }

            // Klick außerhalb des Such-Dropdowns schließt dieses
            if (!e.target.closest('.transit-search-box')) {
                document.querySelectorAll('.transit-search-dropdown').forEach(d => d.classList.add('hidden'));
            }
        });

        // Formular-Submit für Profil speichern
        const profileForm = document.getElementById('profile-form');
        if (profileForm) {
            profileForm.addEventListener('submit', handleSaveProfile);
        }

        // Live-Suche nach Haltestellen
        const searchInput = document.getElementById('station-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const query = this.value.trim();
                const dropdown = document.getElementById('station-search-results');
                if (!dropdown) return;

                clearTimeout(searchDebounceTimer);
                if (query.length < 2) {
                    dropdown.classList.add('hidden');
                    dropdown.innerHTML = '';
                    return;
                }

                searchDebounceTimer = setTimeout(() => {
                    fetchStationSuggestions(query, dropdown, null, null);
                }, 250);
            });
        }

        // Haltestellen-Autocomplete in Modal-Feldern
        document.querySelectorAll('.js-station-autocomplete').forEach(input => {
            input.addEventListener('input', function () {
                const query = this.value.trim();
                let dropdown = this.parentElement.querySelector('.transit-search-dropdown');
                if (!dropdown) {
                    dropdown = document.createElement('div');
                    dropdown.className = 'transit-search-dropdown';
                    this.parentElement.style.position = 'relative';
                    this.parentElement.appendChild(dropdown);
                }

                clearTimeout(searchDebounceTimer);
                if (query.length < 2) {
                    dropdown.classList.add('hidden');
                    dropdown.innerHTML = '';
                    return;
                }

                const targetInputId = this.id;
                const targetHiddenId = this.id.replace('_name', '_id');

                searchDebounceTimer = setTimeout(() => {
                    fetchStationSuggestions(query, dropdown, targetInputId, targetHiddenId);
                }, 250);
            });
        });
    }

    async function fetchStationSuggestions(query, dropdownEl, targetInputId, targetHiddenId) {
        try {
            const resp = await fetch(`api.php?action=search_stations&q=${encodeURIComponent(query)}`);
            const res = await resp.json();
            if (!res.success || !Array.isArray(res.data) || res.data.length === 0) {
                dropdownEl.innerHTML = '<div class="transit-dropdown-empty text-muted">Keine Haltestellen gefunden</div>';
                dropdownEl.classList.remove('hidden');
                return;
            }

            let html = '';
            for (const item of res.data) {
                html += `
                    <div class="transit-dropdown-item js-search-item" 
                         data-id="${KaiHtml.escape(item.id)}" 
                         data-name="${KaiHtml.escape(item.name)}"
                         data-target-input="${targetInputId ? KaiHtml.escape(targetInputId) : ''}"
                         data-target-hidden="${targetHiddenId ? KaiHtml.escape(targetHiddenId) : ''}">
                        <span class="transit-dropdown-icon">📍</span>
                        <span class="transit-dropdown-name">${KaiHtml.escape(item.name)}</span>
                        <span class="transit-dropdown-id text-muted">ID: ${KaiHtml.escape(item.id)}</span>
                    </div>
                `;
            }

            dropdownEl.innerHTML = html;
            dropdownEl.classList.remove('hidden');
        } catch (e) {
            console.error('Haltestellensuche fehlgeschlagen', e);
        }
    }

    function getLvbBadge(dep) {
        if (dep && dep.badge && dep.badge.css_class) {
            return dep.badge;
        }
        const line = (dep && dep.line ? String(dep.line) : '').trim().toUpperCase();
        const mode = dep && dep.mode ? dep.mode : '';
        const num = parseInt(line.replace(/\D/g, ''), 10) || 0;

        if (mode === 'suburban' || line.startsWith('S')) {
            return { css_class: 'transit-shape-circle transit-lvb-sbahn', bg: '#008638', text_color: '#ffffff' };
        }
        if (mode === 'train' || line.startsWith('RE') || line.startsWith('RB')) {
            return { css_class: 'transit-shape-square transit-lvb-train', bg: '#ba0c2f', text_color: '#ffffff' };
        }

        const isTram = (mode === 'tram') || line === 'N10' || line === 'N17' || (num >= 1 && num <= 49 && !line.startsWith('N'));
        if (isTram) {
            if (line === '2' || line === '8') {
                return { css_class: 'transit-shape-square transit-lvb-yellow', bg: '#ffd200', text_color: '#000000' };
            }
            if (line === '1' || line === '3' || line === 'N17') {
                return { css_class: 'transit-shape-square transit-lvb-green', bg: '#50af28', text_color: '#ffffff' };
            }
            if (['4', '7', '12', '15'].includes(line)) {
                return { css_class: 'transit-shape-square transit-lvb-blue', bg: '#003882', text_color: '#ffffff' };
            }
            if (['10', '11', '14', '16', 'N10'].includes(line)) {
                return { css_class: 'transit-shape-square transit-lvb-red', bg: '#e2001a', text_color: '#ffffff' };
            }
            if (num >= 30 && num <= 49) {
                return { css_class: 'transit-shape-square transit-lvb-orange', bg: '#f37021', text_color: '#ffffff' };
            }
            return { css_class: 'transit-shape-square transit-lvb-blue', bg: '#003882', text_color: '#ffffff' };
        }

        // Busse (N1..N9 Nightliner dunkelblau, alle anderen lila)
        if (/^N[1-9]$/i.test(line)) {
            return { css_class: 'transit-shape-circle transit-lvb-nightblue', bg: '#003882', text_color: '#ffffff' };
        }
        return { css_class: 'transit-shape-circle transit-lvb-purple', bg: '#8e126e', text_color: '#ffffff' };
    }

    async function loadStationBoard(stationId, stationName) {
        const container = document.getElementById('free-board-container');
        const titleEl = document.getElementById('free-board-title');
        const tbody = document.getElementById('free-board-tbody');
        if (!container || !titleEl || !tbody) return;

        titleEl.textContent = `Live-Abfahrten: ${stationName}`;
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Lade Live-Zeiten...</td></tr>';
        container.classList.remove('hidden');

        try {
            const resp = await fetch(`api.php?action=station_departures&station_id=${encodeURIComponent(stationId)}&station_name=${encodeURIComponent(stationName)}`);
            const res = await resp.json();

            if (!res.success || !res.data || !Array.isArray(res.data.departures) || res.data.departures.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Keine Abfahrten in den nächsten 60 Minuten gemeldet.</td></tr>';
                return;
            }

            let html = '';
            for (const dep of res.data.departures) {
                let statusHtml = '<span class="text-muted">Fahrplan</span>';
                if (dep.is_cancelled) {
                    statusHtml = '<span class="badge badge-danger">Ausfall</span>';
                } else if (dep.delay_minutes > 0) {
                    statusHtml = `<span class="text-danger">+${dep.delay_minutes} Min</span>`;
                } else if (dep.has_realtime) {
                    statusHtml = '<span class="text-success">Pünktlich</span>';
                }

                const inMinutes = (dep.countdown_minutes !== null && dep.countdown_minutes >= 0) ? `${dep.countdown_minutes} Min` : '–';
                const b = getLvbBadge(dep);

                html += `
                    <tr class="${dep.is_cancelled ? 'transit-row-cancelled' : ''}">
                        <td data-label="Linie">
                            <span class="transit-line-pill ${KaiHtml.escape(b.css_class)}" style="background-color: ${KaiHtml.escape(b.bg)}; color: ${KaiHtml.escape(b.text_color)};">
                                ${KaiHtml.escape(dep.line)}
                            </span>
                        </td>
                        <td data-label="Richtung" class="transit-col-dir">${KaiHtml.escape(dep.direction)}</td>
                        <td data-label="Plan" class="text-muted">${KaiHtml.escape(dep.scheduled_time)}</td>
                        <td data-label="Echtzeit"><strong>${KaiHtml.escape(dep.real_time)}</strong></td>
                        <td data-label="Status">${statusHtml}</td>
                        <td data-label="In" style="text-align: right;"><strong>${KaiHtml.escape(inMinutes)}</strong></td>
                    </tr>
                `;
            }

            tbody.innerHTML = html;
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Fehler beim Laden der Abfahrten.</td></tr>';
        }
    }

    async function reloadProfileData() {
        const card = document.querySelector('.transit-profile-card');
        if (!card) return;

        const profileId = card.dataset.profileId;
        const direction = card.dataset.direction || 'outbound';

        try {
            const resp = await fetch(`api.php?action=status&profile_id=${encodeURIComponent(profileId)}&direction=${encodeURIComponent(direction)}`);
            const res = await resp.json();
            if (!res.success || !res.data) return;

            const data = res.data;

            // Hero aktualisieren
            const nextDep = data.next_departure;
            const heroBadge = document.querySelector('.transit-hero-left .transit-line-badge');
            const heroReal = document.querySelector('.transit-hero-real');
            const heroDelay = document.querySelector('.transit-hero-delay');
            const countdownVal = document.querySelector('.transit-countdown-val');

            if (nextDep) {
                if (heroBadge) {
                    const b = getLvbBadge(nextDep);
                    heroBadge.textContent = nextDep.line;
                    heroBadge.className = `transit-line-badge ${b.css_class}`;
                    heroBadge.style.backgroundColor = b.bg;
                    heroBadge.style.color = b.text_color;
                }
                if (heroReal) {
                    heroReal.textContent = `${nextDep.real_time} Uhr`;
                }
                if (countdownVal && nextDep.countdown_minutes !== null && nextDep.countdown_minutes >= 0) {
                    countdownVal.textContent = nextDep.countdown_minutes;
                }
                if (heroDelay) {
                    if (nextDep.delay_minutes > 0) {
                        heroDelay.textContent = `+${nextDep.delay_minutes} Min`;
                        heroDelay.className = 'transit-hero-delay text-danger';
                    } else if (nextDep.has_realtime) {
                        heroDelay.textContent = 'pünktlich';
                        heroDelay.className = 'transit-hero-delay text-success';
                    }
                }
            }

            // Tabelle aktualisieren
            const tbody = document.querySelector('.js-departures-tbody');
            if (tbody && Array.isArray(data.departures)) {
                let html = '';
                for (const dep of data.departures) {
                    let statusHtml = '<span class="text-muted">Fahrplan</span>';
                    if (dep.is_cancelled) {
                        statusHtml = '<span class="badge badge-danger">Ausfall</span>';
                    } else if (dep.delay_minutes > 0) {
                        statusHtml = `<span class="text-danger">+${dep.delay_minutes} Min</span>`;
                    } else if (dep.has_realtime) {
                        statusHtml = '<span class="text-success">Pünktlich</span>';
                    }

                    const inMin = (dep.countdown_minutes !== null && dep.countdown_minutes >= 0) ? `${dep.countdown_minutes} Min` : '–';
                    const b = getLvbBadge(dep);

                    html += `
                        <tr class="${dep.is_cancelled ? 'transit-row-cancelled' : ''}">
                            <td data-label="Linie">
                                <span class="transit-line-pill ${KaiHtml.escape(b.css_class)}" style="background-color: ${KaiHtml.escape(b.bg)}; color: ${KaiHtml.escape(b.text_color)};">
                                    ${KaiHtml.escape(dep.line)}
                                </span>
                            </td>
                            <td data-label="Richtung" class="transit-col-dir">${KaiHtml.escape(dep.direction)}</td>
                            <td data-label="Plan" class="text-muted">${KaiHtml.escape(dep.scheduled_time)}</td>
                            <td data-label="Echtzeit"><strong>${KaiHtml.escape(dep.real_time)}</strong></td>
                            <td data-label="Status">${statusHtml}</td>
                            <td data-label="In" style="text-align: right;"><strong>${KaiHtml.escape(inMin)}</strong></td>
                        </tr>
                    `;
                }
                tbody.innerHTML = html;
            }
        } catch (err) {
            console.warn('Hintergrund-Aktualisierung fehlgeschlagen', err);
        }
    }

    function openProfileModal(data) {
        const modal = document.getElementById('profile-modal');
        const form = document.getElementById('profile-form');
        const title = document.getElementById('profile-modal-title');
        const deleteBtn = document.getElementById('btn-delete-profile');
        if (!modal || !form) return;

        form.reset();

        if (data && data.id) {
            if (title) title.textContent = 'Pendlerprofil bearbeiten';
            if (deleteBtn) deleteBtn.style.display = 'inline-block';

            document.getElementById('p_id').value = data.id || '';
            document.getElementById('p_name').value = data.profile_name || '';
            document.getElementById('p_lines').value = data.preferred_lines || '';
            document.getElementById('p_modes').value = data.transport_modes || 'all';
            document.getElementById('p_transfer_min').value = data.min_transfer_minutes || 4;

            document.getElementById('p_origin_name').value = data.origin_station_name || '';
            document.getElementById('p_origin_id').value = data.origin_station_id || '';
            document.getElementById('p_via_name').value = data.via_station_name || '';
            document.getElementById('p_via_id').value = data.via_station_id || '';
            document.getElementById('p_dest_name').value = data.destination_station_name || '';
            document.getElementById('p_dest_id').value = data.destination_station_id || '';

            const activeDays = Array.isArray(data.active_days) ? data.active_days : [1, 2, 3, 4, 5];
            form.querySelectorAll('input[name="active_days[]"]').forEach(cb => {
                cb.checked = activeDays.includes(parseInt(cb.value, 10));
            });

            document.getElementById('p_school_days').checked = Boolean(data.only_school_days);
            document.getElementById('p_active').checked = Boolean(data.is_active);
        } else {
            if (title) title.textContent = 'Neues Pendlerprofil anlegen';
            if (deleteBtn) deleteBtn.style.display = 'none';
            document.getElementById('p_id').value = '';
            form.querySelectorAll('input[name="active_days[]"]').forEach(cb => {
                cb.checked = [1, 2, 3, 4, 5].includes(parseInt(cb.value, 10));
            });
            document.getElementById('p_active').checked = true;
        }

        modal.classList.remove('hidden');
    }

    function closeProfileModal() {
        const modal = document.getElementById('profile-modal');
        if (modal) modal.classList.add('hidden');
    }

    async function handleSaveProfile(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);

        const days = [];
        form.querySelectorAll('input[name="active_days[]"]:checked').forEach(cb => {
            days.push(parseInt(cb.value, 10));
        });

        const profileObj = {
            id: formData.get('id') ? parseInt(formData.get('id'), 10) : null,
            profile_name: formData.get('profile_name'),
            origin_station_name: formData.get('origin_station_name'),
            origin_station_id: formData.get('origin_station_id'),
            via_station_name: formData.get('via_station_name') || null,
            via_station_id: formData.get('via_station_id') || null,
            destination_station_name: formData.get('destination_station_name'),
            destination_station_id: formData.get('destination_station_id'),
            preferred_lines: formData.get('preferred_lines') || null,
            transport_modes: formData.get('transport_modes') || 'all',
            active_days: days,
            only_school_days: formData.get('only_school_days') ? 1 : 0,
            is_active: formData.get('is_active') ? 1 : 0,
            min_transfer_minutes: parseInt(formData.get('min_transfer_minutes') || '4', 10),
        };

        try {
            const res = await KaiHttp.postJson('api.php', {
                action: 'save_profile',
                profile: profileObj
            });

            if (res.success) {
                closeProfileModal();
                const targetId = res.data?.id || profileObj.id;
                window.location.href = `index.php?profile=${encodeURIComponent(targetId)}`;
            } else {
                alert('Fehler beim Speichern: ' + (res.message || 'Unbekannt'));
            }
        } catch (err) {
            alert('Netzwerkfehler beim Speichern des Profils');
        }
    }

    async function handleDeleteProfile() {
        const idInput = document.getElementById('p_id');
        const id = idInput ? idInput.value : null;
        if (!id) return;

        if (!confirm('Möchtest du dieses Pendlerprofil wirklich löschen?')) {
            return;
        }

        try {
            const res = await KaiHttp.postJson('api.php', {
                action: 'delete_profile',
                id: parseInt(id, 10)
            });

            if (res.success) {
                closeProfileModal();
                window.location.href = 'index.php';
            } else {
                alert('Fehler beim Löschen des Profils');
            }
        } catch (err) {
            alert('Netzwerkfehler beim Löschen des Profils');
        }
    }

    function startAutoRefresh() {
        if (autoRefreshTimer) clearInterval(autoRefreshTimer);
        // Alle 60 Sekunden aktualisieren, sofern Seite im Vordergrund ist
        autoRefreshTimer = setInterval(() => {
            if (!document.hidden) {
                reloadProfileData();
            }
        }, 60000);
    }
})();

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

        // Verbindungen asynchron beim ersten Seitenaufruf nachladen (nur wenn Profilkarte sichtbar)
        const card = document.querySelector('.transit-profile-card');
        if (card && card.dataset.profileId && !card.classList.contains('hidden')) {
            loadTripRecommendations(card.dataset.profileId, card.dataset.direction || 'outbound');
        }
    }

    function initEventListeners() {
        // Popstate für Browser-Vor/Zurück
        window.addEventListener('popstate', function () {
            window.location.reload();
        });

        // Event-Delegation für Klick-Aktionen
        document.addEventListener('click', function (e) {
            // 0. Profil-Reiter umschalten (sofortiges Feedback & asynchrones Nachladen)
            const tabBtn = e.target.closest('.transit-tab-btn[data-profile-id]');
            if (tabBtn) {
                const profileId = tabBtn.dataset.profileId;
                if (profileId) {
                    e.preventDefault();
                    // Sofortiges visuelles Feedback auf den Reitern
                    document.querySelectorAll('.transit-tab-bar .transit-tab-btn').forEach(b => b.classList.remove('active'));
                    tabBtn.classList.add('active');

                    // Haltestellensuche verstecken, Profilansicht anzeigen
                    const searchSec = document.querySelector('.js-search-section');
                    if (searchSec) searchSec.classList.add('hidden');

                    const card = document.querySelector('.transit-profile-card');
                    if (card) {
                        card.classList.remove('hidden');
                        // URL ohne Seiten-Reload aktualisieren
                        history.pushState(null, '', `index.php?profile=${encodeURIComponent(profileId)}`);
                        // Profil asynchron laden
                        switchToProfile(profileId);
                    } else {
                        window.location.href = `index.php?profile=${encodeURIComponent(profileId)}`;
                    }
                    return;
                }
            }

            // 0b. Haltestellensuche-Reiter umschalten
            const searchTabBtn = e.target.closest('.transit-tab-btn[data-tab="search"]');
            if (searchTabBtn) {
                e.preventDefault();
                document.querySelectorAll('.transit-tab-bar .transit-tab-btn').forEach(b => b.classList.remove('active'));
                searchTabBtn.classList.add('active');

                // Profilansicht verstecken, Haltestellensuche anzeigen
                const card = document.querySelector('.transit-profile-card');
                if (card) card.classList.add('hidden');

                const searchSec = document.querySelector('.js-search-section');
                if (searchSec) {
                    searchSec.classList.remove('hidden');
                    const input = document.getElementById('station-search-input');
                    if (input) input.focus();
                }

                history.pushState(null, '', 'index.php?tab=search');
                return;
            }

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
                history.pushState(null, '', `index.php?profile=${encodeURIComponent(profileId)}&dir=${encodeURIComponent(newDir)}&offset=${encodeURIComponent(offset)}`);
                switchToProfile(profileId, newDir);
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

    function renderDepartureBadgesHtml(departures) {
        if (!Array.isArray(departures) || departures.length === 0) {
            return '<div class="transit-departures-empty text-muted">Keine weiteren Abfahrten in den nächsten 60 Minuten gemeldet.</div>';
        }

        let html = '';
        for (const dep of departures) {
            const b = getLvbBadge(dep);
            const countdownText = (dep.countdown_minutes !== null && dep.countdown_minutes >= 0)
                ? `in ${dep.countdown_minutes} Min`
                : (dep.countdown_minutes === 0 ? 'Jetzt' : '–');

            let countdownBadgeHtml = '';
            if (dep.is_cancelled) {
                countdownBadgeHtml = '<span class="badge badge-danger">Ausfall</span>';
            } else if (dep.countdown_minutes !== null && dep.countdown_minutes >= 0) {
                countdownBadgeHtml = `<span class="badge badge-success">${KaiHtml.escape(countdownText)}</span>`;
            }

            let statusHtml = '<span class="text-muted">Fahrplan</span>';
            if (dep.is_cancelled) {
                statusHtml = '<span class="text-danger font-semibold">Fahrt entfällt</span>';
            } else if (dep.delay_minutes > 0) {
                statusHtml = `<span class="text-danger font-semibold">+${dep.delay_minutes} Min</span>`;
            } else if (dep.has_realtime) {
                statusHtml = '<span class="text-success font-semibold">Pünktlich</span>';
            }

            let timesHtml = `<span class="transit-dep-time-real">${KaiHtml.escape(dep.real_time)} Uhr</span>`;
            if (dep.delay_minutes > 0) {
                timesHtml += ` <span class="transit-dep-time-plan text-muted">(Plan ${KaiHtml.escape(dep.scheduled_time)})</span>`;
            }

            html += `
                <div class="transit-dep-badge ${dep.is_cancelled ? 'transit-dep-cancelled' : ''}">
                    <div class="transit-dep-badge-row transit-dep-badge-top">
                        <div class="transit-dep-badge-line">
                            <span class="transit-line-pill ${KaiHtml.escape(b.css_class)}" style="background-color: ${KaiHtml.escape(b.bg)}; color: ${KaiHtml.escape(b.text_color)};">
                                ${KaiHtml.escape(dep.line)}
                            </span>
                            <span class="transit-dep-badge-dir" title="Richtung ${KaiHtml.escape(dep.direction)}">
                                ${KaiHtml.escape(dep.direction)}
                            </span>
                        </div>
                        <div class="transit-dep-badge-countdown">
                            ${countdownBadgeHtml}
                        </div>
                    </div>
                    <div class="transit-dep-badge-row transit-dep-badge-bottom">
                        <div class="transit-dep-badge-times">
                            ${timesHtml}
                        </div>
                        <div class="transit-dep-badge-status">
                            ${statusHtml}
                        </div>
                    </div>
                </div>
            `;
        }
        return html;
    }

    async function loadStationBoard(stationId, stationName) {
        const container = document.getElementById('free-board-container');
        const titleEl = document.getElementById('free-board-title');
        const badgesEl = document.getElementById('free-board-badges');
        if (!container || !titleEl || !badgesEl) return;

        titleEl.textContent = `Live-Abfahrten: ${stationName}`;
        badgesEl.innerHTML = '<div class="transit-departures-empty text-muted">Lade Live-Zeiten...</div>';
        container.classList.remove('hidden');

        try {
            const resp = await fetch(`api.php?action=station_departures&station_id=${encodeURIComponent(stationId)}&station_name=${encodeURIComponent(stationName)}`);
            const res = await resp.json();

            if (!res.success || !res.data || !Array.isArray(res.data.departures) || res.data.departures.length === 0) {
                badgesEl.innerHTML = '<div class="transit-departures-empty text-muted">Keine Abfahrten in den nächsten 60 Minuten gemeldet.</div>';
                return;
            }

            badgesEl.innerHTML = renderDepartureBadgesHtml(res.data.departures);
        } catch (e) {
            badgesEl.innerHTML = '<div class="transit-departures-empty text-danger">Fehler beim Laden der Abfahrten.</div>';
        }
    }

    async function switchToProfile(profileId, direction = null) {
        const card = document.querySelector('.transit-profile-card');
        if (!card) return;

        // 1. Loading-Zustand (Transparenz) auf der Karte aktivieren
        card.classList.add('transit-card-loading');
        card.dataset.profileId = profileId;
        if (direction) {
            card.dataset.direction = direction;
        }

        // 2. Sofort Platzhalter für Verbindungen zeigen
        const tripsList = document.querySelector('.js-trips-list');
        if (tripsList) {
            tripsList.innerHTML = '<div class="transit-departures-empty text-muted js-trips-placeholder"><span class="transit-spinner"></span> Suche beste Verbindungen &amp; Alternativen (Echtzeit)...</div>';
        }

        const currentDir = direction || card.dataset.direction || 'outbound';

        // 3. Status und Abfahrten entkoppelt und schnell laden
        try {
            const resp = await fetch(`api.php?action=status&include_trips=0&profile_id=${encodeURIComponent(profileId)}&direction=${encodeURIComponent(currentDir)}`);
            const res = await resp.json();
            if (res.success && res.data) {
                applyProfileData(res.data);
            } else {
                window.location.href = `index.php?profile=${encodeURIComponent(profileId)}`;
                return;
            }
        } catch (err) {
            console.warn('Schnellumschaltung fehlgeschlagen', err);
            window.location.href = `index.php?profile=${encodeURIComponent(profileId)}`;
            return;
        } finally {
            card.classList.remove('transit-card-loading');
        }

        // 4. Tür-zu-Tür Verbindungen asynchron im Hintergrund laden
        loadTripRecommendations(profileId, card.dataset.direction || 'outbound');
    }

    function applyProfileData(data) {
        const card = document.querySelector('.transit-profile-card');
        if (!card || !data) return;

        card.dataset.profileId = data.profile_id;
        card.dataset.direction = data.direction;

        // Richtung & Haltestellennamen im Header
        const dirTag = card.querySelector('.transit-direction-tag');
        if (dirTag) dirTag.textContent = data.direction_label;

        const fromStation = card.querySelector('.transit-station-from');
        if (fromStation) fromStation.textContent = data.from_station;

        const toStation = card.querySelector('.transit-station-to');
        if (toStation) toStation.textContent = data.to_station;

        // Status-Pill
        const statusPill = card.querySelector('.transit-status-pill');
        if (statusPill) {
            statusPill.className = `transit-status-pill transit-status--${KaiHtml.escape(data.status_severity)}`;
            statusPill.innerHTML = `<span class="transit-status-dot"></span> ${KaiHtml.escape(data.status_text)}`;
        }

        // Hero-Abfahrt
        const nextDep = data.next_departure;
        const heroBadge = card.querySelector('.transit-hero-left .transit-line-badge');
        const heroReal = card.querySelector('.transit-hero-real');
        const heroDelay = card.querySelector('.transit-hero-delay');
        const heroDir = card.querySelector('.transit-hero-direction');
        const countdownVal = card.querySelector('.transit-countdown-val');
        const countdownUnit = card.querySelector('.transit-countdown-unit');

        if (nextDep) {
            if (heroBadge) {
                const b = getLvbBadge(nextDep);
                heroBadge.textContent = nextDep.line;
                heroBadge.className = `transit-line-badge ${b.css_class}`;
                heroBadge.style.backgroundColor = b.bg;
                heroBadge.style.color = b.text_color;
            }
            if (heroDir) {
                heroDir.textContent = `Richtung ${nextDep.direction}`;
            }
            if (heroReal) {
                heroReal.textContent = `${nextDep.real_time} Uhr`;
            }
            if (countdownVal) {
                if (nextDep.countdown_minutes !== null && nextDep.countdown_minutes >= 0) {
                    countdownVal.textContent = nextDep.countdown_minutes;
                    if (countdownUnit) countdownUnit.textContent = 'Min';
                } else {
                    countdownVal.textContent = 'Sofort';
                    if (countdownUnit) countdownUnit.textContent = '';
                }
            }
            if (heroDelay) {
                if (nextDep.delay_minutes > 0) {
                    heroDelay.textContent = `+${nextDep.delay_minutes} Min`;
                    heroDelay.className = 'transit-hero-delay text-danger';
                } else if (nextDep.has_realtime) {
                    heroDelay.textContent = 'pünktlich';
                    heroDelay.className = 'transit-hero-delay text-success';
                } else {
                    heroDelay.textContent = '';
                }
            }
        }

        // Umstiegs- & Anschlusskarte
        const transferWrap = card.querySelector('.js-transfer-wrap');
        if (transferWrap) {
            if (data.transfer_status) {
                const tf = data.transfer_status;
                const viaEl = transferWrap.querySelector('.js-transfer-via');
                const msgEl = transferWrap.querySelector('.js-transfer-msg');
                const pillEl = transferWrap.querySelector('.js-transfer-pill');
                const dirEl = transferWrap.querySelector('.js-transfer-dir');
                const timeEl = transferWrap.querySelector('.js-transfer-time');
                const delayEl = transferWrap.querySelector('.js-transfer-delay');

                if (viaEl) viaEl.textContent = tf.via_station;
                if (msgEl) msgEl.textContent = tf.message;
                if (pillEl) {
                    const badge = tf.connecting_badge || {};
                    pillEl.textContent = tf.connecting_line;
                    pillEl.className = `transit-line-pill js-transfer-pill ${badge.css_class || 'transit-shape-square transit-lvb-blue'}`;
                    pillEl.style.backgroundColor = badge.bg || '#003882';
                    pillEl.style.color = badge.text_color || '#ffffff';
                }
                if (dirEl) dirEl.textContent = tf.connecting_direction;
                if (timeEl) timeEl.textContent = tf.connecting_time;
                if (delayEl) {
                    if (tf.connecting_delay > 0) {
                        delayEl.textContent = `(+${tf.connecting_delay} Min)`;
                        delayEl.classList.remove('hidden');
                    } else {
                        delayEl.classList.add('hidden');
                    }
                }
                transferWrap.classList.remove('hidden');
            } else {
                transferWrap.classList.add('hidden');
            }
        }

        // Weitere Abfahrten als Badges aktualisieren (ohne Hero-Abfahrt)
        const badgesContainer = card.querySelector('.js-departures-badges');
        if (badgesContainer && Array.isArray(data.departures)) {
            const furtherDeps = data.departures.filter(dep => {
                if (!nextDep) return true;
                if (dep.journey_id && nextDep.journey_id) {
                    return dep.journey_id !== nextDep.journey_id;
                }
                return !(dep.line === nextDep.line && dep.real_time === nextDep.real_time);
            });
            badgesContainer.innerHTML = renderDepartureBadgesHtml(furtherDeps);
        }
    }

    async function loadTripRecommendations(profileId, direction) {
        const tripsList = document.querySelector('.js-trips-list');
        if (!tripsList) return;

        try {
            const resp = await fetch(`api.php?action=trips&profile_id=${encodeURIComponent(profileId)}&direction=${encodeURIComponent(direction || 'outbound')}`);
            const res = await resp.json();

            if (res.success && Array.isArray(res.data)) {
                tripsList.innerHTML = renderTripCardsHtml(res.data);
            } else {
                tripsList.innerHTML = '<div class="transit-departures-empty text-muted">Keine Tür-zu-Tür Verbindungen für den aktuellen Zeitpunkt gefunden.</div>';
            }
        } catch (err) {
            console.warn('Verbindungssuche fehlgeschlagen', err);
            tripsList.innerHTML = '<div class="transit-departures-empty text-muted">Verbindungen konnten nicht geladen werden. Bitte erneut versuchen.</div>';
        }
    }

    async function reloadProfileData() {
        const card = document.querySelector('.transit-profile-card');
        if (!card) return;

        const profileId = card.dataset.profileId;
        const direction = card.dataset.direction || 'outbound';

        try {
            const resp = await fetch(`api.php?action=status&include_trips=0&profile_id=${encodeURIComponent(profileId)}&direction=${encodeURIComponent(direction)}`);
            const res = await resp.json();
            if (res.success && res.data) {
                applyProfileData(res.data);
            }
        } catch (err) {
            console.warn('Hintergrund-Aktualisierung fehlgeschlagen', err);
        }

        loadTripRecommendations(profileId, direction);
    }

    function renderTripCardsHtml(trips) {
        if (!Array.isArray(trips) || trips.length === 0) {
            return '<div class="transit-departures-empty text-muted">Keine Tür-zu-Tür Verbindungen für den aktuellen Zeitpunkt gefunden.</div>';
        }

        let tripHtml = '';
        for (const con of trips) {
            const transfersLabel = con.transfers === 0 ? 'Direkt' : (con.transfers === 1 ? '1 Umstieg' : `${con.transfers} Umstiege`);
            let metaBadge = '';
            if (con.is_cancelled) {
                metaBadge = '<span class="badge badge-danger">Fahrt fällt aus</span>';
            } else if (con.delay_minutes > 0) {
                metaBadge = `<span class="text-danger">+${con.delay_minutes} Min</span>`;
            } else if (con.countdown_minutes !== null && con.countdown_minutes >= 0) {
                metaBadge = `<span class="badge badge-success">in ${con.countdown_minutes} Min</span>`;
            }

            let legsHtml = '';
            const legs = Array.isArray(con.legs) ? con.legs : [];

            // Abschnitte vertikal rendern mit Benennung der Umstiegshaltestelle
            for (let i = 0; i < legs.length; i++) {
                const leg = legs[i];
                if (leg.type === 'transport') {
                    const b = getLvbBadge(leg);
                    const depTime = leg.departure_time ? `<span class="transit-leg-time">(ab ${KaiHtml.escape(leg.departure_time)})</span>` : '';
                    legsHtml += `
                        <div class="transit-trip-leg-row">
                            <span class="transit-line-pill ${KaiHtml.escape(b.css_class)}" style="background-color: ${KaiHtml.escape(b.bg)}; color: ${KaiHtml.escape(b.text_color)};">
                                ${KaiHtml.escape(leg.line)}
                            </span>
                            <span class="transit-leg-line-dir">Richtung <strong>${KaiHtml.escape(leg.direction)}</strong></span>
                            ${depTime}
                        </div>
                    `;

                    // Prüfen, ob nach diesem Leg ein Umstieg zu einem weiteren Transport-Leg erfolgt
                    let nextTransportLeg = null;
                    let walkBetweenMinutes = 0;
                    for (let j = i + 1; j < legs.length; j++) {
                        if (legs[j].type === 'walk') {
                            walkBetweenMinutes += (legs[j].duration_minutes || 0);
                        } else if (legs[j].type === 'transport') {
                            nextTransportLeg = legs[j];
                            break;
                        }
                    }

                    if (nextTransportLeg) {
                        const transferStation = leg.to_name || nextTransportLeg.from_name || 'Umstieg';
                        const walkNote = walkBetweenMinutes > 0 ? `<span class="transit-trip-walk">🚶 ${KaiHtml.escape(walkBetweenMinutes)}m Fußweg</span>` : '';
                        legsHtml += `
                            <div class="transit-trip-transfer-point">
                                <span class="transit-transfer-node">↳</span>
                                <span class="transit-transfer-station">Umstieg an <strong>${KaiHtml.escape(transferStation)}</strong></span>
                                ${walkNote}
                            </div>
                        `;
                    }
                } else if (leg.type === 'walk' && i === 0 && (leg.duration_minutes || 0) > 0) {
                    legsHtml += `
                        <div class="transit-trip-walk-row">
                            <span class="transit-trip-walk">🚶 ${KaiHtml.escape(leg.duration_minutes)}m Fußweg ab ${KaiHtml.escape(leg.from_name || 'Start')}</span>
                        </div>
                    `;
                }
            }

            tripHtml += `
                <div class="transit-trip-card">
                    <div class="transit-trip-summary">
                        <div class="transit-trip-times">
                            <strong>${KaiHtml.escape(con.departure_time)}</strong>
                            <span class="transit-trip-arrow">&rarr;</span>
                            <strong>${KaiHtml.escape(con.arrival_time)}</strong>
                            <span class="transit-trip-duration">(${KaiHtml.escape(con.duration_minutes)} Min)</span>
                        </div>
                        <div class="transit-trip-meta">
                            <span class="transit-trip-transfers">${KaiHtml.escape(transfersLabel)}</span>
                            ${metaBadge}
                        </div>
                    </div>
                    <div class="transit-trip-legs--vertical">
                        ${legsHtml}
                    </div>
                </div>
            `;
        }
        return tripHtml;
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

            const userEmailEl = document.getElementById('p_user_email');
            if (userEmailEl) {
                if (userEmailEl.tagName === 'SELECT') {
                    if (data.user_email) {
                        const val = data.user_email.toLowerCase();
                        let optionExists = false;
                        for (let i = 0; i < userEmailEl.options.length; i++) {
                            if (userEmailEl.options[i].value === val) {
                                optionExists = true;
                                break;
                            }
                        }
                        if (!optionExists) {
                            const opt = document.createElement('option');
                            opt.value = val;
                            opt.textContent = val;
                            userEmailEl.appendChild(opt);
                        }
                        userEmailEl.value = val;
                    } else {
                        userEmailEl.value = '';
                    }
                } else {
                    userEmailEl.value = (userEmailEl.dataset.currentUser || userEmailEl.value || '').toLowerCase();
                }
            }

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

            const userEmailEl = document.getElementById('p_user_email');
            if (userEmailEl) {
                if (userEmailEl.tagName === 'SELECT') {
                    userEmailEl.value = (userEmailEl.dataset.currentUser || '').toLowerCase();
                } else {
                    userEmailEl.value = (userEmailEl.dataset.currentUser || userEmailEl.value || '').toLowerCase();
                }
            }

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
            user_email: formData.get('user_email') ? formData.get('user_email').trim() : null,
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

/**
 * Frontend-Logik für die Reisekosten- & Ladeplanung (Car Domain).
 *
 * Verwaltet Modals, Routen-Neuberechnungen, Ladeschritt-Status
 * und den 1-Klick-Buchungsabgleich (Transaktions-Matching) via Event Delegation.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        initCarTrips();
    });

    function initCarTrips() {
        const tripDetailModal = document.getElementById('trip-detail-modal');
        const tripEditModal = document.getElementById('trip-edit-modal');
        const subtripModal = document.getElementById('subtrip-modal');

        // =====================================================================
        // EVENT DELEGATION: Klicks auf Aktionen
        // =====================================================================
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('button, a');
            if (!btn) return;

            // 1. Reise-Details anzeigen
            if (btn.matches('.js-btn-view-trip')) {
                e.preventDefault();
                const tripId = btn.dataset.tripId;
                if (tripId) {
                    await loadTripDetails(tripId);
                }
                return;
            }

            // 2. Neue Reise anlegen (Modal öffnen)
            if (btn.matches('.js-btn-new-trip')) {
                e.preventDefault();
                openNewTripModal();
                return;
            }

            // 3. Ausflug / Sub-Trip anlegen
            if (btn.matches('.js-btn-new-subtrip')) {
                e.preventDefault();
                const parentId = btn.dataset.parentId;
                const parentDest = btn.dataset.parentDest || 'Unterkunft';
                openSubtripModal(parentId, parentDest);
                return;
            }

            // 4. Reise neu berechnen
            if (btn.matches('.js-btn-recalc-trip')) {
                e.preventDefault();
                const tripId = btn.dataset.tripId;
                if (!tripId) return;

                btn.disabled = true;
                const originalText = btn.innerHTML;
                btn.innerHTML = '⏳ Berechne...';

                try {
                    const res = await KaiHttp.postJson('trips_api.php', {
                        action: 'recalculate_trip',
                        id: parseInt(tripId, 10),
                    });

                    if (res && res.success) {
                        await loadTripDetails(tripId);
                        window.location.reload();
                    } else {
                        alert(res?.error || 'Fehler bei der Neuberechnung');
                    }
                } catch (err) {
                    alert('Serverfehler bei Neuberechnung');
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
                return;
            }

            // 5. Reise löschen
            if (btn.matches('.js-btn-delete-trip')) {
                e.preventDefault();
                const tripId = btn.dataset.tripId;
                if (!tripId) return;

                if (!confirm('Soll diese Reise wirklich gelöscht werden? Alle zugehörigen Ladeschritte werden entfernt.')) {
                    return;
                }

                try {
                    const res = await KaiHttp.postJson('trips_api.php', {
                        action: 'delete_trip',
                        id: parseInt(tripId, 10),
                    });

                    if (res && res.success) {
                        window.location.reload();
                    } else {
                        alert(res?.error || 'Löschen fehlgeschlagen');
                    }
                } catch (err) {
                    alert('Serverfehler beim Löschen');
                }
                return;
            }

            // 6. Ladeschritt-Status umschalten (geplant <-> erledigt)
            if (btn.matches('.js-btn-toggle-step')) {
                e.preventDefault();
                const stepId = btn.dataset.stepId;
                const tripId = btn.dataset.tripId;
                const currentStatus = btn.dataset.currentStatus;
                const newStatus = (currentStatus === 'erledigt') ? 'geplant' : 'erledigt';

                try {
                    const res = await KaiHttp.postJson('trips_api.php', {
                        action: 'update_step_status',
                        step_id: parseInt(stepId, 10),
                        status: newStatus,
                    });

                    if (res && res.success) {
                        await loadTripDetails(tripId);
                    }
                } catch (err) {
                    alert('Fehler beim Aktualisieren des Ladeschritts');
                }
                return;
            }

            // 7. Buchung verknüpfen (Zuordnen)
            if (btn.matches('.js-btn-link-tx')) {
                e.preventDefault();
                const tripId = btn.dataset.tripId;
                const txType = btn.dataset.txType;
                const txId = btn.dataset.txId;
                const categorySelect = document.getElementById(`cat-select-${txType}-${txId}`);
                const category = categorySelect ? categorySelect.value : 'charge';

                btn.disabled = true;

                try {
                    const res = await KaiHttp.postJson('trips_api.php', {
                        action: 'link_transaction',
                        trip_id: parseInt(tripId, 10),
                        transaction_type: txType,
                        transaction_id: parseInt(txId, 10),
                        cost_category: category,
                    });

                    if (res && res.success) {
                        await loadTripDetails(tripId);
                    } else {
                        alert(res?.error || 'Zuordnung fehlgeschlagen');
                    }
                } catch (err) {
                    alert('Fehler beim Zuordnen der Buchung');
                } finally {
                    btn.disabled = false;
                }
                return;
            }

            // 8. Buchung entfernen (Entkoppeln)
            if (btn.matches('.js-btn-unlink-tx')) {
                e.preventDefault();
                const tripId = btn.dataset.tripId;
                const txType = btn.dataset.txType;
                const txId = btn.dataset.txId;

                btn.disabled = true;

                try {
                    const res = await KaiHttp.postJson('trips_api.php', {
                        action: 'unlink_transaction',
                        trip_id: parseInt(tripId, 10),
                        transaction_type: txType,
                        transaction_id: parseInt(txId, 10),
                    });

                    if (res && res.success) {
                        await loadTripDetails(tripId);
                    } else {
                        alert(res?.error || 'Entkopplung fehlgeschlagen');
                    }
                } catch (err) {
                    alert('Fehler beim Lösen der Buchung');
                } finally {
                    btn.disabled = false;
                }
                return;
            }

            // 9. Modals schließen
            if (btn.matches('.js-btn-close-modal')) {
                e.preventDefault();
                closeAllModals();
                return;
            }
        });

        // =====================================================================
        // EVENT DELEGATION: Formularübermittlungen
        // =====================================================================
        document.addEventListener('submit', async (e) => {
            const form = e.target;

            // Formular: Hauptreise speichern
            if (form.matches('#trip-edit-form')) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;

                const payload = {
                    action: 'save_trip',
                    trip: {
                        id: form.trip_id.value ? parseInt(form.trip_id.value, 10) : null,
                        title: form.title.value.trim(),
                        start_address: form.start_address.value.trim(),
                        destination_address: form.destination_address.value.trim(),
                        departure_time: form.departure_time.value,
                        return_time: form.return_time.value || null,
                        is_round_trip: form.is_round_trip ? form.is_round_trip.checked : false,
                        target_arrival_soc: parseInt(form.target_arrival_soc.value, 10) || 10,
                        planned_departure_soc: parseInt(form.planned_departure_soc.value, 10) || 80,
                    }
                };

                try {
                    const res = await KaiHttp.postJson('trips_api.php', payload);
                    if (res && res.success) {
                        closeAllModals();
                        window.location.reload();
                    } else {
                        alert(res?.error || 'Speichern der Reise fehlgeschlagen');
                    }
                } catch (err) {
                    alert('Serverfehler beim Speichern der Reise');
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
                return;
            }

            // Formular: Ausflug / Sub-Trip speichern
            if (form.matches('#subtrip-form')) {
                e.preventDefault();
                const submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;

                const payload = {
                    action: 'save_trip',
                    trip: {
                        parent_trip_id: parseInt(form.parent_trip_id.value, 10),
                        title: form.title.value.trim(),
                        start_address: form.start_address.value.trim(),
                        destination_address: form.destination_address.value.trim(),
                        departure_time: form.departure_time.value,
                        return_time: form.return_time.value || null,
                        is_round_trip: true, // Ausflüge sind standardmäßig Rundfahrten
                        target_arrival_soc: 15,
                        planned_departure_soc: 80,
                    }
                };

                try {
                    const res = await KaiHttp.postJson('trips_api.php', payload);
                    if (res && res.success) {
                        closeAllModals();
                        window.location.reload();
                    } else {
                        alert(res?.error || 'Speichern des Ausflugs fehlgeschlagen');
                    }
                } catch (err) {
                    alert('Serverfehler beim Speichern des Ausflugs');
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
                return;
            }
        });

        // Prüfen, ob eine spezifische Reise-ID über den URL-Parameter trip_id angefordert wurde (z.B. aus Activity Log oder Benachrichtigung)
        const urlParams = new URLSearchParams(window.location.search);
        const urlTripId = urlParams.get('trip_id');
        if (urlTripId) {
            loadTripDetails(urlTripId);
        }
    }

    // =========================================================================
    // MODAL-VERWALTUNG
    // =========================================================================

    function openNewTripModal() {
        const modal = document.getElementById('trip-edit-modal');
        const form = document.getElementById('trip-edit-form');
        if (!modal || !form) return;

        form.reset();
        form.trip_id.value = '';
        document.getElementById('trip-modal-title').textContent = '➕ Neue Reise planen';

        // Standard-Werte setzen
        const now = new Date();
        now.setDate(now.getDate() + 1);
        now.setHours(8, 0, 0, 0);

        const isoDate = now.toISOString().slice(0, 16);
        form.departure_time.value = isoDate;
        form.start_address.value = 'Zuhause';
        form.target_arrival_soc.value = 10;
        form.planned_departure_soc.value = 80;

        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }

    function openSubtripModal(parentId, parentDest) {
        const modal = document.getElementById('subtrip-modal');
        const form = document.getElementById('subtrip-form');
        if (!modal || !form) return;

        form.reset();
        form.parent_trip_id.value = parentId;
        form.start_address.value = parentDest;

        const now = new Date();
        now.setHours(9, 30, 0, 0);
        form.departure_time.value = now.toISOString().slice(0, 16);

        modal.classList.remove('hidden');
        modal.style.display = 'flex';
    }

    function closeAllModals() {
        document.querySelectorAll('.app-modal, .modal-overlay').forEach((m) => {
            m.classList.add('hidden');
            m.style.display = 'none';
        });
    }

    // =========================================================================
    // DETAILANSICHT EINER REISE LADEN & RENDERN
    // =========================================================================

    async function loadTripDetails(tripId) {
        const modal = document.getElementById('trip-detail-modal');
        const container = document.getElementById('trip-detail-content');
        if (!modal || !container) return;

        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        container.innerHTML = '<div style="padding: 2rem; text-align: center;">⏳ Lade Reisedaten...</div>';

        try {
            const res = await fetch(`trips_api.php?action=get&id=${tripId}`);
            const json = await res.json();

            if (!json.success || !json.data) {
                container.innerHTML = `<div class="alert alert-danger">${KaiHtml.escape(json.error || 'Fehler beim Laden')}</div>`;
                return;
            }

            renderTripDetailContent(container, json.data);
        } catch (err) {
            container.innerHTML = '<div class="alert alert-danger">Netzwerkfehler beim Laden der Reise.</div>';
        }
    }

    function renderTripDetailContent(container, data) {
        const trip = data.trip;
        const steps = data.charging_steps || [];
        const transactions = data.transactions || [];
        const candidates = data.candidates || [];
        const subtrips = data.subtrips || [];

        const totalCost = (parseFloat(trip.home_charge_cost || 0) + parseFloat(trip.en_route_charge_cost || 0) + parseFloat(trip.additional_cost || 0)).toFixed(2);
        const dist = parseFloat(trip.total_distance_km || 0);
        const costPer100 = (dist > 0) ? ((totalCost / dist) * 100).toFixed(2) : '0.00';

        const formatTripTime = (str) => {
            if (!str) return '–';
            const m = str.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
            if (m) {
                return `${m[3]}.${m[2]}.${m[1]} ${m[4]}:${m[5]} Uhr`;
            }
            return str;
        };

        const depFormatted = formatTripTime(trip.departure_time);
        const retFormatted = trip.return_time ? formatTripTime(trip.return_time) : null;

        let html = `
            <div class="trip-detail-header" style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; border-bottom:1px solid var(--bg-surface-hover); padding-bottom:1rem; flex-wrap:wrap; gap:1rem;">
                <div>
                    <h2 style="margin:0 0 0.5rem 0; font-size:1.4rem; color:var(--text-main);">${KaiHtml.escape(trip.title)}</h2>
                    <div style="color:var(--text-muted); font-size:0.9rem;">
                        📍 <strong>${KaiHtml.escape(trip.start_address)}</strong> &rarr; <strong>${KaiHtml.escape(trip.destination_address)}</strong>
                        ${trip.is_round_trip ? ' <span class="badge badge-info" style="margin-left:0.35rem;">🔄 Rundreise</span>' : ''}
                    </div>
                    <div style="color:var(--text-muted); font-size:0.85rem; margin-top:0.35rem;">
                        🗓️ Abfahrt: <strong style="color:var(--text-main);">${KaiHtml.escape(depFormatted)}</strong>
                        ${retFormatted ? ` &bull; Rückkehr: <strong style="color:var(--text-main);">${KaiHtml.escape(retFormatted)}</strong>` : ''}
                    </div>
                </div>
                <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                    ${trip.abrp_deep_link ? `
                        <a href="${KaiHtml.escape(trip.abrp_deep_link)}" target="_blank" rel="noopener noreferrer" class="btn btn-primary btn-sm" title="In ABRP App / CarPlay öffnen">
                            ⚡ In ABRP öffnen
                        </a>
                    ` : ''}
                    ${(() => {
                        const target = (trip.destination_lat && trip.destination_lon)
                            ? `${parseFloat(trip.destination_lat)},${parseFloat(trip.destination_lon)}`
                            : encodeURIComponent(trip.destination_address || '');
                        const gmapsUrl = `https://www.google.com/maps/dir/?api=1&destination=${target}`;
                        return `
                            <a href="${KaiHtml.escape(gmapsUrl)}" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" title="Navigation in Google Maps öffnen">
                                🗺️ In Maps öffnen
                            </a>
                        `;
                    })()}
                    <button type="button" class="btn btn-outline btn-sm js-btn-recalc-trip" data-trip-id="${trip.id}" title="Route und Vorladekette neu kalkulieren">
                        🔄 Neu berechnen
                    </button>
                    <button type="button" class="btn btn-outline btn-sm js-btn-delete-trip" data-trip-id="${trip.id}" title="Reise löschen" style="color:var(--danger, #ef4444);">
                        🗑️
                    </button>
                </div>
            </div>

            <!-- KPI-Kacheln -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:1rem; margin-bottom:1.5rem;">
                <div class="card" style="padding:1rem; text-align:center; background:var(--bg-surface); border:1px solid var(--bg-surface-hover);">
                    <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.25rem;">Gesamtdistanz</div>
                    <div style="font-size:1.4rem; font-weight:bold; color:var(--car-blue, #3b82f6);">${dist > 0 ? (dist.toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1}) + ' km') : '– km'}</div>
                    ${trip.actual_distance_km ? `
                        <div style="font-size:0.75rem; color:var(--color-green); margin-top:0.25rem;">
                            Ist: ${parseFloat(trip.actual_distance_km).toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1})} km
                        </div>
                    ` : ''}
                </div>
                <div class="card" style="padding:1rem; text-align:center; background:var(--bg-surface); border:1px solid var(--bg-surface-hover);">
                    <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.25rem;">Verbrauch (geschätzt)</div>
                    <div style="font-size:1.4rem; font-weight:bold; color:var(--text-main);">${parseFloat(trip.estimated_consumption_kwh || 0) > 0 ? (parseFloat(trip.estimated_consumption_kwh).toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1}) + ' kWh') : '– kWh'}</div>
                    ${trip.actual_consumption_kwh ? `
                        <div style="font-size:0.75rem; color:var(--color-green); margin-top:0.25rem;">
                            Ist: ${parseFloat(trip.actual_consumption_kwh).toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1})} kWh
                        </div>
                    ` : ''}
                </div>
                <div class="card" style="padding:1rem; text-align:center; background:var(--bg-surface); border:1px solid var(--bg-surface-hover);">
                    <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.25rem;">Start-Empfehlung</div>
                    <div style="font-size:1.4rem; font-weight:bold; color:${trip.planned_departure_soc >= 100 ? '#ef4444' : '#10b981'};">
                        ${KaiHtml.escape(trip.planned_departure_soc)} %
                    </div>
                    ${trip.can_drive_without_charging && trip.min_departure_soc < trip.planned_departure_soc ? `
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.35rem;">
                            Mindest-SoC: <strong style="color:var(--text-main);">${parseInt(trip.min_departure_soc, 10)}%</strong>
                        </div>
                    ` : ''}
                    ${trip.current_vehicle_soc !== null && trip.current_vehicle_soc !== undefined ? `
                        <div style="font-size:0.7rem; margin-top:0.35rem; font-weight:500; color:${trip.current_vehicle_soc >= trip.min_departure_soc ? '#10b981' : '#f59e0b'};">
                            ${trip.current_vehicle_soc >= trip.min_departure_soc 
                                ? `✅ Ist-Stand (${trip.current_vehicle_soc}%) reicht ohne Nachladen` 
                                : `⚡ Ist: ${trip.current_vehicle_soc}% (noch mind. +${trip.min_departure_soc - trip.current_vehicle_soc}% bis Start-SoC)`}
                        </div>
                    ` : ''}
                    ${trip.actual_arrival_soc !== null && trip.actual_arrival_soc !== undefined ? `
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.25rem;">
                            Ziel-SoC: <strong style="color:var(--text-main);">${parseInt(trip.actual_arrival_soc, 10)}%</strong>
                        </div>
                    ` : ''}
                </div>
                <div class="card" style="padding:1rem; text-align:center; background:var(--bg-surface); border:1px solid var(--bg-surface-hover);">
                    <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.25rem;">Schnellladen unterwegs</div>
                    <div style="font-size:1.4rem; font-weight:bold; color:var(--car-orange, #f59e0b);">
                        ${parseFloat(trip.en_route_charge_kwh || 0).toLocaleString('de-DE', {minimumFractionDigits: 1, maximumFractionDigits: 1})} kWh
                    </div>
                </div>
                <div class="card" style="padding:1rem; text-align:center; background:var(--bg-surface); border:1px solid var(--bg-surface-hover);">
                    <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.04em; margin-bottom:0.25rem;">Gesamtkosten</div>
                    <div style="font-size:1.4rem; font-weight:bold; color:#10b981;">${totalCost.replace('.', ',')} €</div>
                    <small style="color:var(--text-muted); font-size:0.75rem;">${costPer100.replace('.', ',')} € / 100 km</small>
                </div>
            </div>

            <!-- Vorladekette & Ladeschritte -->
            <div class="card" style="padding:1.25rem; margin-bottom:1.5rem;">
                <h3 style="margin-top:0; margin-bottom:0.75rem; font-size:1.1rem;">🔋 Vorlade-Kette & Ladestrategie</h3>
                ${steps.length === 0 ? '<p style="color:var(--text-muted); margin:0;">Keine Ladeschritte geplant.</p>' : `
                    <div class="table-responsive">
                        <table class="table" style="width:100%; font-size:0.9rem;">
                            <thead>
                                <tr>
                                    <th>Typ</th>
                                    <th>Datum</th>
                                    <th>Ziel-SoC</th>
                                    <th>Energie</th>
                                    <th>Status</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${steps.map(s => `
                                    <tr>
                                        <td>
                                            ${s.step_type === 'pv_precharge' ? '☀️ PV-Vorlauf (+10%)' : (s.step_type === 'evening_grid' ? '🔌 Vorabend-Netzladung' : '⚡ Unterwegs-Schnellladen')}
                                        </td>
                                        <td>${KaiHtml.escape(s.scheduled_date)}</td>
                                        <td><strong>${KaiHtml.escape(s.target_soc)} %</strong></td>
                                        <td>${KaiHtml.escape(s.planned_kwh)} kWh</td>
                                        <td>
                                            <span class="badge ${s.status === 'erledigt' ? 'badge-success' : 'badge-neutral'}">
                                                ${KaiHtml.escape(s.status)}
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-outline btn-sm js-btn-toggle-step" data-step-id="${s.id}" data-trip-id="${trip.id}" data-current-status="${s.status}">
                                                ${s.status === 'erledigt' ? 'Als offen markieren' : '✓ Erledigt'}
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `}
            </div>

            <!-- Reisekosten & Cent-genauer Abrechnungsabgleich -->
            <div class="card" style="padding:1.25rem; margin-bottom:1.5rem;">
                <h3 style="margin-top:0; margin-bottom:0.75rem; font-size:1.1rem;">💳 Reale Reisekosten & Buchungs-Zuordnung</h3>
                
                <div style="display:flex; gap:1.5rem; margin-bottom:1rem; font-size:0.9rem; flex-wrap:wrap;">
                    <div>🏠 <strong>Heimstrom Vorabend:</strong> ${parseFloat(trip.home_charge_cost || 0).toFixed(2)} €</div>
                    <div>⚡ <strong>Unterwegs geladen:</strong> ${parseFloat(trip.en_route_charge_cost || 0).toFixed(2)} €</div>
                    <div>🅿️ <strong>Maut & Parken:</strong> ${parseFloat(trip.additional_cost || 0).toFixed(2)} €</div>
                    <div>💰 <strong>Bilanz:</strong> <span style="font-weight:bold; color:#10b981;">${totalCost} €</span></div>
                </div>

                <!-- Zugeordnete Buchungen -->
                <h4 style="font-size:0.95rem; margin-bottom:0.5rem;">Zugeordnete Transaktionen (${transactions.length})</h4>
                ${transactions.length === 0 ? '<p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:1rem;">Noch keine Bank- oder Kreditkartenbuchungen zugeordnet.</p>' : `
                    <div class="table-responsive" style="margin-bottom:1.5rem;">
                        <table class="table" style="width:100%; font-size:0.85rem;">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Konto</th>
                                    <th>Partner / Händler</th>
                                    <th>Kategorie</th>
                                    <th>Betrag</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                ${transactions.map(t => `
                                    <tr>
                                        <td>${KaiHtml.escape(t.booking_date)}</td>
                                        <td><span class="badge">${t.transaction_type === 'creditcard' ? 'Visa CC' : 'Giro'}</span></td>
                                        <td><strong>${KaiHtml.escape(t.partner_name)}</strong></td>
                                        <td>
                                            ${t.cost_category === 'charge' ? '⚡ Ladekosten' : (t.cost_category === 'toll' ? '🛣️ Maut' : (t.cost_category === 'parking' ? '🅿️ Parken' : 'Sonstiges'))}
                                        </td>
                                        <td style="font-weight:bold; color:#ef4444;">${parseFloat(t.amount).toFixed(2)} €</td>
                                        <td>
                                            <button type="button" class="btn btn-outline btn-sm js-btn-unlink-tx" data-trip-id="${trip.id}" data-tx-type="${t.transaction_type}" data-tx-id="${t.transaction_id}" title="Zuordnung aufheben">
                                                ✕
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `}

                <!-- Vorschläge / Kandidaten für Zuordnung -->
                <h4 style="font-size:0.95rem; margin-bottom:0.5rem;">Offene Buchungen im Reisezeitraum (${candidates.filter(c => !c.is_linked).length} Treffer)</h4>
                ${candidates.filter(c => !c.is_linked).length === 0 ? '<p style="color:var(--text-muted); font-size:0.85rem; margin:0;">Keine unzugeordneten Ausgabenkandidaten im Reisezeitraum gefunden.</p>' : `
                    <div class="table-responsive">
                        <table class="table" style="width:100%; font-size:0.85rem;">
                            <thead>
                                <tr>
                                    <th>Datum</th>
                                    <th>Konto</th>
                                    <th>Partner / Buchungstext</th>
                                    <th>Betrag</th>
                                    <th>Kategorie-Wahl</th>
                                    <th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${candidates.filter(c => !c.is_linked).map(c => `
                                    <tr>
                                        <td>${KaiHtml.escape(c.booking_date)}</td>
                                        <td><span class="badge">${c.transaction_type === 'creditcard' ? 'Visa CC' : 'Giro'}</span></td>
                                        <td>
                                            <strong>${KaiHtml.escape(c.partner_name)}</strong>
                                            ${c.confidence === 'high' ? ' <span class="badge badge-success" style="font-size:0.7rem;">Match</span>' : ''}
                                            <div style="font-size:0.75rem; color:var(--text-muted);">${KaiHtml.escape(c.description || '')}</div>
                                        </td>
                                        <td style="font-weight:bold;">${parseFloat(c.amount).toFixed(2)} €</td>
                                        <td>
                                            <select id="cat-select-${c.transaction_type}-${c.transaction_id}" class="form-control" style="padding:0.2rem 0.5rem; font-size:0.8rem;">
                                                <option value="charge" ${c.suggested_category === 'charge' ? 'selected' : ''}>⚡ Ladekosten</option>
                                                <option value="toll" ${c.suggested_category === 'toll' ? 'selected' : ''}>🛣️ Maut</option>
                                                <option value="parking" ${c.suggested_category === 'parking' ? 'selected' : ''}>🅿️ Parken</option>
                                                <option value="other" ${c.suggested_category === 'other' ? 'selected' : ''}>Sonstiges</option>
                                            </select>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-outline btn-sm js-btn-link-tx" data-trip-id="${trip.id}" data-tx-type="${c.transaction_type}" data-tx-id="${c.transaction_id}">
                                                ➕ Zuordnen
                                            </button>
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `}
            </div>

            <!-- Verschachtelte Ausflüge (Sub-Trips) -->
            ${!trip.parent_trip_id ? `
                <div class="card" style="padding:1.25rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem;">
                        <h3 style="margin:0; font-size:1.1rem;">🏖️ Ausflüge während des Aufenthalts (${subtrips.length})</h3>
                        <button type="button" class="btn btn-outline btn-sm js-btn-new-subtrip" data-parent-id="${trip.id}" data-parent-dest="${KaiHtml.escape(trip.destination_address)}">
                            ➕ Ausflug hinzufügen
                        </button>
                    </div>
                    ${subtrips.length === 0 ? '<p style="color:var(--text-muted); font-size:0.85rem; margin:0;">Keine Ausflüge für diesen Urlaub eingetragen.</p>' : `
                        <div class="table-responsive">
                            <table class="table" style="width:100%; font-size:0.85rem;">
                                <thead>
                                    <tr>
                                        <th>Titel</th>
                                        <th>Zielort</th>
                                        <th>Abfahrt</th>
                                        <th>Distanz</th>
                                        <th>Bedarf</th>
                                        <th>Aktion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${subtrips.map(sub => `
                                        <tr>
                                            <td><strong>${KaiHtml.escape(sub.title)}</strong></td>
                                            <td>${KaiHtml.escape(sub.destination_address)}</td>
                                            <td>${KaiHtml.escape(sub.departure_time)}</td>
                                            <td>${KaiHtml.escape(sub.total_distance_km)} km</td>
                                            <td>${KaiHtml.escape(sub.estimated_consumption_kwh)} kWh</td>
                                            <td>
                                                <button type="button" class="btn btn-outline btn-sm js-btn-view-trip" data-trip-id="${sub.id}">
                                                    Details
                                                </button>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    `}
                </div>
            ` : ''}
        `;

        container.innerHTML = html;
    }
})();

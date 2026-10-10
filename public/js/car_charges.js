/**
 * car_charges.js
 * Verwaltung von Ladevorgängen: Beleg-Zuordnung (E-Bons), Ladetarife, Stationen auflösen.
 */

document.addEventListener('DOMContentLoaded', () => {
    const assignModal = document.getElementById('assign-cost-modal');
    const tariffsModal = document.getElementById('manage-tariffs-modal');

    let currentChargeId = null;
    let currentKwh = 0;
    let currentMode = 'DC';
    let currentOperator = '';

    // Modal schließen Buttons
    document.addEventListener('click', (e) => {
        const closeBtn = e.target.closest('.js-btn-close-modal');
        if (closeBtn) {
            closeModals();
        }
    });

    function closeModals() {
        if (assignModal) assignModal.style.display = 'none';
        if (tariffsModal) tariffsModal.style.display = 'none';
    }

    // Modal 1: Beleg / Tarif zuordnen öffnen
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-btn-open-assign-modal');
        if (!btn || !assignModal) return;

        currentChargeId = parseInt(btn.dataset.chargeId, 10);
        currentKwh = parseFloat(btn.dataset.kwh || 0);
        currentMode = btn.dataset.mode || 'DC';
        currentOperator = btn.dataset.operator || '';

        // Formularfelder / Info initialisieren
        const infoEl = document.getElementById('assign-charge-info');
        if (infoEl) {
            infoEl.innerHTML = `<strong>#${currentChargeId}</strong> &bull; ${currentKwh.toFixed(1).replace('.', ',')} kWh (${KaiHtml.escape(currentMode)})` +
                (currentOperator ? ` &bull; Betreiber: <strong>${KaiHtml.escape(currentOperator)}</strong>` : '');
        }

        assignModal.style.display = 'flex';

        // Lade Vorschläge & Tarife
        await loadAssignModalData();
    });

    async function loadAssignModalData() {
        if (!currentChargeId) return;

        const receiptListEl = document.getElementById('assign-receipts-list');
        const tariffSelectEl = document.getElementById('assign-tariff-select');
        const tariffPreviewEl = document.getElementById('assign-tariff-preview');

        if (receiptListEl) receiptListEl.innerHTML = '<div class="u-muted" style="padding: 1rem 0;">Lade passende Belege...</div>';

        try {
            // 1. Tarife & Vorschlag abrufen
            const tariffRes = await fetch(`charges_api.php?action=suggest_tariff&charge_id=${currentChargeId}`);
            const tariffData = await tariffRes.json();

            if (tariffData.success && tariffSelectEl) {
                tariffSelectEl.innerHTML = '<option value="">-- Tarif wählen --</option>';
                const tariffs = tariffData.tariffs || [];
                const matchedId = tariffData.tariff ? tariffData.tariff.id : null;

                tariffs.forEach(t => {
                    const opt = document.createElement('option');
                    opt.value = t.id;
                    const price = currentMode === 'DC' ? t.price_dc_eur_kwh : t.price_ac_eur_kwh;
                    opt.textContent = `${t.name} (${parseFloat(price).toFixed(2).replace('.', ',')} €/kWh)`;
                    opt.dataset.ac = t.price_ac_eur_kwh;
                    opt.dataset.dc = t.price_dc_eur_kwh;
                    if (t.id === matchedId) {
                        opt.selected = true;
                    }
                    tariffSelectEl.appendChild(opt);
                });

                updateTariffPreview();
            }

            // 2. Beleg-Kandidaten abrufen
            await loadReceiptCandidates();

        } catch (err) {
            console.error('Fehler beim Laden der Zuordnungsdaten:', err);
        }
    }

    async function loadReceiptCandidates(query = '') {
        const receiptListEl = document.getElementById('assign-receipts-list');
        if (!receiptListEl || !currentChargeId) return;

        try {
            const url = `charges_api.php?action=receipt_candidates&charge_id=${currentChargeId}` + 
                (query ? `&query=${encodeURIComponent(query)}` : '');
            const res = await fetch(url);
            const data = await res.json();

            if (!data.success || !data.candidates || data.candidates.length === 0) {
                receiptListEl.innerHTML = '<div class="u-muted" style="padding: 0.75rem 0; font-size: 0.9rem;">Keine passenden Belege gefunden. Verwende die Suche oder trage die Kosten per Tarif/manuell ein.</div>';
                return;
            }

            let html = '<div class="receipt-candidates-grid" style="display: flex; flex-direction: column; gap: 0.5rem; max-height: 240px; overflow-y: auto;">';
            data.candidates.forEach(c => {
                const total = parseFloat(c.total || 0).toFixed(2).replace('.', ',');
                const dateParts = (c.purchase_date || '').split('-');
                const formattedDate = dateParts.length === 3 ? `${dateParts[2]}.${dateParts[1]}.${dateParts[0]}` : c.purchase_date;
                const isExact = c.is_exact_date;

                html += `
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0.75rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 6px;">
                        <div>
                            <strong>${KaiHtml.escape(c.store)}</strong>
                            <div class="u-muted" style="font-size: 0.8rem;">
                                ${KaiHtml.escape(formattedDate)} &bull; 
                                <span style="color: var(--color-success, #22c55e); font-weight: bold;">${total} €</span>
                                ${isExact ? ' <span class="badge badge-success" style="font-size: 0.7rem;">Tag-Match</span>' : ''}
                                ${c.is_already_linked ? ' <span class="badge badge-warning" style="font-size: 0.7rem;">bereits verknüpft</span>' : ''}
                            </div>
                        </div>
                        <div>
                            <button type="button" class="btn btn-outline btn-xs js-btn-link-receipt" data-receipt-id="${c.id}" data-total="${total}">
                                🔗 Übernehmen
                            </button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            receiptListEl.innerHTML = html;
        } catch (e) {
            receiptListEl.innerHTML = '<div class="text-danger" style="padding: 0.5rem 0;">Fehler beim Laden der Belege.</div>';
        }
    }

    // Beleg-Suche Live-Filter
    const receiptSearchInput = document.getElementById('assign-receipt-search');
    if (receiptSearchInput) {
        let timeout = null;
        receiptSearchInput.addEventListener('input', () => {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                loadReceiptCandidates(receiptSearchInput.value.trim());
            }, 300);
        });
    }

    // Tarif-Auswahl Dropdown Change
    const tariffSelect = document.getElementById('assign-tariff-select');
    if (tariffSelect) {
        tariffSelect.addEventListener('change', updateTariffPreview);
    }

    function updateTariffPreview() {
        const tariffPreviewEl = document.getElementById('assign-tariff-preview');
        if (!tariffPreviewEl || !tariffSelect) return;

        const selOption = tariffSelect.selectedOptions[0];
        if (!selOption || !selOption.value) {
            tariffPreviewEl.textContent = '–';
            return;
        }

        const rate = parseFloat(currentMode === 'DC' ? selOption.dataset.dc : selOption.dataset.ac) || 0;
        const estimated = (currentKwh * rate).toFixed(2).replace('.', ',');
        tariffPreviewEl.innerHTML = `<strong>${estimated} €</strong> <span class="u-muted" style="font-size: 0.8rem;">(${currentKwh.toFixed(1).replace('.', ',')} kWh &times; ${rate.toFixed(2).replace('.', ',')} €/kWh)</span>`;
    }

    // Klick: Beleg übernehmen
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-btn-link-receipt');
        if (!btn || !currentChargeId) return;

        const receiptId = parseInt(btn.dataset.receiptId, 10);
        btn.disabled = true;
        btn.textContent = 'Speichere...';

        try {
            const res = await KaiHttp.postJson('charges_api.php', {
                action: 'link_receipt',
                charge_id: currentChargeId,
                receipt_id: receiptId
            });

            if (res.success) {
                closeModals();
                window.location.reload();
            } else {
                alert('Fehler beim Zuordnen: ' + (res.error || 'Unbekannter Fehler'));
                btn.disabled = false;
                btn.textContent = '🔗 Übernehmen';
            }
        } catch (err) {
            alert('Netzwerkfehler beim Zuordnen des Belegs.');
            btn.disabled = false;
            btn.textContent = '🔗 Übernehmen';
        }
    });

    // Klick: Tarif anwenden
    const applyTariffBtn = document.getElementById('js-btn-apply-tariff');
    if (applyTariffBtn) {
        applyTariffBtn.addEventListener('click', async () => {
            if (!currentChargeId || !tariffSelect || !tariffSelect.value) {
                alert('Bitte wähle zuerst einen Tarif aus.');
                return;
            }

            const tariffId = parseInt(tariffSelect.value, 10);
            applyTariffBtn.disabled = true;
            applyTariffBtn.textContent = 'Wende an...';

            try {
                const res = await KaiHttp.postJson('charges_api.php', {
                    action: 'apply_tariff',
                    charge_id: currentChargeId,
                    tariff_id: tariffId
                });

                if (res.success) {
                    closeModals();
                    window.location.reload();
                } else {
                    alert('Fehler: ' + (res.error || 'Konnte Tarif nicht anwenden'));
                    applyTariffBtn.disabled = false;
                    applyTariffBtn.textContent = '⚡ Tarif anwenden';
                }
            } catch (err) {
                alert('Netzwerkfehler.');
                applyTariffBtn.disabled = false;
                applyTariffBtn.textContent = '⚡ Tarif anwenden';
            }
        });
    }

    // Klick: Manuellen Betrag speichern
    const saveManualBtn = document.getElementById('js-btn-save-manual-cost');
    if (saveManualBtn) {
        saveManualBtn.addEventListener('click', async () => {
            const amountInput = document.getElementById('assign-manual-amount');
            const categoryInput = document.getElementById('assign-manual-category');

            if (!amountInput || !currentChargeId) return;

            const cost = parseFloat(amountInput.value.replace(',', '.'));
            if (isNaN(cost) || cost < 0) {
                alert('Bitte einen gültigen Betrag eingeben.');
                return;
            }

            saveManualBtn.disabled = true;
            saveManualBtn.textContent = 'Speichere...';

            try {
                const res = await KaiHttp.postJson('charges_api.php', {
                    action: 'set_cost',
                    charge_id: currentChargeId,
                    cost_eur: cost,
                    tariff_category: categoryInput ? categoryInput.value.trim() : null
                });

                if (res.success) {
                    closeModals();
                    window.location.reload();
                } else {
                    alert('Fehler: ' + (res.error || 'Speichern fehlgeschlagen'));
                    saveManualBtn.disabled = false;
                    saveManualBtn.textContent = '💾 Betrag speichern';
                }
            } catch (err) {
                alert('Netzwerkfehler.');
                saveManualBtn.disabled = false;
                saveManualBtn.textContent = '💾 Betrag speichern';
            }
        });
    }

    // Beleg-Verknüpfung aufheben
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-btn-unlink-receipt');
        if (!btn) return;

        const chargeId = parseInt(btn.dataset.chargeId, 10);
        if (!confirm('Möchtest du die Verknüpfung zu diesem Beleg wirklich aufheben?')) {
            return;
        }

        btn.disabled = true;
        try {
            const res = await KaiHttp.postJson('charges_api.php', {
                action: 'unlink_receipt',
                charge_id: chargeId
            });

            if (res.success) {
                window.location.reload();
            } else {
                alert('Fehler beim Entkoppeln: ' + (res.error || 'Unbekannt'));
                btn.disabled = false;
            }
        } catch (err) {
            alert('Netzwerkfehler.');
            btn.disabled = false;
        }
    });

    // Ladestation über OpenStreetMap auflösen
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-btn-resolve-station');
        if (!btn) return;

        const chargeId = parseInt(btn.dataset.chargeId, 10);
        btn.disabled = true;
        btn.textContent = '⏳ Suche...';

        try {
            const res = await KaiHttp.postJson('charges_api.php', {
                action: 'resolve_location',
                charge_id: chargeId
            });

            if (res.success) {
                window.location.reload();
            } else {
                alert('Keine Station gefunden oder Fehler: ' + (res.error || 'Unbekannt'));
                btn.disabled = false;
                btn.textContent = '🔍 Station suchen';
            }
        } catch (err) {
            alert('Netzwerkfehler.');
            btn.disabled = false;
            btn.textContent = '🔍 Station suchen';
        }
    });

    // Modal 2: Ladetarife verwalten öffnen
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-btn-manage-tariffs');
        if (!btn || !tariffsModal) return;

        tariffsModal.style.display = 'flex';
        await loadTariffsList();
    });

    async function loadTariffsList() {
        const listEl = document.getElementById('tariffs-list-container');
        if (!listEl) return;

        listEl.innerHTML = '<div class="u-muted">Lade Tarife...</div>';

        try {
            const res = await fetch('charges_api.php?action=list_tariffs');
            const data = await res.json();

            if (!data.success || !data.tariffs || data.tariffs.length === 0) {
                listEl.innerHTML = '<div class="u-muted">Noch keine Ladetarife hinterlegt. Lege jetzt deinen ersten Tarif an!</div>';
                return;
            }

            let html = '<div style="display: flex; flex-direction: column; gap: 0.75rem;">';
            data.tariffs.forEach(t => {
                const ac = parseFloat(t.price_ac_eur_kwh).toFixed(2).replace('.', ',');
                const dc = parseFloat(t.price_dc_eur_kwh).toFixed(2).replace('.', ',');
                const isDef = t.is_default == 1;

                html += `
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 6px; flex-wrap: wrap; gap: 0.5rem;">
                        <div>
                            <strong>${KaiHtml.escape(t.name)}</strong>
                            ${isDef ? ' <span class="badge badge-success" style="font-size: 0.75rem;">Standard</span>' : ''}
                            <div class="u-muted" style="font-size: 0.85rem; margin-top: 2px;">
                                AC: <strong>${ac} €/kWh</strong> &bull; DC: <strong>${dc} €/kWh</strong>
                                ${t.operator_match ? ` &bull; Match: <code>${KaiHtml.escape(t.operator_match)}</code>` : ''}
                            </div>
                            ${t.notes ? `<div class="u-muted" style="font-size: 0.8rem; font-style: italic;">${KaiHtml.escape(t.notes)}</div>` : ''}
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="button" class="btn btn-outline btn-xs js-btn-edit-tariff" 
                                data-id="${t.id}"
                                data-name="${KaiHtml.escape(t.name)}"
                                data-match="${KaiHtml.escape(t.operator_match || '')}"
                                data-ac="${t.price_ac_eur_kwh}"
                                data-dc="${t.price_dc_eur_kwh}"
                                data-default="${t.is_default}"
                                data-notes="${KaiHtml.escape(t.notes || '')}">
                                ✏️ Bearbeiten
                            </button>
                            <button type="button" class="btn btn-outline btn-xs text-danger js-btn-delete-tariff" data-id="${t.id}">
                                🗑️
                            </button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            listEl.innerHTML = html;
        } catch (e) {
            listEl.innerHTML = '<div class="text-danger">Fehler beim Laden der Tarife.</div>';
        }
    }

    // Tarif Formular Submit
    const tariffForm = document.getElementById('tariff-form');
    if (tariffForm) {
        tariffForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = tariffForm.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            const formData = new FormData(tariffForm);
            const payload = {
                action: 'save_tariff',
                id: formData.get('id') || null,
                name: formData.get('name'),
                operator_match: formData.get('operator_match'),
                price_ac_eur_kwh: parseFloat(formData.get('price_ac_eur_kwh')),
                price_dc_eur_kwh: parseFloat(formData.get('price_dc_eur_kwh')),
                is_default: formData.get('is_default') === '1' ? 1 : 0,
                notes: formData.get('notes')
            };

            try {
                const res = await KaiHttp.postJson('charges_api.php', payload);
                if (res.success) {
                    resetTariffForm();
                    await loadTariffsList();
                } else {
                    alert('Fehler beim Speichern: ' + (res.error || 'Unbekannt'));
                }
            } catch (err) {
                alert('Netzwerkfehler.');
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // Reset Tarif Formular
    const resetTariffBtn = document.getElementById('js-btn-reset-tariff-form');
    if (resetTariffBtn) {
        resetTariffBtn.addEventListener('click', resetTariffForm);
    }

    function resetTariffForm() {
        if (!tariffForm) return;
        tariffForm.reset();
        const idInput = tariffForm.querySelector('input[name="id"]');
        if (idInput) idInput.value = '';
        const titleEl = document.getElementById('tariff-form-title');
        if (titleEl) titleEl.textContent = 'Neuen Tarif anlegen';
    }

    // Tarif bearbeiten Button Klick
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-btn-edit-tariff');
        if (!btn || !tariffForm) return;

        tariffForm.querySelector('input[name="id"]').value = btn.dataset.id;
        tariffForm.querySelector('input[name="name"]').value = btn.dataset.name;
        tariffForm.querySelector('input[name="operator_match"]').value = btn.dataset.match;
        tariffForm.querySelector('input[name="price_ac_eur_kwh"]').value = btn.dataset.ac;
        tariffForm.querySelector('input[name="price_dc_eur_kwh"]').value = btn.dataset.dc;
        tariffForm.querySelector('input[name="notes"]').value = btn.dataset.notes;

        const defCheckbox = tariffForm.querySelector('input[name="is_default"]');
        if (defCheckbox) defCheckbox.checked = (btn.dataset.default === '1');

        const titleEl = document.getElementById('tariff-form-title');
        if (titleEl) titleEl.textContent = 'Tarif bearbeiten: ' + btn.dataset.name;

        tariffForm.scrollIntoView({ behavior: 'smooth' });
    });

    // Tarif löschen
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.js-btn-delete-tariff');
        if (!btn) return;

        const id = parseInt(btn.dataset.id, 10);
        if (!confirm('Diesen Tarif wirklich löschen?')) return;

        btn.disabled = true;
        try {
            const res = await KaiHttp.postJson('charges_api.php', {
                action: 'delete_tariff',
                id: id
            });
            if (res.success) {
                await loadTariffsList();
            } else {
                alert('Fehler beim Löschen: ' + (res.error || 'Unbekannt'));
                btn.disabled = false;
            }
        } catch (err) {
            alert('Netzwerkfehler.');
            btn.disabled = false;
        }
    });
});

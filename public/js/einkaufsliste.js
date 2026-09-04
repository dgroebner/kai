/**
 * einkaufsliste.js - Interaktive Steuerung der intelligenten Einkaufsliste
 *
 * Verwendet Event Delegation, KaiHttp (CSRF-POST) und KaiHtml (DOM-Escaping).
 */
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const API_URL = 'api.php';

    // Toast Notification Helper
    function showToast(message, isError = false) {
        let toast = document.getElementById('shopping-toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'shopping-toast';
            toast.className = 'shopping-toast';
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.className = 'shopping-toast ' + (isError ? 'toast-error' : 'toast-success');
        toast.classList.remove('hidden');

        setTimeout(() => {
            toast.classList.add('hidden');
        }, 3500);
    }

    // --- Tab Navigation ---
    document.addEventListener('click', (e) => {
        const tabBtn = e.target.closest('.js-tab-btn');
        if (tabBtn) {
            const targetTab = tabBtn.dataset.tab;
            if (!targetTab) return;

            // Buttons umschalten
            document.querySelectorAll('.js-tab-btn').forEach(btn => {
                if (btn === tabBtn) {
                    btn.classList.remove('btn-outline');
                } else {
                    btn.classList.add('btn-outline');
                }
            });

            // Tab-Inhalte umschalten
            document.querySelectorAll('.shopping-tab-pane').forEach(pane => {
                pane.classList.add('hidden');
            });
            const targetPane = document.getElementById('tab-' + targetTab);
            if (targetPane) {
                targetPane.classList.remove('hidden');
            }

            // URL anpassen (ohne Reload)
            const url = new URL(window.location);
            url.searchParams.set('tab', targetTab);
            window.history.replaceState({}, '', url);
        }
    });

    // --- Markt Filter (Alle / Rewe / Globus) ---
    document.addEventListener('click', (e) => {
        const marketBtn = e.target.closest('.js-market-filter');
        if (marketBtn) {
            const selectedMarket = marketBtn.dataset.market;
            const url = new URL(window.location);
            url.searchParams.set('market', selectedMarket);
            url.searchParams.set('tab', 'list');
            window.location.href = url.toString();
        }
    });

    // --- Autocomplete-Hilfe für schnelles Hinzufügen ---
    const nameInput = document.getElementById('input-item-name');
    if (nameInput) {
        nameInput.addEventListener('input', () => {
            const val = nameInput.value.trim().toLowerCase();
            const datalist = document.getElementById('known-products-datalist');
            if (!datalist) return;

            const options = Array.from(datalist.options);
            const match = options.find(opt => opt.value.trim().toLowerCase() === val);
            if (match) {
                const marketSelect = document.getElementById('input-item-market');
                const catSelect = document.getElementById('input-item-category');
                const unitSelect = document.getElementById('input-item-unit');

                if (marketSelect && match.dataset.market) {
                    marketSelect.value = match.dataset.market;
                }
                if (catSelect && match.dataset.category) {
                    catSelect.value = match.dataset.category;
                }
                if (unitSelect && match.dataset.unit) {
                    unitSelect.value = match.dataset.unit;
                }
            }
        });
    }

    // --- Artikel hinzufügen (Form Submit) ---
    const addForm = document.getElementById('shopping-add-form');
    if (addForm) {
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const name = document.getElementById('input-item-name').value.trim();
            if (!name) return;

            const quantity = parseFloat(document.getElementById('input-item-quantity').value) || 1.0;
            const unit = document.getElementById('input-item-unit').value;
            const market = document.getElementById('input-item-market').value;
            const category = document.getElementById('input-item-category').value;
            const isSpontaneous = document.getElementById('input-is-spontaneous').checked ? 1 : 0;

            const payload = {
                action: 'add_item',
                name,
                quantity,
                unit,
                market,
                category,
                is_spontaneous: isSpontaneous
            };

            const submitBtn = addForm.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, payload);
                if (res.success) {
                    showToast(res.message || 'Artikel hinzugefügt!');
                    // Formular zurücksetzen, Fokus auf Name
                    document.getElementById('input-item-name').value = '';
                    document.getElementById('input-item-quantity').value = '1';
                    document.getElementById('input-is-spontaneous').checked = false;
                    document.getElementById('input-item-name').focus();

                    // Seite neu laden um die korrekte Gang-Sortierung serverseitig zu rendern
                    window.location.reload();
                } else {
                    showToast(res.message || 'Fehler beim Hinzufügen', true);
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // --- Abhaken umschalten (Checkbox Klick) ---
    document.addEventListener('change', async (e) => {
        const check = e.target.closest('.js-item-check');
        if (check) {
            const itemId = parseInt(check.dataset.id, 10);
            if (!itemId) return;

            const isChecked = check.checked;
            const row = check.closest('.shopping-item-row');

            // Optimistische Animation
            if (row) {
                if (isChecked) {
                    row.classList.add('is-checked');
                    const nameSpan = row.querySelector('.item-name');
                    if (nameSpan) nameSpan.classList.add('strike-through');
                } else {
                    row.classList.remove('is-checked');
                    const nameSpan = row.querySelector('.item-name');
                    if (nameSpan) nameSpan.classList.remove('strike-through');
                }
            }

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'toggle_check',
                    id: itemId,
                    checked: isChecked
                });

                if (!res.success) {
                    showToast('Fehler beim Aktualisieren', true);
                    check.checked = !isChecked; // Rollback
                } else {
                    // Nach kurzer Verzögerung Seite neu laden für sauberes Layout (Gruppe "Erledigt")
                    setTimeout(() => {
                        window.location.reload();
                    }, 400);
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                check.checked = !isChecked;
            }
        }
    });

    // --- Artikel löschen ---
    document.addEventListener('click', async (e) => {
        const deleteBtn = e.target.closest('.js-delete-item-btn');
        if (deleteBtn) {
            const itemId = parseInt(deleteBtn.dataset.id, 10);
            if (!itemId) return;

            if (!confirm('Möchtest du diesen Artikel wirklich aus der Einkaufsliste entfernen?')) {
                return;
            }

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'delete_item',
                    id: itemId
                });

                if (res.success) {
                    const row = deleteBtn.closest('.shopping-item-row');
                    if (row) {
                        row.remove();
                    }
                    showToast(res.message || 'Artikel gelöscht');
                } else {
                    showToast(res.message || 'Fehler beim Löschen', true);
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
            }
        }
    });

    // --- Einkauf abschließen ---
    document.addEventListener('click', async (e) => {
        const completeBtn = e.target.closest('.js-complete-shopping-btn');
        if (completeBtn) {
            const market = completeBtn.dataset.market || 'all';
            const marketText = market === 'all' ? 'alle Märkte' : market;

            if (!confirm(`Möchtest du den Einkauf für ${marketText} wirklich abschließen? Alle erledigten Artikel werden entfernt und die Verbrauchsintervalle aktualisiert.`)) {
                return;
            }

            completeBtn.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'complete_shopping',
                    market: market
                });

                if (res.success) {
                    showToast(res.message || 'Einkauf erfolgreich abgeschlossen!');
                    setTimeout(() => {
                        window.location.reload();
                    }, 800);
                } else {
                    showToast(res.message || 'Fehler beim Abschließen', true);
                    completeBtn.disabled = false;
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                completeBtn.disabled = false;
            }
        }
    });

    // --- Historische eBons analysieren (Lernprozess) ---
    const syncEbonsHandler = async (btn) => {
        if (!btn) return;
        btn.disabled = true;
        const oldText = btn.innerHTML;
        btn.innerHTML = '⏳ Analysiere eBons...';

        try {
            const res = await KaiHttp.postJson(API_URL, {action: 'sync_ebons'});
            if (res.success) {
                showToast(res.message || 'eBon-Analyse erfolgreich!');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(res.message || 'Fehler bei der Analyse', true);
                btn.disabled = false;
                btn.innerHTML = oldText;
            }
        } catch (err) {
            showToast('Verbindungsfehler', true);
            btn.disabled = false;
            btn.innerHTML = oldText;
        }
    };

    const btnSyncEbons = document.getElementById('btn-sync-ebons');
    if (btnSyncEbons) {
        btnSyncEbons.addEventListener('click', () => syncEbonsHandler(btnSyncEbons));
    }
    const btnTriggerSync = document.getElementById('btn-trigger-sync');
    if (btnTriggerSync) {
        btnTriggerSync.addEventListener('click', () => syncEbonsHandler(btnTriggerSync));
    }

    // --- Einzelnen Vorschlag übernehmen ---
    document.addEventListener('click', async (e) => {
        const acceptBtn = e.target.closest('.js-accept-single-suggestion');
        if (acceptBtn) {
            const productId = parseInt(acceptBtn.dataset.id, 10);
            const market = acceptBtn.dataset.market || 'Rewe';
            if (!productId) return;

            acceptBtn.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'accept_suggestion',
                    product_id: productId,
                    market: market
                });

                if (res.success) {
                    acceptBtn.innerHTML = '✔️ Auf Liste';
                    acceptBtn.classList.remove('btn-primary');
                    acceptBtn.classList.add('btn-success');
                    showToast(res.message || 'Artikel hinzugefügt!');
                } else {
                    showToast(res.message || 'Fehler beim Übernehmen', true);
                    acceptBtn.disabled = false;
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                acceptBtn.disabled = false;
            }
        }
    });

    // --- Alle Vorschläge übernehmen ---
    const btnAcceptAll = document.getElementById('btn-accept-all-suggestions');
    if (btnAcceptAll) {
        btnAcceptAll.addEventListener('click', async () => {
            let productIds;
            try {
                productIds = JSON.parse(btnAcceptAll.dataset.ids || '[]');
            } catch (e) {
                productIds = [];
            }

            if (!productIds.length) return;

            btnAcceptAll.disabled = true;
            btnAcceptAll.innerHTML = '⏳ Übernehme alle...';

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'accept_all_suggestions',
                    product_ids: productIds
                });

                if (res.success) {
                    showToast(res.message || 'Alle Vorschläge hinzugefügt!');
                    setTimeout(() => {
                        const url = new URL(window.location);
                        url.searchParams.set('tab', 'list');
                        window.location.href = url.toString();
                    }, 800);
                } else {
                    showToast(res.message || 'Fehler beim Übernehmen', true);
                    btnAcceptAll.disabled = false;
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                btnAcceptAll.disabled = false;
            }
        });
    }

    // --- KI Rezept-Assistent: Analyse anfordern ---
    const formRecipeAi = document.getElementById('form-recipe-ai');
    if (formRecipeAi) {
        formRecipeAi.addEventListener('submit', async (e) => {
            e.preventDefault();

            const textInput = document.getElementById('recipe-input-text');
            const text = textInput ? textInput.value.trim() : '';
            if (!text) {
                showToast('Bitte Text oder Rezept eingeben', true);
                return;
            }

            const parseBtn = document.getElementById('btn-parse-recipe');
            const loader = document.getElementById('recipe-loading-indicator');
            const previewContainer = document.getElementById('recipe-preview-container');
            const previewBody = document.getElementById('recipe-preview-body');

            if (parseBtn) parseBtn.disabled = true;
            if (loader) loader.classList.remove('hidden');

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'parse_recipe',
                    text: text
                });

                if (res.success && Array.isArray(res.items) && res.items.length > 0) {
                    previewBody.innerHTML = '';

                    res.items.forEach((item, idx) => {
                        const tr = document.createElement('tr');
                        tr.className = 'recipe-item-preview-row';
                        tr.innerHTML = `
                            <td data-label="Auswahl">
                                <input type="checkbox" class="js-recipe-check" checked data-idx="${idx}">
                            </td>
                            <td data-label="Artikel">
                                <input type="text" class="form-control form-control-sm js-recipe-name" value="${KaiHtml.escape(item.name)}">
                            </td>
                            <td data-label="Menge">
                                <input type="number" step="0.1" min="0.1" class="form-control form-control-sm js-recipe-qty" value="${KaiHtml.escape(item.quantity)}">
                            </td>
                            <td data-label="Einheit">
                                <input type="text" class="form-control form-control-sm js-recipe-unit" value="${KaiHtml.escape(item.unit)}">
                            </td>
                            <td data-label="Zielmarkt">
                                <select class="form-control form-control-sm js-recipe-market">
                                    <option value="Rewe" ${item.market === 'Rewe' ? 'selected' : ''}>Rewe</option>
                                    <option value="Globus" ${item.market === 'Globus' ? 'selected' : ''}>Globus</option>
                                </select>
                            </td>
                            <td data-label="Kategorie">
                                <input type="text" class="form-control form-control-sm js-recipe-category" value="${KaiHtml.escape(item.category)}">
                            </td>
                        `;
                        previewBody.appendChild(tr);
                    });

                    previewContainer.classList.remove('hidden');
                    showToast(`${res.items.length} Artikel erfolgreich erkannt!`);
                } else {
                    showToast('Keine Zutaten erkannt. Bitte Eingabe prüfen.', true);
                }
            } catch (err) {
                showToast('Fehler bei der KI-Analyse', true);
            } finally {
                if (parseBtn) parseBtn.disabled = false;
                if (loader) loader.classList.add('hidden');
            }
        });
    }

    // Check-All im Rezept-Preview
    const checkAllRecipe = document.getElementById('check-all-recipe-items');
    if (checkAllRecipe) {
        checkAllRecipe.addEventListener('change', () => {
            document.querySelectorAll('.js-recipe-check').forEach(chk => {
                chk.checked = checkAllRecipe.checked;
            });
        });
    }

    // Geparste Rezept-Artikel zur Liste hinzufügen
    const btnSaveRecipeItems = document.getElementById('btn-save-recipe-items');
    if (btnSaveRecipeItems) {
        btnSaveRecipeItems.addEventListener('click', async () => {
            const rows = document.querySelectorAll('.recipe-item-preview-row');
            const items = [];

            rows.forEach(row => {
                const chk = row.querySelector('.js-recipe-check');
                if (chk && chk.checked) {
                    const name = row.querySelector('.js-recipe-name')?.value.trim() || '';
                    const qty = parseFloat(row.querySelector('.js-recipe-qty')?.value) || 1.0;
                    const unit = row.querySelector('.js-recipe-unit')?.value.trim() || 'Stück';
                    const market = row.querySelector('.js-recipe-market')?.value || 'Rewe';
                    const category = row.querySelector('.js-recipe-category')?.value.trim() || 'Sonstiges';

                    if (name) {
                        items.push({name, quantity: qty, unit, market, category});
                    }
                }
            });

            if (!items.length) {
                showToast('Keine Artikel ausgewählt', true);
                return;
            }

            btnSaveRecipeItems.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'save_recipe_items',
                    items: items
                });

                if (res.success) {
                    showToast(res.message || 'Artikel zur Einkaufsliste hinzugefügt!');
                    setTimeout(() => {
                        const url = new URL(window.location);
                        url.searchParams.set('tab', 'list');
                        window.location.href = url.toString();
                    }, 800);
                } else {
                    showToast(res.message || 'Fehler beim Speichern', true);
                    btnSaveRecipeItems.disabled = false;
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                btnSaveRecipeItems.disabled = false;
            }
        });
    }

    // --- Gang-Reihenfolge Umschalten & Sortieren ---
    document.addEventListener('click', (e) => {
        const toggleBtn = e.target.closest('.js-aisle-market-toggle');
        if (toggleBtn) {
            const market = toggleBtn.dataset.market;
            document.querySelectorAll('.js-aisle-market-toggle').forEach(btn => {
                if (btn === toggleBtn) {
                    btn.classList.remove('btn-outline');
                } else {
                    btn.classList.add('btn-outline');
                }
            });

            const reweBox = document.getElementById('aisle-list-rewe');
            const globusBox = document.getElementById('aisle-list-globus');
            if (market === 'Rewe') {
                if (reweBox) reweBox.classList.remove('hidden');
                if (globusBox) globusBox.classList.add('hidden');
            } else {
                if (reweBox) reweBox.classList.add('hidden');
                if (globusBox) globusBox.classList.remove('hidden');
            }
        }
    });

    // Nach oben / Nach unten in Gang-Sortierung
    document.addEventListener('click', (e) => {
        const upBtn = e.target.closest('.js-move-aisle-up');
        if (upBtn) {
            const item = upBtn.closest('.aisle-sortable-item');
            if (item && item.previousElementSibling) {
                item.parentNode.insertBefore(item, item.previousElementSibling);
            }
            return;
        }

        const downBtn = e.target.closest('.js-move-aisle-down');
        if (downBtn) {
            const item = downBtn.closest('.aisle-sortable-item');
            if (item && item.nextElementSibling) {
                item.parentNode.insertBefore(item.nextElementSibling, item);
            }

        }
    });

    // Gang-Reihenfolge speichern
    document.addEventListener('click', async (e) => {
        const saveBtn = e.target.closest('.js-save-aisle-order');
        if (saveBtn) {
            const market = saveBtn.dataset.market;
            const list = document.querySelector(`.aisle-sortable-list[data-market="${market}"]`);
            if (!list) return;

            const categories = [];
            list.querySelectorAll('.aisle-sortable-item').forEach(li => {
                const cat = li.dataset.category;
                if (cat) categories.push(cat);
            });

            saveBtn.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'update_aisle_order',
                    market: market,
                    categories: categories
                });

                if (res.success) {
                    showToast(res.message || 'Gang-Reihenfolge gespeichert!');
                } else {
                    showToast(res.message || 'Fehler beim Speichern', true);
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
            } finally {
                saveBtn.disabled = false;
            }
        }
    });
});

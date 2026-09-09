/**
 * einkaufsliste.js - Interaktive Steuerung der intelligenten Einkaufsliste
 *
 * Verwendet Event Delegation, KaiHttp (CSRF-POST) und KaiHtml (DOM-Escaping).
 */
const API_URL = 'api.php';
window.API_URL = API_URL;

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
window.showToast = showToast;

document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    // =========================================================
    // --- Quick-Add Flow (Slider & Modal) ---
    // =========================================================
    const quickAddForm = document.getElementById('shopping-add-form');
    const btnQuickStartNext = document.getElementById('btn-quick-start-next');
    const inputItemName = document.getElementById('input-item-name');

    // Modal Elements
    const quickAddModal = document.getElementById('quick-add-modal');
    const btnCloseQuickAddModal = document.getElementById('btn-close-quick-add-modal');
    const displayName = document.getElementById('quick-add-display-name');
    const slider = document.getElementById('quick-add-slider');
    const sliderDisplay = document.getElementById('quick-add-slider-display');
    const unitDisplay = document.getElementById('quick-add-unit-display');
    const sliderTicks = document.getElementById('quick-add-slider-ticks');
    const modalAddQuantity = document.getElementById('modal-add-quantity');
    const modalAddUnit = document.getElementById('modal-add-unit');
    const modalAddMarket = document.getElementById('modal-add-market');
    const modalAddCategory = document.getElementById('modal-add-category');
    const modalAddNote = document.getElementById('modal-add-note');
    const btnSubmitQuickAdd = document.getElementById('btn-submit-quick-add');
    const inputIsSpontaneous = document.getElementById('input-is-spontaneous');

    let currentSliderValues = [];

    function initSlider(unit) {
        if (!slider) return;
        if (unit === 'g') {
            currentSliderValues = [100, 200, 250, 400, 500, 750, 1000];
        } else if (unit === 'kg') {
            currentSliderValues = [0.5, 1, 1.5, 2, 2.5, 3, 5];
        } else if (unit === 'Liter') {
            currentSliderValues = [0.5, 1, 1.5, 2, 3, 5];
        } else if (unit === 'ml') {
            currentSliderValues = [100, 200, 250, 330, 400, 500, 750];
        } else {
            // Default (Stück, Packung, etc.)
            currentSliderValues = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        }

        slider.max = currentSliderValues.length - 1;

        // Render Ticks
        sliderTicks.innerHTML = '';
        currentSliderValues.forEach(val => {
            const span = document.createElement('span');
            span.textContent = val;
            sliderTicks.appendChild(span);
        });

        unitDisplay.textContent = unit;
        syncSliderWithInput();
    }

    function syncSliderWithInput() {
        if (!slider) return;
        const val = parseFloat(modalAddQuantity.value) || 1;
        let closestIdx = 0;
        let minDiff = Infinity;
        currentSliderValues.forEach((v, idx) => {
            const diff = Math.abs(v - val);
            if (diff < minDiff) {
                minDiff = diff;
                closestIdx = idx;
            }
        });
        slider.value = closestIdx;
        sliderDisplay.textContent = currentSliderValues[closestIdx];
    }

    if (slider) {
        slider.addEventListener('input', () => {
            const val = currentSliderValues[slider.value];
            sliderDisplay.textContent = val;
            modalAddQuantity.value = val;
        });

        modalAddQuantity.addEventListener('input', syncSliderWithInput);

        modalAddUnit.addEventListener('change', () => {
            initSlider(modalAddUnit.value);
        });
    }

    function openQuickAddModal() {
        if (!inputItemName) return;
        const name = inputItemName.value.trim();
        if (!name) return;

        // Auto-fill from datalist if possible
        const dl = document.getElementById('known-products-datalist');
        let matchedOption = null;
        if (dl) {
            const options = dl.querySelectorAll('option');
            const searchName = name.toLowerCase();
            options.forEach(opt => {
                if (opt.value.toLowerCase() === searchName) {
                    matchedOption = opt;
                }
            });
        }

        if (displayName) displayName.textContent = name;

        if (matchedOption) {
            modalAddUnit.value = matchedOption.dataset.unit || 'Stück';
            modalAddMarket.value = matchedOption.dataset.market || 'Rewe';
            modalAddCategory.value = matchedOption.dataset.category || 'Sonstiges';
        } else {
            modalAddUnit.value = 'Stück';
            modalAddMarket.value = 'Übergreifend';
            modalAddCategory.value = 'Sonstiges';
        }

        modalAddQuantity.value = 1;
        modalAddNote.value = '';
        initSlider(modalAddUnit.value);

        if (quickAddModal) quickAddModal.classList.remove('hidden');
    }

    if (btnQuickStartNext) {
        btnQuickStartNext.addEventListener('click', openQuickAddModal);
    }
    if (quickAddForm) {
        quickAddForm.addEventListener('submit', (e) => {
            e.preventDefault();
            openQuickAddModal();
        });
    }

    function closeQuickAddModal() {
        if (quickAddModal) quickAddModal.classList.add('hidden');
    }

    if (btnCloseQuickAddModal) {
        btnCloseQuickAddModal.addEventListener('click', closeQuickAddModal);
    }

    if (btnSubmitQuickAdd) {
        btnSubmitQuickAdd.addEventListener('click', async () => {
            const name = displayName.textContent;
            const quantity = parseFloat(modalAddQuantity.value) || 1.0;
            const unit = modalAddUnit.value;
            const market = modalAddMarket.value;
            const category = modalAddCategory.value;
            const note = modalAddNote.value.trim();
            const isSpontaneous = inputIsSpontaneous && inputIsSpontaneous.checked ? 1 : 0;

            const payload = {
                action: 'add_item',
                name,
                quantity,
                unit,
                market,
                category,
                note,
                is_spontaneous: isSpontaneous
            };

            btnSubmitQuickAdd.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, payload);
                if (res.success) {
                    showToast(res.message || 'Artikel hinzugefügt!');
                    inputItemName.value = '';
                    if (inputIsSpontaneous) inputIsSpontaneous.checked = false;
                    closeQuickAddModal();
                    inputItemName.focus();
                    window.location.reload();
                } else {
                    showToast(res.message || 'Fehler beim Hinzufügen', true);
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
            } finally {
                btnSubmitQuickAdd.disabled = false;
            }
        });
    }

    // --- Tab Navigation ---
    document.addEventListener('click', (e) => {
        const tabBtn = e.target.closest('.js-tab-btn');
        if (tabBtn) {
            const targetTab = tabBtn.dataset.tab;
            if (!targetTab) return;

            if (targetTab === 'aisles' && window.inboxModified) {
                window.location.href = '?tab=' + targetTab;
                return;
            }

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
                if (targetTab === 'aisles' && typeof window.applyProductMasterFilter === 'function') {
                    const filterInput = document.getElementById('product-master-filter');
                    if (filterInput && filterInput.value.trim() !== '') {
                        window.applyProductMasterFilter(filterInput.value);
                    }
                }
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
                    renderQuantityChips();
                }
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
        const editTrigger = e.target.closest('.js-edit-list-item-trigger');
        if (editTrigger) {
            const row = editTrigger.closest('.shopping-item-row');
            if (row && typeof window.openEditItemModal === 'function') {
                window.openEditItemModal(
                    parseInt(row.dataset.id, 10),
                    row.dataset.name,
                    parseFloat(row.dataset.quantity),
                    row.dataset.unit,
                    row.dataset.market,
                    row.dataset.category,
                    row.dataset.note
                );
            }
            return;
        }

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
// --- Produkt-Master bearbeiten (Edit & Ignore) ---
    document.addEventListener('click', async (e) => {
        // Edit button
        const editBtn = e.target.closest('.js-edit-product-btn');
        if (editBtn) {
            const id = editBtn.dataset.id;
            // fetch product data
            const res = await KaiHttp.postJson(API_URL, {action: 'get_product_master', id});
            if (res.success) {
                const product = res.product;
                document.getElementById('modal-product-id').value = product.id;
                document.getElementById('modal-name').value = product.display_name ?? product.name ?? '';
                document.getElementById('modal-product-market').value = product.preferred_market || 'Rewe';

                if (typeof window.updateCategoryDropdown === 'function') {
                    window.updateCategoryDropdown('modal-product-market', 'modal-product-category');
                }
                document.getElementById('modal-product-unit').value = product.default_unit || 'Stück';
                document.getElementById('modal-product-category').value = product.default_category || 'Sonstiges';
                document.getElementById('modal-ignore-checkbox').checked = !!product.is_ignored;

                const intervalInput = document.getElementById('modal-product-interval');
                if (intervalInput) {
                    intervalInput.value = product.avg_interval_days !== null && product.avg_interval_days !== undefined ? product.avg_interval_days : '';
                }

                const holidayModeSelect = document.getElementById('modal-product-holiday-mode');
                if (holidayModeSelect) {
                    const factor = parseFloat(product.holiday_factor ?? 1.0);
                    if (factor < 0.05) {
                        holidayModeSelect.value = '0.00';
                    } else if (factor >= 1.9) {
                        holidayModeSelect.value = '2.00';
                    } else if (factor >= 1.4) {
                        holidayModeSelect.value = '1.50';
                    } else {
                        holidayModeSelect.value = '1.00';
                    }
                }

                document.getElementById('product-edit-modal').classList.remove('hidden');
            } else {
                showToast(res.message || 'Fehler beim Laden', true);
            }
            return;
        }

        // Toggle ignore button
        const ignoreBtn = e.target.closest('.js-toggle-ignore-btn');
        if (ignoreBtn) {
            const id = ignoreBtn.dataset.id;
            const res = await KaiHttp.postJson(API_URL, {action: 'toggle_product_ignore', id});
            if (res.success) {
                const row = document.querySelector(`tr[data-id="${id}"]`);
                if (row) row.classList.toggle('row-ignored');
                // toggle icon and data attribute
                if (ignoreBtn.dataset.ignored === '1') {
                    ignoreBtn.innerHTML = '👁️';
                    ignoreBtn.dataset.ignored = '0';
                } else {
                    ignoreBtn.innerHTML = '🚫';
                    ignoreBtn.dataset.ignored = '1';
                }
                showToast(res.message || 'Ignorier‑Status aktualisiert');
            } else {
                showToast(res.message || 'Fehler beim Umschalten', true);
            }
        }
    });

    // Save button in modal
    const btnSaveProduct = document.getElementById('btn-save-product');
    if (btnSaveProduct) {
        btnSaveProduct.addEventListener('click', async () => {
            const id = document.getElementById('modal-product-id').value;
            const name = document.getElementById('modal-name').value.trim();
            const ignore = document.getElementById('modal-ignore-checkbox').checked ? 1 : 0;
            const market = document.getElementById('modal-product-market').value;
            const unit = document.getElementById('modal-product-unit').value;
            const category = document.getElementById('modal-product-category').value;
            const intervalVal = document.getElementById('modal-product-interval') ? document.getElementById('modal-product-interval').value.trim() : '';
            const holidayModeVal = document.getElementById('modal-product-holiday-mode') ? document.getElementById('modal-product-holiday-mode').value : '1.00';

            const payload = {
                action: 'save_product_master',
                id,
                name: name,
                preferred_market: market,
                default_unit: unit,
                default_category: category,
                avg_interval_days: intervalVal !== '' ? parseFloat(intervalVal) : '',
                holiday_factor: holidayModeVal,
                is_ignored: ignore
            };
            const res = await KaiHttp.postJson(API_URL, payload);
            if (res.success) {
                showToast(res.message || 'Produkt gespeichert');
                const row = document.querySelector(`#product-master-table tr[data-id="${id}"]`);
                if (row) {
                    const nameCell = row.querySelector('td[data-label="Artikel"] strong');
                    if (nameCell) nameCell.textContent = name;

                    const marketBadge = row.querySelector('td[data-label="Markt"] span');
                    if (marketBadge) {
                        marketBadge.textContent = market;
                        marketBadge.className = 'badge badge-market ' + (market === 'Rewe' ? 'badge-rewe' : 'badge-globus');
                    }

                    const catCell = row.querySelector('td[data-label="Kategorie"]');
                    if (catCell) {
                        const icon = (window.CATEGORY_ICONS && window.CATEGORY_ICONS[category]) || '🛒';
                        catCell.textContent = icon + ' ' + category;
                    }

                    const intervalCell = row.querySelector('td[data-label="Intervall"]');
                    if (intervalCell) {
                        intervalCell.textContent = intervalVal !== '' ? parseFloat(intervalVal).toFixed(1).replace('.', ',') + ' Tage' : '—';
                    }

                    const holidayCell = row.querySelector('td[data-label="Ferien"]');
                    if (holidayCell) {
                        const hf = parseFloat(holidayModeVal);
                        if (hf < 0.05) {
                            holidayCell.innerHTML = '<span class="badge badge-warning" title="Brotbüchse: Pausiert vor &amp; in Ferien, aktiv vor Schulstart">🥪 Brotbüchse</span>';
                        } else if (hf > 1.0) {
                            holidayCell.innerHTML = '<span class="badge badge-info" title="Mehrbedarf in Ferien">🏖️ ' + hf.toFixed(1) + 'x</span>';
                        } else {
                            holidayCell.innerHTML = '<span class="text-muted">1.0x</span>';
                        }
                    }

                    row.classList.toggle('row-ignored', ignore === 1);
                }
                document.getElementById('product-edit-modal').classList.add('hidden');
            } else {
                showToast(res.message || 'Fehler beim Speichern', true);
            }
        });
    }

    // Modal close / cancel
    const closeModal = () => {
        const modal = document.getElementById('product-edit-modal');
        if (modal) modal.classList.add('hidden');
    };
    const closeBtn = document.querySelector('#product-edit-modal .rule-modal-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }
    const cancelBtn = document.getElementById('btn-cancel-product');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeModal);
    }

    // =========================================================
    // --- eBon-Mapping Modal ---
    // =========================================================

    /** Rendert die Mapping-Liste im Modal neu */
    function renderMappingList(mappings, productId) {
        const container = document.getElementById('mapping-list-container');
        if (!container) return;

        if (!mappings || mappings.length === 0) {
            container.innerHTML = '<p class="text-muted" style="font-size:0.85rem;">Noch keine Kassenbonnamen zugeordnet.</p>';
            return;
        }

        container.innerHTML = mappings.map(m => `
            <div class="mapping-list-item" style="display:flex;align-items:center;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid var(--bg-surface-hover);">
                <span style="font-size:0.9rem;">${KaiHtml.escape(m.ebon_name)}</span>
                <button type="button"
                        class="btn-icon js-delete-mapping-btn"
                        data-id="${KaiHtml.escape(String(m.id))}"
                        data-product-id="${KaiHtml.escape(String(productId))}"
                        title="Zuordnung löschen">🗑️</button>
            </div>
        `).join('');
    }

    // Mapping-Modal öffnen
    document.addEventListener('click', async (e) => {
        const mappingBtn = e.target.closest('.js-mapping-btn');
        if (!mappingBtn) return;

        const productId = mappingBtn.dataset.id;
        const productName = mappingBtn.dataset.name;

        const nameEl = document.getElementById('mapping-modal-product-name');
        const idEl = document.getElementById('mapping-modal-product-id');
        const modal = document.getElementById('ebon-mapping-modal');
        const input = document.getElementById('mapping-new-ebon-name');

        if (nameEl) nameEl.textContent = productName;
        if (idEl) idEl.value = productId;
        if (input) input.value = '';

        // Vorhandene Mappings laden
        try {
            const res = await KaiHttp.postJson(API_URL, {action: 'get_ebon_mappings', product_id: productId});
            if (res.success) {
                renderMappingList(res.mappings, productId);
            } else {
                showToast(res.message || 'Fehler beim Laden der Zuordnungen', true);
                return;
            }
        } catch (err) {
            showToast('Verbindungsfehler', true);
            return;
        }

        if (modal) modal.classList.remove('hidden');
    });

    // Mapping-Modal schließen
    const closeMappingModal = () => {
        const modal = document.getElementById('ebon-mapping-modal');
        if (modal) modal.classList.add('hidden');
    };

    const closeMappingBtn = document.getElementById('btn-close-mapping-modal');
    if (closeMappingBtn) closeMappingBtn.addEventListener('click', closeMappingModal);

    const closeMappingBtnFooter = document.getElementById('btn-close-mapping-modal-footer');
    if (closeMappingBtnFooter) closeMappingBtnFooter.addEventListener('click', closeMappingModal);

    // Neues Mapping hinzufügen
    const btnAddMapping = document.getElementById('btn-add-mapping');
    if (btnAddMapping) {
        btnAddMapping.addEventListener('click', async () => {
            const productId = document.getElementById('mapping-modal-product-id')?.value;
            const ebonName = document.getElementById('mapping-new-ebon-name')?.value.trim();

            if (!ebonName) {
                showToast('eBon-Name darf nicht leer sein', true);
                return;
            }

            btnAddMapping.disabled = true;
            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'save_ebon_mapping',
                    product_id: productId,
                    ebon_name: ebonName,
                });

                if (res.success) {
                    renderMappingList(res.mappings, productId);
                    const input = document.getElementById('mapping-new-ebon-name');
                    if (input) input.value = '';
                    showToast(res.message || 'Zuordnung gespeichert');
                } else {
                    showToast(res.message || 'Fehler beim Speichern', true);
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
            } finally {
                btnAddMapping.disabled = false;
            }
        });
    }

    // Mapping löschen (Event Delegation auf Modal-Container)
    document.addEventListener('click', async (e) => {
        const deleteBtn = e.target.closest('.js-delete-mapping-btn');
        if (!deleteBtn) return;

        const mappingId = deleteBtn.dataset.id;
        const productId = deleteBtn.dataset.productId;

        if (!confirm('Diese Kassenbonzuordnung wirklich löschen?')) return;

        try {
            const res = await KaiHttp.postJson(API_URL, {
                action: 'delete_ebon_mapping',
                id: mappingId,
                product_id: productId,
            });

            if (res.success) {
                renderMappingList(res.mappings, productId);
                showToast(res.message || 'Zuordnung gelöscht');
            } else {
                showToast(res.message || 'Fehler beim Löschen', true);
            }
        } catch (err) {
            showToast('Verbindungsfehler', true);
        }
    });


    // =========================================================
    // --- Inbox (Unbekannte eBons) ---
    // =========================================================
    const tabBtnInbox = document.getElementById('tab-btn-inbox');
    const inboxListContainer = document.getElementById('inbox-list-container');
    const inboxEmptyState = document.getElementById('inbox-empty-state');
    const inboxLoading = document.getElementById('inbox-loading-indicator');
    Array.from(document.querySelectorAll('#bulk-target-select option'))
        .filter(opt => opt.value !== '')
        .map(opt => `<option value="${opt.value}">${KaiHtml.escape(opt.textContent)}</option>`)
        .join('');

    function loadInbox() {
        if (!inboxListContainer) return;

        inboxLoading.classList.remove('hidden');
        inboxListContainer.classList.add('hidden');
        inboxEmptyState.classList.add('hidden');

        KaiHttp.postJson(API_URL, {action: 'get_inbox'})
            .then(res => {
                inboxLoading.classList.add('hidden');
                if (res.success && res.items && res.items.length > 0) {
                    let html = '';
                    res.items.forEach(item => {
                        html += `
                            <div class="inbox-item-card">
                                <div class="inbox-item-info">
                                    <h4 style="margin:0; font-size:1.1rem; ${item.is_likely_non_product ? 'text-decoration: line-through; color: var(--text-muted);' : ''}">${KaiHtml.escape(item.name)}</h4>
                                    <div style="font-size:0.8rem; color:var(--text-muted); margin-top:0.2rem;">
                                        Oft in: ${KaiHtml.escape(item.dominant_category)} | Gekauft: ${item.count}x
                                        ${item.is_likely_non_product ? ' <br><span class="badge badge-warning">Vermutlich Rabatt/Pfand</span>' : ''}
                                    </div>
                                </div>
                                <div class="inbox-item-actions">
                                    <div class="inbox-assign-wrapper" style="display:flex; align-items:center; gap:0.25rem;">
                                        <input type="text" class="form-control js-inbox-assign-input" 
                                               list="known-products-datalist" 
                                               data-ebon="${KaiHtml.escape(item.name)}" 
                                               value="${KaiHtml.escape(item.name)}"
                                               placeholder="Zuordnen oder neu..."
                                               style="max-width: 200px;">
                                        <button type="button" class="btn-icon js-inbox-assign-save hidden" title="Speichern">✅</button>
                                        <button type="button" class="btn-icon js-inbox-assign-cancel hidden" title="Abbrechen">❌</button>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline js-inbox-ignore-btn" data-name="${KaiHtml.escape(item.name)}" title="Als Rabatt/Pfand ignorieren">🚫 Ignorieren</button>
                                </div>
                            </div>
                        `;
                    });
                    inboxListContainer.innerHTML = html;
                    inboxListContainer.classList.remove('hidden');

                    const badge = tabBtnInbox.querySelector('.badge');
                    if (badge) badge.textContent = res.items.length;
                    else tabBtnInbox.innerHTML = `📥 Unbekannte eBons <span class="badge badge-warning shopping-badge-counter">${res.items.length}</span>`;
                } else {
                    inboxEmptyState.classList.remove('hidden');
                    const badge = tabBtnInbox.querySelector('.badge');
                    if (badge) badge.remove();
                }
            })
            .catch(err => {
                inboxLoading.classList.add('hidden');
                showToast('Fehler beim Laden der Inbox', true);
            });
    }

    if (tabBtnInbox) {
        tabBtnInbox.addEventListener('click', loadInbox);
    }

    if (new URLSearchParams(window.location.search).get('tab') === 'inbox') {
        loadInbox();
    }

    document.addEventListener('change', async (e) => {
        if (e.target.classList.contains('js-inbox-target-select')) {
            const select = e.target;
            const targetId = select.value;
            if (!targetId) return;

            const ebonName = select.dataset.name;
            select.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'resolve_inbox',
                    action_type: 'map',
                    ebon_name: ebonName,
                    target_id: targetId
                });

                if (res.success) {
                    window.inboxModified = true;
                    showToast('Zuordnung gespeichert');
                    select.closest('.inbox-item-card').remove();
                } else {
                    showToast(res.message || 'Fehler beim Zuordnen', true);
                    select.disabled = false;
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                select.disabled = false;
            }
        }
    });

    document.addEventListener('click', async (e) => {
        const newBtn = e.target.closest('.js-inbox-new-btn');
        if (newBtn) {
            const ebonName = newBtn.dataset.name;
            const newName = prompt('Bitte den sauberen Artikelnamen eingeben (so wie er künftig heißen soll):', ebonName);
            if (newName === null) return;
            if (newName.trim() === '') {
                showToast('Der Name darf nicht leer sein.', true);
                return;
            }
            newBtn.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'resolve_inbox',
                    action_type: 'new',
                    ebon_name: ebonName,
                    new_name: newName.trim()
                });

                if (res.success) {
                    window.inboxModified = true;
                    showToast('Als neuen Artikel angelegt');
                    newBtn.closest('.inbox-item-card').remove();

                    if (res.new_product) {
                        const newOpt = document.createElement('option');
                        newOpt.value = res.new_product.id;
                        newOpt.textContent = res.new_product.name;

                        document.querySelectorAll('.js-inbox-target-select').forEach(select => {
                            select.appendChild(newOpt.cloneNode(true));
                        });
                    }
                } else {
                    showToast(res.message || 'Fehler beim Anlegen', true);
                    newBtn.disabled = false;
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                newBtn.disabled = false;
            }
        }
    });

    // =========================================================
    // --- Bulk Merge ---
    // =========================================================
    const checkAllProducts = document.getElementById('check-all-products');
    const mergeCheckboxes = document.querySelectorAll('.js-merge-check');
    const bulkActionBar = document.getElementById('bulk-action-bar');
    const bulkSelectedCount = document.getElementById('bulk-selected-count');
    const btnExecuteBulkMerge = document.getElementById('btn-execute-bulk-merge');
    const bulkTargetSelect = document.getElementById('bulk-target-select');

    function updateBulkActionBar() {
        if (!bulkActionBar) return;
        const checked = document.querySelectorAll('.js-merge-check:checked');
        const count = checked.length;

        bulkSelectedCount.textContent = count;
        if (count > 0) {
            bulkActionBar.classList.remove('hidden');
        } else {
            bulkActionBar.classList.add('hidden');
        }
    }

    if (checkAllProducts) {
        checkAllProducts.addEventListener('change', () => {
            mergeCheckboxes.forEach(chk => chk.checked = checkAllProducts.checked);
            updateBulkActionBar();
        });
    }

    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('js-merge-check')) {
            updateBulkActionBar();
        }
    });

    if (bulkTargetSelect && btnExecuteBulkMerge) {
        bulkTargetSelect.addEventListener('change', () => {
            btnExecuteBulkMerge.disabled = bulkTargetSelect.value === '';
        });

        btnExecuteBulkMerge.addEventListener('click', async () => {
            const targetId = bulkTargetSelect.value;
            const sourceIds = Array.from(document.querySelectorAll('.js-merge-check:checked')).map(chk => chk.value);

            if (!targetId || sourceIds.length === 0) return;
            if (sourceIds.includes(targetId)) {
                showToast('Ziel-Artikel darf nicht in der Auswahl enthalten sein.', true);
                return;
            }
            if (!confirm(`Möchtest du diese ${sourceIds.length} Artikel wirklich zusammenführen? Die Quellen werden danach gelöscht.`)) return;

            btnExecuteBulkMerge.disabled = true;
            btnExecuteBulkMerge.innerHTML = 'Führe zusammen...';

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'merge_products',
                    target_id: targetId,
                    source_ids: sourceIds
                });

                if (res.success) {
                    showToast(res.message);
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    showToast(res.message || 'Fehler beim Zusammenführen', true);
                    btnExecuteBulkMerge.disabled = false;
                    btnExecuteBulkMerge.innerHTML = 'Zusammenführen';
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                btnExecuteBulkMerge.disabled = false;
                btnExecuteBulkMerge.innerHTML = 'Zusammenführen';
            }
        });
    }

    // =========================================================
    // --- KI-Auto-Merge Modal ---
    // =========================================================
    const btnOpenAiMerge = document.getElementById('btn-open-ai-merge');
    const aiMergeModal = document.getElementById('ai-merge-modal');
    const btnCloseAiModal = document.getElementById('btn-close-ai-modal');
    const btnCancelAiModal = document.getElementById('btn-cancel-ai-modal');
    const btnStartAiAnalysis = document.getElementById('btn-start-ai-analysis');
    const aiLoading = document.getElementById('ai-loading-indicator');
    const aiResults = document.getElementById('ai-results-container');
    const aiEmpty = document.getElementById('ai-empty-state');

    function closeAiModal() {
        if (aiMergeModal) aiMergeModal.classList.add('hidden');
    }

    if (btnOpenAiMerge) {
        btnOpenAiMerge.addEventListener('click', () => {
            aiMergeModal.classList.remove('hidden');
        });
    }

    if (btnCloseAiModal) btnCloseAiModal.addEventListener('click', closeAiModal);
    if (btnCancelAiModal) btnCancelAiModal.addEventListener('click', closeAiModal);

    if (btnStartAiAnalysis) {
        btnStartAiAnalysis.addEventListener('click', async () => {
            btnStartAiAnalysis.classList.add('hidden');
            aiLoading.classList.remove('hidden');
            aiResults.classList.add('hidden');
            aiEmpty.classList.add('hidden');

            try {
                const res = await KaiHttp.postJson(API_URL, {action: 'ai_suggest_merges'});

                aiLoading.classList.add('hidden');

                if (res.success && res.clusters && res.clusters.length > 0) {
                    let html = '<h4 style="margin-bottom: 1rem;">Die KI schlägt folgende Zusammenführungen vor:</h4>';

                    res.clusters.forEach((cluster, idx) => {
                        html += `
                            <div class="ai-cluster-card" id="cluster-card-${idx}">
                                <div class="ai-cluster-header">
                                    <strong>Ziel: ${KaiHtml.escape(cluster.target_name)}</strong>
                                    <button type="button" class="btn btn-sm btn-primary js-accept-ai-cluster" 
                                            data-idx="${idx}"
                                            data-target-name="${KaiHtml.escape(cluster.target_name)}"
                                            data-sources='${JSON.stringify(cluster.source_ids)}'>
                                        Zusammenführen
                                    </button>
                                </div>
                                <div class="ai-cluster-sources">
                                    Fasst ${cluster.source_ids.length} Artikel-IDs zusammen.
                                </div>
                            </div>
                        `;
                    });

                    aiResults.innerHTML = html;
                    aiResults.classList.remove('hidden');
                } else {
                    aiEmpty.classList.remove('hidden');
                }
            } catch (err) {
                aiLoading.classList.add('hidden');
                showToast('Fehler bei der KI-Analyse', true);
                btnStartAiAnalysis.classList.remove('hidden');
            }
        });
    }

    document.addEventListener('click', async (e) => {
        const acceptBtn = e.target.closest('.js-accept-ai-cluster');
        if (acceptBtn) {
            acceptBtn.disabled = true;
            acceptBtn.innerHTML = '⏳ Arbeite...';

            const targetName = acceptBtn.dataset.targetName;
            let sourceIds = [];
            try {
                sourceIds = JSON.parse(acceptBtn.dataset.sources);
            } catch (err) {
            }

            if (sourceIds.length < 2) {
                showToast('Ein Cluster braucht mindestens 2 Artikel.', true);
                return;
            }

            const targetId = sourceIds.shift();

            try {
                await KaiHttp.postJson(API_URL, {
                    action: 'save_product_master',
                    id: targetId,
                    custom_label: targetName,
                    is_ignored: 0
                });

                const mergeRes = await KaiHttp.postJson(API_URL, {
                    action: 'merge_products',
                    target_id: targetId,
                    source_ids: sourceIds
                });

                if (mergeRes.success) {
                    acceptBtn.closest('.ai-cluster-card').remove();
                    showToast('Cluster erfolgreich zusammengeführt');
                } else {
                    showToast(mergeRes.message || 'Fehler beim Mergen', true);
                    acceptBtn.innerHTML = 'Fehler';
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                acceptBtn.innerHTML = 'Fehler';
            }
        }

        const ignoreBtn = e.target.closest('.js-inbox-ignore-btn');
        if (ignoreBtn) {
            const ebonName = ignoreBtn.dataset.name;
            ignoreBtn.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'resolve_inbox',
                    action_type: 'ignore',
                    ebon_name: ebonName
                });

                if (res.success) {
                    showToast(res.message || 'Wird künftig ignoriert');
                    ignoreBtn.closest('.inbox-item-card').remove();

                    const currentCount = document.querySelectorAll('.inbox-item-card').length;
                    const badge = document.querySelector('#tab-btn-inbox .badge');
                    if (currentCount === 0) {
                        if (badge) badge.remove();
                        document.getElementById('inbox-list-container').classList.add('hidden');
                        document.getElementById('inbox-empty-state').classList.remove('hidden');
                    } else if (badge) {
                        badge.textContent = currentCount;
                    }
                } else {
                    showToast(res.message || 'Fehler beim Ignorieren', true);
                    ignoreBtn.disabled = false;
                }
            } catch (err) {
                console.error(err);
                showToast('Verbindungsfehler', true);
                ignoreBtn.disabled = false;
            }
        }
    });


    // =========================================================
    // --- Inbox Assignment (Input + Save/Cancel) ---
    // =========================================================

    // Show buttons on input or focus
    document.addEventListener('input', (e) => {
        if (e.target.classList.contains('js-inbox-assign-input')) {
            const wrapper = e.target.closest('.inbox-assign-wrapper');
            wrapper.querySelector('.js-inbox-assign-save').classList.remove('hidden');
            wrapper.querySelector('.js-inbox-assign-cancel').classList.remove('hidden');
        }
    });
    document.addEventListener('focusin', (e) => {
        if (e.target.classList.contains('js-inbox-assign-input')) {
            const wrapper = e.target.closest('.inbox-assign-wrapper');
            wrapper.querySelector('.js-inbox-assign-save').classList.remove('hidden');
            wrapper.querySelector('.js-inbox-assign-cancel').classList.remove('hidden');
        }
    });

    // Handle clicks
    document.addEventListener('click', async (e) => {
        const cancelBtn = e.target.closest('.js-inbox-assign-cancel');
        if (cancelBtn) {
            const wrapper = cancelBtn.closest('.inbox-assign-wrapper');
            const input = wrapper.querySelector('.js-inbox-assign-input');
            // Revert value
            input.value = input.dataset.ebon;
            // Hide buttons
            wrapper.querySelector('.js-inbox-assign-save').classList.add('hidden');
            wrapper.querySelector('.js-inbox-assign-cancel').classList.add('hidden');
            return;
        }

        const saveBtn = e.target.closest('.js-inbox-assign-save');
        if (saveBtn) {
            const wrapper = saveBtn.closest('.inbox-assign-wrapper');
            const input = wrapper.querySelector('.js-inbox-assign-input');
            const ebonName = input.dataset.ebon;
            const targetName = input.value.trim();

            if (targetName === '') {
                showToast('Name darf nicht leer sein', true);
                return;
            }

            input.disabled = true;
            saveBtn.disabled = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'resolve_inbox',
                    action_type: 'assign',
                    ebon_name: ebonName,
                    target_name: targetName
                });

                if (res.success) {
                    window.inboxModified = true;
                    showToast(res.message || 'Erfolgreich zugeordnet');
                    wrapper.closest('.inbox-item-card').remove();

                    // Update datalist if a new product was created
                    if (res.new_product) {
                        const datalist = document.getElementById('known-products-datalist');
                        if (datalist) {
                            const opt = document.createElement('option');
                            opt.value = res.new_product.name;
                            datalist.appendChild(opt);
                        }
                    }
                } else {
                    showToast(res.message || 'Fehler beim Zuordnen', true);
                    input.disabled = false;
                    saveBtn.disabled = false;
                }
            } catch (err) {
                showToast('Verbindungsfehler', true);
                input.disabled = false;
                saveBtn.disabled = false;
            }
        }
    });

    // =========================================================
    // --- Schnellfilter Artikelstamm ---
    // =========================================================
    function applyProductMasterFilter(term) {
        const table = document.getElementById('product-master-table');
        if (!table) return;

        const cleanTerm = (term || '').toLowerCase().trim();
        const tokens = cleanTerm.split(/\s+/).filter(t => t.length > 0);
        const rows = table.querySelectorAll('tbody tr:not(#product-master-filter-empty)');
        const emptyRow = document.getElementById('product-master-filter-empty');

        let visibleCount = 0;
        let totalArticleRows = 0;

        rows.forEach(row => {
            // Ignore static placeholder if database had 0 products initially
            if (row.querySelector('td[colspan]')) return;
            totalArticleRows++;

            const text = row.textContent.toLowerCase();
            const match = tokens.length === 0 || tokens.every(token => text.includes(token));

            if (match) {
                row.classList.remove('hidden');
                row.style.display = '';
                visibleCount++;
            } else {
                row.classList.add('hidden');
                row.style.display = 'none';
            }
        });

        if (emptyRow) {
            if (visibleCount === 0 && tokens.length > 0 && totalArticleRows > 0) {
                emptyRow.classList.remove('hidden');
                emptyRow.style.display = '';
            } else {
                emptyRow.classList.add('hidden');
                emptyRow.style.display = 'none';
            }
        }

        const clearBtn = document.getElementById('btn-clear-product-filter');
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', cleanTerm.length === 0);
        }
    }
    window.applyProductMasterFilter = applyProductMasterFilter;

    const productMasterFilter = document.getElementById('product-master-filter');
    if (productMasterFilter) {
        ['input', 'keyup', 'change', 'search'].forEach(evt => {
            productMasterFilter.addEventListener(evt, (e) => {
                applyProductMasterFilter(e.target.value);
            });
        });
    }

    // Event Delegation fallback
    document.addEventListener('input', (e) => {
        if (e.target && e.target.id === 'product-master-filter') {
            applyProductMasterFilter(e.target.value);
        }
    });

    document.addEventListener('click', (e) => {
        if (e.target && e.target.id === 'btn-clear-product-filter') {
            const input = document.getElementById('product-master-filter');
            if (input) {
                input.value = '';
                input.focus();
                applyProductMasterFilter('');
            }
        }
    });
});


// --- Einkaufslisten-Eintrag bearbeiten Modal ---
window.openEditItemModal = function (id, name, quantity, unit, market, category, note) {
    document.getElementById('modal-list-item-id').value = id;
    document.getElementById('modal-list-item-name').value = name;
    document.getElementById('modal-list-item-quantity').value = quantity;
    document.getElementById('modal-list-item-unit').value = unit;
    document.getElementById('modal-list-item-market').value = market;

    if (typeof window.updateCategoryDropdown === 'function') {
        window.updateCategoryDropdown('modal-list-item-market', 'modal-list-item-category');
    }
    document.getElementById('modal-list-item-category').value = category || 'Sonstiges';

    document.getElementById('modal-list-item-note').value = note || '';

    // Init Slider
    if (typeof window.initEditSlider === 'function') {
        window.initEditSlider(unit);
    }

    document.getElementById('list-item-edit-modal').classList.remove('hidden');
};

document.addEventListener('DOMContentLoaded', () => {
    const btnCloseListItemModal = document.getElementById('btn-close-list-item-modal');
    const btnCancelListItem = document.getElementById('btn-cancel-list-item');
    const btnSaveListItem = document.getElementById('btn-save-list-item');
    const listItemEditModal = document.getElementById('list-item-edit-modal');

    // Category dropdown filter logic
    // Cache icons from the original DOM so we don't lose them
    window.CATEGORY_ICONS = {};
    document.querySelectorAll('#modal-add-category option').forEach(opt => {
        if (opt.value !== 'Sonstiges') {
            const text = opt.textContent.trim();
            const iconMatch = text.match(/^(\p{Emoji_Presentation}|\p{Emoji}\uFE0F|\p{Emoji_Modifier_Base})\s+(.*)$/u);
            if (iconMatch) {
                window.CATEGORY_ICONS[opt.value] = iconMatch[1];
            } else {
                // Fallback for simple emojis or if regex misses
                window.CATEGORY_ICONS[opt.value] = Array.from(text)[0];
            }
        }
    });

    window.updateCategoryDropdown = function (marketSelectId, categorySelectId) {
        const marketSelect = document.getElementById(marketSelectId);
        const categorySelect = document.getElementById(categorySelectId);
        if (!marketSelect || !categorySelect) return;

        const selectedMarket = marketSelect.value;
        const currentCategory = categorySelect.value;

        categorySelect.innerHTML = '<option value="Sonstiges">Sonstiges</option>';

        const metaMarkets = document.querySelector('meta[name="market-categories"]');
        const metaUnique = document.querySelector('meta[name="unique-cats"]');
        const marketCategories = metaMarkets ? JSON.parse(metaMarkets.content) : {};
        const uniqueCats = metaUnique ? JSON.parse(metaUnique.content) : [];

        let catsToShow;
        if (selectedMarket === 'Übergreifend' || selectedMarket === 'all') {
            catsToShow = uniqueCats || [];
        } else {
            const marketData = (marketCategories && marketCategories[selectedMarket]) || [];
            catsToShow = marketData.map(c => c.category_name);
        }

        catsToShow.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c;
            const icon = window.CATEGORY_ICONS[c] || '🛒';
            opt.textContent = icon + ' ' + c;
            categorySelect.appendChild(opt);
        });

        const exists = Array.from(categorySelect.options).some(opt => opt.value === currentCategory);
        if (exists) {
            categorySelect.value = currentCategory;
        } else {
            categorySelect.value = 'Sonstiges';
        }
    };

    const attachCategoryFilter = (marketId, catId) => {
        const marketEl = document.getElementById(marketId);
        if (marketEl) {
            marketEl.addEventListener('change', () => window.updateCategoryDropdown(marketId, catId));
            window.updateCategoryDropdown(marketId, catId);
        }
    };

    attachCategoryFilter('modal-add-market', 'modal-add-category');
    attachCategoryFilter('modal-list-item-market', 'modal-list-item-category');
    attachCategoryFilter('modal-product-market', 'modal-product-category');

    // Edit Slider Logic
    const editSlider = document.getElementById('edit-item-slider');
    const editSliderDisplay = document.getElementById('edit-item-slider-display');
    const editUnitDisplay = document.getElementById('edit-item-unit-display');
    const editSliderTicks = document.getElementById('edit-item-slider-ticks');
    const editModalQuantity = document.getElementById('modal-list-item-quantity');
    const editModalUnit = document.getElementById('modal-list-item-unit');

    let currentEditSliderValues = [];

    window.initEditSlider = function (unit) {
        if (!editSlider) return;
        if (unit === 'g') {
            currentEditSliderValues = [100, 200, 250, 400, 500, 750, 1000];
        } else if (unit === 'kg') {
            currentEditSliderValues = [0.5, 1, 1.5, 2, 2.5, 3, 5];
        } else if (unit === 'Liter') {
            currentEditSliderValues = [0.5, 1, 1.5, 2, 3, 5];
        } else if (unit === 'ml') {
            currentEditSliderValues = [100, 200, 250, 330, 400, 500, 750];
        } else {
            currentEditSliderValues = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        }

        editSlider.max = currentEditSliderValues.length - 1;

        editSliderTicks.innerHTML = '';
        currentEditSliderValues.forEach(val => {
            const span = document.createElement('span');
            span.textContent = val;
            editSliderTicks.appendChild(span);
        });

        editUnitDisplay.textContent = unit;
        syncEditSliderWithInput();
    };

    function syncEditSliderWithInput() {
        if (!editSlider) return;
        const val = parseFloat(editModalQuantity.value) || 1;
        let closestIdx = 0;
        let minDiff = Infinity;
        currentEditSliderValues.forEach((v, idx) => {
            const diff = Math.abs(v - val);
            if (diff < minDiff) {
                minDiff = diff;
                closestIdx = idx;
            }
        });
        editSlider.value = closestIdx;
        editSliderDisplay.textContent = currentEditSliderValues[closestIdx];
    }

    if (editSlider) {
        editSlider.addEventListener('input', () => {
            const val = currentEditSliderValues[editSlider.value];
            editSliderDisplay.textContent = val;
            editModalQuantity.value = val;
        });

        editModalQuantity.addEventListener('input', syncEditSliderWithInput);

        editModalUnit.addEventListener('change', () => {
            window.initEditSlider(editModalUnit.value);
        });
    }

    function closeListItemModal() {
        if (listItemEditModal) listItemEditModal.classList.add('hidden');
    }

    if (btnCloseListItemModal) btnCloseListItemModal.addEventListener('click', closeListItemModal);
    if (btnCancelListItem) btnCancelListItem.addEventListener('click', closeListItemModal);

    if (btnSaveListItem) {
        btnSaveListItem.addEventListener('click', async () => {
            const id = document.getElementById('modal-list-item-id').value;
            const name = document.getElementById('modal-list-item-name').value.trim();
            const quantity = parseFloat(document.getElementById('modal-list-item-quantity').value);
            const unit = document.getElementById('modal-list-item-unit').value;
            const market = document.getElementById('modal-list-item-market').value;
            const category = document.getElementById('modal-list-item-category').value;
            const note = document.getElementById('modal-list-item-note').value.trim();

            const payload = {
                action: 'edit_list_item',
                id,
                name,
                quantity,
                unit,
                market,
                category,
                note
            };

            const res = await KaiHttp.postJson('api.php', payload);
            if (res.success) {
                showToast(res.message || 'Eintrag aktualisiert');
                window.location.reload();
            } else {
                showToast(res.message || 'Fehler beim Aktualisieren', true);
            }
        });
    }

    // ==============================================================
    // PHASE 2: EINKAUFS-SESSIONS & MOBILER LIVE-MODUS
    // ==============================================================
    const activeBanner = document.getElementById('shopping-active-banner');
    const liveOverlay = document.getElementById('shopping-live-overlay');
    const checkoutModal = document.getElementById('checkout-confirm-modal');
    const linkReceiptsModal = document.getElementById('session-link-receipts-modal');
    const analysisModal = document.getElementById('session-analysis-modal');

    let currentLiveMarket = 'all';

    // 1. Session starten
    document.addEventListener('click', async (e) => {
        const startBtn = e.target.closest('.js-start-session-btn');
        if (startBtn) {
            const type = startBtn.dataset.type || 'wocheneinkauf';
            startBtn.disabled = true;
            try {
                const res = await KaiHttp.postJson(API_URL, {action: 'start_session', session_type: type});
                if (res.success) {
                    showToast(res.message || 'Einkauf gestartet');
                    if (activeBanner) {
                        activeBanner.classList.remove('hidden');
                        activeBanner.dataset.sessionId = res.session_id;
                        const cancelBtnInBanner = activeBanner.querySelector('.js-cancel-session-btn');
                        if (cancelBtnInBanner) cancelBtnInBanner.dataset.sessionId = res.session_id;
                        const typeEl = document.getElementById('banner-session-type');
                        if (typeEl) typeEl.textContent = type.charAt(0).toUpperCase() + type.slice(1);
                    }
                    const startBar = document.getElementById('shopping-start-session-bar');
                    if (startBar) startBar.style.display = 'none';
                    openLiveMode();
                } else {
                    showToast(res.message || 'Fehler beim Starten', true);
                }
            } catch (err) {
                showToast('Verbindungsfehler beim Starten', true);
            } finally {
                startBtn.disabled = false;
            }
            return;
        }

        // Session abbrechen
        const cancelBtn = e.target.closest('.js-cancel-session-btn');
        if (cancelBtn) {
            let sessionId = parseInt(cancelBtn.dataset.sessionId, 10);
            if ((!sessionId || isNaN(sessionId)) && activeBanner) {
                sessionId = parseInt(activeBanner.dataset.sessionId, 10);
            }
            if (!sessionId || isNaN(sessionId)) {
                sessionId = null;
            }
            if (!confirm('Möchtest du diesen Einkauf wirklich abbrechen? Deine Artikel bleiben auf der Liste.')) return;

            const res = await KaiHttp.postJson(API_URL, { action: 'cancel_session', session_id: sessionId });
            if (res.success) {
                showToast(res.message || 'Einkauf abgebrochen');
                window.location.reload();
            } else {
                showToast(res.message || 'Fehler beim Abbrechen', true);
            }
            return;
        }

        // Live-Modus öffnen
        if (e.target.closest('.js-open-live-mode')) {
            openLiveMode();
            return;
        }

        // Live-Modus schließen / pausieren
        if (e.target.closest('.js-close-live-mode')) {
            closeLiveMode();
            return;
        }

        // Live-Modus: Einkauf beenden Trigger
        if (e.target.closest('.js-finish-live-session')) {
            openCheckoutConfirmModal();
            return;
        }

        // Checkout Modal Abbrechen
        if (e.target.closest('#btn-cancel-checkout-modal') || e.target.closest('#btn-close-checkout-modal')) {
            if (checkoutModal) checkoutModal.classList.add('hidden');
            return;
        }

        // Checkout Bestätigen
        if (e.target.closest('#btn-confirm-checkout')) {
            const confirmBtn = document.getElementById('btn-confirm-checkout');
            let sessionId = activeBanner ? parseInt(activeBanner.dataset.sessionId, 10) : null;
            if (!sessionId) {
                showToast('Keine aktive Session gefunden', true);
                return;
            }
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Schließe ab...';

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'complete_session',
                    session_id: sessionId,
                    market: currentLiveMarket
                });
                if (res.success) {
                    showToast(res.message || 'Einkauf erfolgreich abgeschlossen!');
                    if (checkoutModal) checkoutModal.classList.add('hidden');
                    if (liveOverlay) liveOverlay.classList.add('hidden');
                    document.body.style.overflow = '';
                    window.location.href = '?tab=history';
                } else {
                    showToast(res.message || 'Fehler beim Beenden', true);
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Ja, Einkauf beenden';
                }
            } catch (err) {
                showToast('Verbindungsfehler beim Checkout', true);
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Ja, Einkauf beenden';
            }
            return;
        }

        // Live-Modus: Filter-Wechsel
        const liveFilterBtn = e.target.closest('.js-live-market-filter');
        if (liveFilterBtn) {
            document.querySelectorAll('.js-live-market-filter').forEach(btn => {
                btn.classList.remove('btn-active-filter');
                btn.classList.add('btn-outline');
            });
            liveFilterBtn.classList.remove('btn-outline');
            liveFilterBtn.classList.add('btn-active-filter');
            currentLiveMarket = liveFilterBtn.dataset.market || 'all';
            applyLiveMarketFilter(currentLiveMarket);
            return;
        }

        // Live-Modus: Artikelzeile antippen / abhaken
        const liveItemRow = e.target.closest('.shopping-live-item-row');
        if (liveItemRow && !e.target.classList.contains('shopping-live-checkbox')) {
            const itemId = liveItemRow.dataset.id;
            toggleLiveItem(liveItemRow, itemId);
            return;
        }

        // Kassenbons verknüpfen Modal öffnen
        const linkReceiptsBtn = e.target.closest('.js-link-receipts-btn');
        if (linkReceiptsBtn) {
            const sessionId = linkReceiptsBtn.dataset.sessionId;
            openLinkReceiptsModal(sessionId);
            return;
        }

        // Kassenbon Verknüpfung / Lösen Aktion
        const toggleReceiptLinkBtn = e.target.closest('.js-toggle-receipt-link');
        if (toggleReceiptLinkBtn) {
            const receiptId = toggleReceiptLinkBtn.dataset.receiptId;
            const sessionId = toggleReceiptLinkBtn.dataset.sessionId;
            const isLinked = toggleReceiptLinkBtn.dataset.linked === '1';
            toggleReceiptLinkBtn.disabled = true;

            const action = isLinked ? 'unlink_receipt' : 'link_receipt';
            const res = await KaiHttp.postJson(API_URL, {action, receipt_id: receiptId, session_id: sessionId});
            if (res.success) {
                showToast(res.message);
                openLinkReceiptsModal(sessionId); // Neu laden
            } else {
                showToast(res.message || 'Fehler', true);
                toggleReceiptLinkBtn.disabled = false;
            }
            return;
        }

        // E-Bon Analyse Modal öffnen
        const analysisBtn = e.target.closest('.js-view-session-analysis-btn');
        if (analysisBtn) {
            const sessionId = analysisBtn.dataset.sessionId;
            openSessionAnalysisModal(sessionId);
            return;
        }

        // Modals schließen
        if (e.target.closest('#btn-close-link-receipts-modal') || e.target.closest('#btn-cancel-link-receipts-modal')) {
            if (linkReceiptsModal) linkReceiptsModal.classList.add('hidden');
            return;
        }
        if (e.target.closest('#btn-close-analysis-modal') || e.target.closest('#btn-cancel-analysis-modal')) {
            if (analysisModal) analysisModal.classList.add('hidden');

        }
    });

    // Checkbox-Change im Live-Modus
    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('shopping-live-checkbox')) {
            const row = e.target.closest('.shopping-live-item-row');
            if (row) {
                toggleLiveItem(row, row.dataset.id);
            }
        }
    });

    // Hilfsfunktion: Live-Modus öffnen & befüllen
    function openLiveMode() {
        if (!liveOverlay) return;

        const liveContent = document.getElementById('shopping-live-content');
        if (!liveContent) return;

        // Alle Quell-Gänge aus der normalen Ansicht kopieren
        const aisleGroups = document.querySelectorAll('.shopping-aisle-group');
        let html = '';

        if (aisleGroups.length === 0) {
            html = '<div class="card text-center" style="padding: 2.5rem 1rem;"><p>🎉 Keine Artikel auf der Liste!</p></div>';
        } else {
            aisleGroups.forEach(group => {
                const titleEl = group.querySelector('.aisle-title');
                const titleHtml = titleEl ? titleEl.innerHTML : 'Gang';
                const items = group.querySelectorAll('.shopping-item-row');

                if (items.length > 0) {
                    html += `<div class="shopping-live-aisle-group">
                        <div class="shopping-live-aisle-header">
                            ${titleHtml}
                        </div>
                        <div class="shopping-live-items-list">`;

                    items.forEach(item => {
                        const id = item.dataset.id;
                        const name = item.dataset.name || '';
                        const qty = item.dataset.quantity || '1';
                        const unit = item.dataset.unit || 'Stück';
                        const market = item.dataset.market || 'Rewe';
                        const note = item.dataset.note || '';
                        const isChecked = item.classList.contains('is-checked');
                        const marketBadgeClass = market === 'Rewe' ? 'badge-rewe' : (market === 'Globus' ? 'badge-globus' : 'badge-info');

                        html += `
                            <div class="shopping-live-item-row ${isChecked ? 'is-checked' : ''}" 
                                 data-id="${id}" 
                                 data-market="${KaiHtml.escape(market)}" 
                                 data-checked="${isChecked ? '1' : '0'}">
                                <input type="checkbox" class="shopping-live-checkbox" ${isChecked ? 'checked' : ''}>
                                <div class="shopping-live-item-body">
                                    <div>
                                        <div class="shopping-live-item-name">
                                            ${KaiHtml.escape(name)}
                                            <span class="shopping-live-item-qty">${KaiHtml.escape(qty)} ${KaiHtml.escape(unit)}</span>
                                        </div>
                                        ${note ? `<div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;"><i>${KaiHtml.escape(note)}</i></div>` : ''}
                                    </div>
                                    <div>
                                        <span class="badge badge-market ${marketBadgeClass}">${KaiHtml.escape(market)}</span>
                                    </div>
                                </div>
                            </div>
                        `;
                    });

                    html += `</div></div>`;
                }
            });
        }

        liveContent.innerHTML = html;
        document.body.style.overflow = 'hidden';
        liveOverlay.classList.remove('hidden');
        applyLiveMarketFilter(currentLiveMarket);
        updateLiveCounters();
    }

    function closeLiveMode() {
        document.body.style.overflow = '';
        if (liveOverlay) liveOverlay.classList.add('hidden');
    }

    function applyLiveMarketFilter(market) {
        const rows = document.querySelectorAll('.shopping-live-item-row');
        rows.forEach(row => {
            const m = row.dataset.market;
            const match = (market === 'all' || m === market || m === 'Übergreifend');
            row.style.display = match ? 'flex' : 'none';
        });

        // Leere Gang-Gruppen im Filter ausblenden
        document.querySelectorAll('.shopping-live-aisle-group').forEach(group => {
            const visibleRows = Array.from(group.querySelectorAll('.shopping-live-item-row')).filter(r => r.style.display !== 'none');
            group.style.display = visibleRows.length > 0 ? 'block' : 'none';
        });

        updateLiveCounters();
    }

    async function toggleLiveItem(row, itemId) {
        try {
            const res = await KaiHttp.postJson(API_URL, {action: 'toggle_item_check', id: itemId});
            if (res.success) {
                if (res.sync_hash && window.shoppingSync) {
                    window.shoppingSync.setHash(res.sync_hash);
                }
                const checked = res.is_checked ? 1 : 0;
                row.dataset.checked = checked;
                row.classList.toggle('is-checked', checked === 1);
                const cb = row.querySelector('.shopping-live-checkbox');
                if (cb) cb.checked = checked === 1;

                // Sync mit normaler Ansicht
                const regRow = document.querySelector(`.shopping-item-row[data-id="${itemId}"]`);
                if (regRow) {
                    regRow.classList.toggle('is-checked', checked === 1);
                    const regCb = regRow.querySelector('.shopping-checkbox');
                    if (regCb) regCb.checked = checked === 1;
                }

                updateLiveCounters();
            } else {
                showToast(res.message || 'Fehler beim Abhaken', true);
            }
        } catch (err) {
            showToast('Verbindungsfehler', true);
        }
    }

    function updateLiveCounters() {
        const visibleRows = Array.from(document.querySelectorAll('.shopping-live-item-row')).filter(r => r.style.display !== 'none');
        const checkedRows = visibleRows.filter(r => r.dataset.checked === '1');

        const checkedEl = document.getElementById('live-checked-counter');
        const totalEl = document.getElementById('live-total-counter');
        if (checkedEl) checkedEl.textContent = checkedRows.length;
        if (totalEl) totalEl.textContent = visibleRows.length;

        // Auch Banner synchronisieren
        const allChecked = document.querySelectorAll('.shopping-live-item-row[data-checked="1"]').length;
        const allTotal = document.querySelectorAll('.shopping-live-item-row').length;
        const bannerChecked = document.getElementById('banner-checked-count');
        const bannerTotal = document.getElementById('banner-total-count');
        if (bannerChecked) bannerChecked.textContent = allChecked;
        if (bannerTotal) bannerTotal.textContent = allTotal;
    }

    function openCheckoutConfirmModal() {
        if (!checkoutModal) return;
        const allRows = Array.from(document.querySelectorAll('.shopping-live-item-row'));
        const checkedCount = allRows.filter(r => r.dataset.checked === '1').length;
        const openCount = allRows.filter(r => r.dataset.checked === '0').length;

        const chkEl = document.getElementById('checkout-modal-checked-count');
        const opEl = document.getElementById('checkout-modal-open-count');
        if (chkEl) chkEl.textContent = checkedCount;
        if (opEl) opEl.textContent = openCount;

        checkoutModal.classList.remove('hidden');
    }

    // Modal: Kassenbons verknüpfen
    async function openLinkReceiptsModal(sessionId) {
        if (!linkReceiptsModal) return;
        linkReceiptsModal.classList.remove('hidden');
        const listContainer = document.getElementById('candidate-receipts-list');
        const loader = document.getElementById('candidate-receipts-loading');

        if (loader) loader.classList.remove('hidden');
        if (listContainer) listContainer.innerHTML = '';

        try {
            const res = await KaiHttp.postJson(API_URL, {action: 'get_session_candidates', session_id: sessionId});
            if (loader) loader.classList.add('hidden');

            if (res.success && res.candidates && res.candidates.length > 0) {
                let html = '';
                res.candidates.forEach(c => {
                    const isLinked = c.is_currently_linked === 1;
                    html += `
                        <div class="card" style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; margin-bottom: 0.5rem; border-color: ${isLinked ? 'var(--color-green)' : 'var(--border)'};">
                            <div>
                                <strong>${KaiHtml.escape(c.store)}</strong>
                                <span class="badge badge-market ${c.store.toLowerCase().includes('rewe') ? 'badge-rewe' : 'badge-globus'}">${KaiHtml.escape(c.purchase_date)}</span>
                                <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 2px;">
                                    ${parseFloat(c.total).toFixed(2).replace('.', ',')} € &bull; ${c.item_count} Artikel
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm ${isLinked ? 'btn-outline' : 'btn-success'} js-toggle-receipt-link"
                                    data-receipt-id="${c.id}" 
                                    data-session-id="${sessionId}" 
                                    data-linked="${isLinked ? '1' : '0'}">
                                ${isLinked ? '❌ Lösen' : '➕ Verknüpfen'}
                            </button>
                        </div>
                    `;
                });
                listContainer.innerHTML = html;
            } else {
                listContainer.innerHTML = '<div class="text-center text-muted" style="padding: 1.5rem;">Keine passenden Kassenbons für diesen Zeitraum gefunden.</div>';
            }
        } catch (err) {
            if (loader) loader.classList.add('hidden');
            listContainer.innerHTML = '<div class="text-center text-danger" style="padding: 1rem;">Fehler beim Laden der Belege.</div>';
        }
    }

    // Modal: E-Bon-Analyse & Spontankäufe
    async function openSessionAnalysisModal(sessionId) {
        if (!analysisModal) return;
        analysisModal.classList.remove('hidden');

        const content = document.getElementById('session-analysis-content');
        const loader = document.getElementById('session-analysis-loading');
        if (loader) loader.classList.remove('hidden');
        if (content) content.innerHTML = '';

        try {
            const res = await KaiHttp.postJson(API_URL, {action: 'get_session_analysis', session_id: sessionId});
            if (loader) loader.classList.add('hidden');

            if (res.success && res.data) {
                const d = res.data;
                if (d.receipt_count === 0) {
                    content.innerHTML = `
                        <div class="card text-center" style="padding: 2rem;">
                            <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">🧾 Noch kein Kassenbon verknüpft</p>
                            <p class="text-muted" style="font-size: 0.9rem; margin-bottom: 1.25rem;">
                                Um geplante Artikel und Spontankäufe abzugleichen, verknüpfe bitte zuerst die E-Bons dieses Einkaufs.
                            </p>
                            <button type="button" class="btn btn-primary js-link-receipts-btn" data-session-id="${sessionId}">
                                ➕ Jetzt Kassenbons verknüpfen
                            </button>
                        </div>
                    `;
                    return;
                }

                let html = `
                    <div class="shopping-analysis-grid">
                        <div class="shopping-analysis-kpi">
                            <div class="text-muted" style="font-size: 0.85rem;">Gesamtausgaben</div>
                            <div class="kpi-value">${d.total_cost.toFixed(2).replace('.', ',')} €</div>
                            <div class="text-muted" style="font-size: 0.8rem; margin-top: 4px;">aus ${d.receipt_count} Beleg(en)</div>
                        </div>
                        <div class="shopping-analysis-kpi kpi-planned">
                            <div class="text-muted" style="font-size: 0.85rem;">Geplanter Einkauf</div>
                            <div class="kpi-value">${d.planned_cost.toFixed(2).replace('.', ',')} €</div>
                            <div class="text-muted" style="font-size: 0.8rem; margin-top: 4px;">${d.planned_items.length} Artikel von der Liste</div>
                        </div>
                        <div class="shopping-analysis-kpi kpi-spontaneous">
                            <div class="text-muted" style="font-size: 0.85rem;">Spontankäufe</div>
                            <div class="kpi-value">${d.spontaneous_cost.toFixed(2).replace('.', ',')} €</div>
                            <div class="text-muted" style="font-size: 0.8rem; margin-top: 4px;">${d.spontaneous_pct_cost}% des Gesamtbetrags (${d.spontaneous_items.length} Artikel)</div>
                        </div>
                    </div>
                `;

                if (d.spontaneous_items.length > 0) {
                    html += `
                        <div class="card" style="margin-bottom: 1.25rem; border-color: rgba(245, 158, 11, 0.4);">
                            <h4 style="color: var(--color-orange); margin-bottom: 0.75rem;">⚡ Spontankäufe (${d.spontaneous_items.length})</h4>
                            <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 0.75rem;">
                                Diese Artikel standen <strong>nicht</strong> auf deiner Einkaufsliste und wurden spontan im Markt mitgenommen:
                            </p>
                            <div class="table-responsive">
                                <table class="data-table stack-table table-compact">
                                    <thead>
                                        <tr>
                                            <th>Artikel</th>
                                            <th>Markt</th>
                                            <th>Menge</th>
                                            <th class="text-right">Betrag</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;
                    d.spontaneous_items.forEach(it => {
                        html += `
                            <tr>
                                <td data-label="Artikel"><strong>${KaiHtml.escape(it.name)}</strong></td>
                                <td data-label="Markt"><span class="badge badge-market ${it.store.toLowerCase().includes('rewe') ? 'badge-rewe' : 'badge-globus'}">${KaiHtml.escape(it.store)}</span></td>
                                <td data-label="Menge">${it.quantity > 1 ? it.quantity + 'x' : '1x'}</td>
                                <td data-label="Betrag" class="text-right"><strong>${it.total_price.toFixed(2).replace('.', ',')} €</strong></td>
                            </tr>
                        `;
                    });
                    html += `</tbody></table></div></div>`;
                } else {
                    html += `
                        <div class="card text-center" style="padding: 1.25rem; margin-bottom: 1.25rem; background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.3);">
                            <p style="margin-bottom: 0; color: var(--color-green); font-weight: 600;">🎯 Perfekt diszipliniert! Keine Spontankäufe auf den Kassenbons entdeckt.</p>
                        </div>
                    `;
                }

                if (d.planned_items.length > 0) {
                    html += `
                        <div class="card">
                            <h4 style="margin-bottom: 0.75rem;">✔️ Geplante Einkäufe (${d.planned_items.length})</h4>
                            <div class="table-responsive">
                                <table class="data-table stack-table table-compact">
                                    <thead>
                                        <tr>
                                            <th>Artikel (Kassenbon)</th>
                                            <th>Zugeordnet zu</th>
                                            <th>Markt</th>
                                            <th class="text-right">Betrag</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;
                    d.planned_items.forEach(it => {
                        html += `
                            <tr>
                                <td data-label="Artikel">${KaiHtml.escape(it.name)}</td>
                                <td data-label="Zugeordnet zu"><strong>${KaiHtml.escape(it.display_name)}</strong></td>
                                <td data-label="Markt"><span class="badge badge-market ${it.store.toLowerCase().includes('rewe') ? 'badge-rewe' : 'badge-globus'}">${KaiHtml.escape(it.store)}</span></td>
                                <td data-label="Betrag" class="text-right">${it.total_price.toFixed(2).replace('.', ',')} €</td>
                            </tr>
                        `;
                    });
                    html += `</tbody></table></div></div>`;
                }

                content.innerHTML = html;
            } else {
                content.innerHTML = '<div class="text-center text-danger" style="padding: 1.5rem;">Fehler beim Laden der Auswertung.</div>';
            }
        } catch (err) {
            if (loader) loader.classList.add('hidden');
            content.innerHTML = '<div class="text-center text-danger" style="padding: 1.5rem;">Verbindungsfehler beim Laden der Analyse.</div>';
        }
    }

    // ==============================================================
    // REALTIME-SYNCHRONISATION (SHOPPING LIST SYNC)
    // ==============================================================
    class ShoppingListSync {
        constructor() {
            const metaHash = document.querySelector('meta[name="shopping-sync-hash"]');
            this.currentHash = metaHash ? metaHash.getAttribute('content') : '';
            this.timer = null;
            this.isPolling = false;
            this.visibleInterval = 4000;  // 4s wenn Tab im Vordergrund
            this.hiddenInterval = 30000;  // 30s wenn Tab im Hintergrund
            this.isInitialLoad = true;

            this.init();
        }

        init() {
            this.scheduleNext(this.visibleInterval);

            // Tab-Wechsel und Wiederkehr erkennen
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.pollNow();
                } else {
                    this.scheduleNext(this.hiddenInterval);
                }
            });

            window.addEventListener('focus', () => {
                this.pollNow();
            });
        }

        scheduleNext(ms) {
            if (this.timer) clearTimeout(this.timer);
            this.timer = setTimeout(() => this.poll(), ms);
        }

        async pollNow() {
            if (this.timer) clearTimeout(this.timer);
            await this.poll();
        }

        setHash(newHash) {
            if (newHash) {
                this.currentHash = newHash;
                const metaHash = document.querySelector('meta[name="shopping-sync-hash"]');
                if (metaHash) metaHash.setAttribute('content', newHash);
            }
        }

        getActiveMarket() {
            const metaMarket = document.querySelector('meta[name="active-market"]');
            return metaMarket ? metaMarket.getAttribute('content') : 'all';
        }

        async poll() {
            if (this.isPolling) return;
            this.isPolling = true;

            try {
                const res = await KaiHttp.postJson(API_URL, {
                    action: 'get_sync_state',
                    current_hash: this.currentHash
                });

                if (res.success) {
                    if (res.changed && res.items) {
                        const previousHash = this.currentHash;
                        this.setHash(res.hash);

                        // Aktualisierungen durchführen
                        this.applySyncUpdate(res, previousHash !== '');
                    } else if (res.hash) {
                        this.setHash(res.hash);
                    }
                }
            } catch (err) {
                // Stilles Ignorieren von Netzwerkfehlern beim Polling (z.B. Offline oder Funkloch)
            } finally {
                this.isPolling = false;
                const nextInterval = document.hidden ? this.hiddenInterval : this.visibleInterval;
                this.scheduleNext(nextInterval);
            }
        }

        applySyncUpdate(data, showNotification) {
            const allItems = data.items || [];
            const marketCounts = data.market_counts || {};
            const activeSession = data.active_session;

            // 1. Zähler in Navigation & Filtern aktualisieren
            this.updateHeaderAndCounters(marketCounts);

            // 2. Aktives Einkaufs-Banner aktualisieren
            this.updateSessionBanner(activeSession);

            // 3. Hauptansicht (tab-list) aktualisieren
            const activeMarket = this.getActiveMarket();
            const relevantItems = activeMarket === 'all'
                ? allItems
                : allItems.filter(i => i.market === activeMarket || i.market === 'Übergreifend');

            const newlyAddedIds = this.renderMainList(relevantItems);

            // 4. Live-Modus Overlay aktualisieren (falls gerade geöffnet)
            const liveNewlyAdded = this.updateLiveOverlay(allItems);

            // 5. Dezente Benachrichtigung für Nutzer
            if (showNotification) {
                const totalNew = Math.max(newlyAddedIds.length, liveNewlyAdded.length);
                if (totalNew > 0) {
                    showToast(`🛒 ${totalNew} neue(r) Artikel auf die Liste gesetzt!`);
                }
            }
        }

        updateHeaderAndCounters(counts) {
            // Tab-Badge Einkaufsliste
            const navBadge = document.getElementById('shopping-nav-list-count');
            if (navBadge && counts.all) {
                navBadge.textContent = counts.all.open;
            }

            // Filter Buttons (Alle / Rewe / Globus)
            document.querySelectorAll('.js-market-filter').forEach(btn => {
                const m = btn.dataset.market;
                if (m === 'all' && counts.all) {
                    btn.textContent = `Alle Märkte (${counts.all.open})`;
                } else if (m === 'Rewe' && counts.Rewe) {
                    btn.textContent = `🔴 Rewe (${counts.Rewe.open})`;
                } else if (m === 'Globus' && counts.Globus) {
                    btn.textContent = `🟠 Globus (${counts.Globus.open})`;
                }
            });

            // "Einkauf abschließen"-Button
            const completeBtn = document.querySelector('.shopping-market-filter-card .js-complete-shopping-btn');
            const activeMarket = this.getActiveMarket();
            const checkedCount = counts[activeMarket] ? counts[activeMarket].checked : (counts.all ? counts.all.checked : 0);

            if (checkedCount > 0) {
                if (completeBtn) {
                    completeBtn.textContent = `✔️ Einkauf abschließen (${checkedCount})`;
                    completeBtn.style.display = '';
                }
            } else if (completeBtn) {
                completeBtn.style.display = 'none';
            }
        }

        updateSessionBanner(session) {
            const banner = document.getElementById('shopping-active-banner');
            const startBar = document.getElementById('shopping-start-session-bar');

            if (session) {
                if (banner) {
                    banner.classList.remove('hidden');
                    banner.dataset.sessionId = session.id;
                    const typeEl = document.getElementById('banner-session-type');
                    if (typeEl) typeEl.textContent = session.session_type.charAt(0).toUpperCase() + session.session_type.slice(1);
                    const chkEl = document.getElementById('banner-checked-count');
                    const totEl = document.getElementById('banner-total-count');
                    if (chkEl) chkEl.textContent = session.checked_count;
                    if (totEl) totEl.textContent = session.total_count;

                    const cancelBtn = banner.querySelector('.js-cancel-session-btn');
                    if (cancelBtn) cancelBtn.dataset.sessionId = session.id;
                }
                if (startBar) startBar.style.display = 'none';
            } else {
                if (banner) {
                    banner.classList.add('hidden');
                    banner.dataset.sessionId = '';
                }
                if (startBar) startBar.style.display = '';
            }
        }

        renderMainList(items) {
            const container = document.getElementById('shopping-items-container');
            if (!container) return [];

            const existingRows = container.querySelectorAll('.shopping-item-row');
            const oldIds = new Set(Array.from(existingRows).map(r => parseInt(r.dataset.id, 10)));

            const openItems = items.filter(i => parseInt(i.is_checked, 10) === 0);
            const checkedItems = items.filter(i => parseInt(i.is_checked, 10) === 1);

            const newlyAddedIds = [];
            openItems.forEach(it => {
                if (!oldIds.has(parseInt(it.id, 10))) {
                    newlyAddedIds.push(parseInt(it.id, 10));
                }
            });

            if (openItems.length === 0 && checkedItems.length === 0) {
                container.innerHTML = `
                    <div class="card text-center shopping-empty-state">
                        <p>🎉 Keine offenen Artikel für diesen Markt auf der Einkaufsliste!</p>
                        <button type="button" class="btn btn-outline js-tab-btn" data-tab="suggestions">💡 Vorschläge prüfen</button>
                    </div>
                `;
                return newlyAddedIds;
            }

            // Gruppieren nach Kategorie/Gang
            const groupedOpen = {};
            openItems.forEach(item => {
                const cat = item.category || 'Sonstiges';
                const order = parseInt(item.aisle_order || 999, 10);
                if (!groupedOpen[cat]) {
                    groupedOpen[cat] = {
                        name: cat,
                        order: order,
                        items: []
                    };
                }
                groupedOpen[cat].items.push(item);
            });

            const sortedCategories = Object.keys(groupedOpen).sort((a, b) => groupedOpen[a].order - groupedOpen[b].order);

            let html = '';
            if (openItems.length === 0) {
                html += `
                    <div class="card text-center shopping-empty-state">
                        <p>🎉 Keine offenen Artikel für diesen Markt auf der Einkaufsliste!</p>
                        <button type="button" class="btn btn-outline js-tab-btn" data-tab="suggestions">💡 Vorschläge prüfen</button>
                    </div>
                `;
            } else {
                sortedCategories.forEach(catName => {
                    const group = groupedOpen[catName];
                    const catIcon = (window.CATEGORY_ICONS && window.CATEGORY_ICONS[catName]) || '🛒';
                    const aisleLabel = group.order < 900 ? `Gang ${group.order}` : '❓';

                    html += `
                        <div class="card shopping-aisle-group">
                            <div class="shopping-aisle-header">
                                <h4 class="aisle-title">
                                    <span class="aisle-badge">${aisleLabel}</span>
                                    ${catIcon} ${KaiHtml.escape(catName)}
                                    <span class="text-muted">(${group.items.length})</span>
                                </h4>
                            </div>
                            <div class="shopping-items-list">
                    `;

                    group.items.forEach(item => {
                        const isNew = newlyAddedIds.includes(parseInt(item.id, 10));
                        html += this.buildMainItemRow(item, false, isNew);
                    });

                    html += `</div></div>`;
                });
            }

            // Abgehakte Artikel
            if (checkedItems.length > 0) {
                const activeMarket = this.getActiveMarket();
                html += `
                    <div class="card shopping-checked-group">
                        <div class="shopping-aisle-header">
                            <h4 class="aisle-title text-muted">
                                ✔️ Erledigt (${checkedItems.length})
                            </h4>
                            <button type="button" class="btn btn-sm btn-success js-complete-shopping-btn" data-market="${KaiHtml.escape(activeMarket)}">
                                Einkauf abschließen &amp; löschen
                            </button>
                        </div>
                        <div class="shopping-items-list shopping-checked-list">
                `;

                checkedItems.forEach(item => {
                    html += this.buildMainItemRow(item, true, false);
                });

                html += `</div></div>`;
            }

            container.innerHTML = html;
            return newlyAddedIds;
        }

        buildMainItemRow(item, isChecked, isNew) {
            const id = parseInt(item.id, 10);
            const name = item.name || '';
            const qty = parseFloat(item.quantity) || 1;
            const formattedQty = (qty === parseInt(qty, 10)) ? parseInt(qty, 10) : qty.toFixed(1).replace('.', ',');
            const unit = item.unit || 'Stück';
            const market = item.market || 'Rewe';
            const category = item.category || 'Sonstiges';
            const note = item.note || '';
            const marketBadgeClass = market === 'Rewe' ? 'badge-rewe' : 'badge-globus';
            const isSpontaneous = parseInt(item.is_spontaneous, 10) === 1;

            const newClass = isNew ? ' item-newly-added' : '';

            if (isChecked) {
                return `
                    <div class="shopping-item-row is-checked${newClass}" data-id="${id}">
                        <div class="shopping-item-check">
                            <input type="checkbox" class="shopping-checkbox js-item-check" data-id="${id}" checked title="Wieder öffnen">
                        </div>
                        <div class="shopping-item-details">
                            <span class="item-name strike-through">${KaiHtml.escape(name)}</span>
                            <span class="item-quantity text-muted">${formattedQty} ${KaiHtml.escape(unit)}</span>
                        </div>
                        <div class="shopping-item-meta">
                            <span class="badge badge-market ${marketBadgeClass}">${KaiHtml.escape(market)}</span>
                            <button type="button" class="btn-icon js-delete-item-btn" data-id="${id}" title="Löschen">🗑️</button>
                        </div>
                    </div>
                `;
            }

            let badges = `<span class="badge badge-market ${marketBadgeClass}">${KaiHtml.escape(market)}</span>`;
            if (isSpontaneous) {
                badges += `<span class="badge badge-warning" title="Spontankauf (verzerrt das Verbrauchsintervall nicht)">⚡ Spontan</span>`;
            }
            if (item.source === 'recipe') {
                badges += `<span class="badge badge-info" title="Aus Rezept generiert">🧑‍🍳 Rezept</span>`;
            } else if (item.source === 'suggestion') {
                badges += `<span class="badge badge-info" title="Aus automatischem Intervall vorgeschlagen">✨ Vorschlag</span>`;
            }

            return `
                <div class="shopping-item-row${newClass}"
                     data-id="${id}"
                     data-market="${KaiHtml.escape(market)}"
                     data-name="${KaiHtml.escape(name)}"
                     data-quantity="${qty}"
                     data-unit="${KaiHtml.escape(unit)}"
                     data-category="${KaiHtml.escape(category)}"
                     data-note="${KaiHtml.escape(note)}">
                    <div class="shopping-item-check">
                        <input type="checkbox" class="shopping-checkbox js-item-check" data-id="${id}" title="Als erledigt markieren">
                    </div>
                    <div class="shopping-item-details js-edit-list-item-trigger" style="cursor: pointer;">
                        <span class="item-name">${KaiHtml.escape(name)}</span>
                        <span class="item-quantity">${formattedQty} ${KaiHtml.escape(unit)}</span>
                        ${note ? `<div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;"><small><i>${KaiHtml.escape(note)}</i></small></div>` : ''}
                    </div>
                    <div class="shopping-item-meta">
                        ${badges}
                        <button type="button" class="btn-icon js-edit-list-item-trigger" title="Bearbeiten">✏️</button>
                        <button type="button" class="btn-icon js-delete-item-btn" data-id="${id}" title="Löschen">🗑️</button>
                    </div>
                </div>
            `;
        }

        updateLiveOverlay(items) {
            const liveOverlay = document.getElementById('shopping-live-overlay');
            const liveContent = document.getElementById('shopping-live-content');
            if (!liveOverlay || liveOverlay.classList.contains('hidden') || !liveContent) {
                return [];
            }

            const existingLiveRows = liveContent.querySelectorAll('.shopping-live-item-row');
            const liveOldIds = new Set(Array.from(existingLiveRows).map(r => parseInt(r.dataset.id, 10)));
            const newLiveItemIds = [];

            // Alle offenen & erledigten Artikel nach Gängen gruppieren
            // Items sortieren nach Gang (aisle_order)
            const grouped = {};
            items.forEach(it => {
                const cat = it.category || 'Sonstiges';
                const order = parseInt(it.aisle_order || 999, 10);
                if (!grouped[cat]) {
                    grouped[cat] = {
                        name: cat,
                        order: order,
                        items: []
                    };
                }
                grouped[cat].items.push(it);
                if (!liveOldIds.has(parseInt(it.id, 10))) {
                    newLiveItemIds.push(parseInt(it.id, 10));
                }
            });

            // Diffen der bestehenden DOM-Elemente
            // 1. Entfernte Items aus DOM löschen
            const itemMap = new Map(items.map(it => [parseInt(it.id, 10), it]));
            existingLiveRows.forEach(row => {
                const id = parseInt(row.dataset.id, 10);
                if (!itemMap.has(id)) {
                    row.remove();
                }
            });

            // 2. Bestehende Items aktualisieren (Checked-Status)
            existingLiveRows.forEach(row => {
                const id = parseInt(row.dataset.id, 10);
                const fresh = itemMap.get(id);
                if (fresh) {
                    const isChecked = parseInt(fresh.is_checked, 10) === 1;
                    const wasChecked = row.dataset.checked === '1';
                    if (isChecked !== wasChecked) {
                        row.dataset.checked = isChecked ? '1' : '0';
                        row.classList.toggle('is-checked', isChecked);
                        const cb = row.querySelector('.shopping-live-checkbox');
                        if (cb) cb.checked = isChecked;
                    }
                }
            });

            // 3. Neu hinzugekommene Items in die passenden Gang-Gruppen einhängen
            if (newLiveItemIds.length > 0) {
                newLiveItemIds.forEach(newId => {
                    const item = itemMap.get(newId);
                    if (!item) return;

                    const catName = item.category || 'Sonstiges';
                    const order = parseInt(item.aisle_order || 999, 10);
                    const catIcon = (window.CATEGORY_ICONS && window.CATEGORY_ICONS[catName]) || '🛒';
                    const aisleLabel = order < 900 ? `Gang ${order}` : '❓';

                    // Suche bestehende Gang-Gruppe im Live-Modus
                    let aisleGroup = null;
                    liveContent.querySelectorAll('.shopping-live-aisle-group').forEach(group => {
                        const header = group.querySelector('.shopping-live-aisle-header');
                        if (header && header.textContent.includes(catName)) {
                            aisleGroup = group;
                        }
                    });

                    if (!aisleGroup) {
                        // Gang-Gruppe neu erstellen
                        aisleGroup = document.createElement('div');
                        aisleGroup.className = 'shopping-live-aisle-group';
                        aisleGroup.dataset.order = order;
                        aisleGroup.innerHTML = `
                            <div class="shopping-live-aisle-header">
                                <span class="aisle-badge">${aisleLabel}</span>
                                ${catIcon} ${KaiHtml.escape(catName)}
                            </div>
                            <div class="shopping-live-items-list"></div>
                        `;

                        // An geordneter Stelle einfügen
                        let inserted = false;
                        const allGroups = liveContent.querySelectorAll('.shopping-live-aisle-group');
                        for (const g of allGroups) {
                            const gOrder = parseInt(g.dataset.order || 999, 10);
                            if (order < gOrder) {
                                liveContent.insertBefore(aisleGroup, g);
                                inserted = true;
                                break;
                            }
                        }
                        if (!inserted) {
                            liveContent.appendChild(aisleGroup);
                        }
                    }

                    const listContainer = aisleGroup.querySelector('.shopping-live-items-list');
                    if (listContainer) {
                        const rowEl = document.createElement('div');
                        const isChecked = parseInt(item.is_checked, 10) === 1;
                        const market = item.market || 'Rewe';
                        const marketBadgeClass = market === 'Rewe' ? 'badge-rewe' : (market === 'Globus' ? 'badge-globus' : 'badge-info');
                        const qty = parseFloat(item.quantity) || 1;
                        const formattedQty = (qty === parseInt(qty, 10)) ? parseInt(qty, 10) : qty.toFixed(1).replace('.', ',');
                        const unit = item.unit || 'Stück';

                        rowEl.className = `shopping-live-item-row item-newly-added${isChecked ? ' is-checked' : ''}`;
                        rowEl.dataset.id = item.id;
                        rowEl.dataset.market = market;
                        rowEl.dataset.checked = isChecked ? '1' : '0';

                        rowEl.innerHTML = `
                            <input type="checkbox" class="shopping-live-checkbox" ${isChecked ? 'checked' : ''}>
                            <div class="shopping-live-item-body">
                                <div>
                                    <div class="shopping-live-item-name">
                                        ${KaiHtml.escape(item.name)}
                                        <span class="shopping-live-item-qty">${formattedQty} ${KaiHtml.escape(unit)}</span>
                                    </div>
                                    ${item.note ? `<div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;"><i>${KaiHtml.escape(item.note)}</i></div>` : ''}
                                </div>
                                <div>
                                    <span class="badge badge-market ${marketBadgeClass}">${KaiHtml.escape(market)}</span>
                                </div>
                            </div>
                        `;
                        listContainer.appendChild(rowEl);
                    }
                });
            }

            // Filter anwenden & Zähler aktualisieren
            applyLiveMarketFilter(currentLiveMarket);
            updateLiveCounters();

            return newLiveItemIds;
        }
    }

    // Instanziieren und global zur Verfügung stellen
    window.shoppingSync = new ShoppingListSync();
});

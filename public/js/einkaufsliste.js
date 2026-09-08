
/**
 * einkaufsliste.js - Interaktive Steuerung der intelligenten Einkaufsliste
 *
 * Verwendet Event Delegation, KaiHttp (CSRF-POST) und KaiHtml (DOM-Escaping).
 */
document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const API_URL = 'api.php';

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
        if(!inputItemName) return;
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

        if(displayName) displayName.textContent = name;
        
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

        if(quickAddModal) quickAddModal.classList.remove('hidden');
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
        if(quickAddModal) quickAddModal.classList.add('hidden');
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
            
            const payload = {
                action: 'save_product_master', 
                id, 
                name: name, 
                preferred_market: market,
                default_unit: unit,
                default_category: category,
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
        // =========================================================
        // --- Inbox (Unbekannte eBons) ---
        // =========================================================
        const tabBtnInbox = document.getElementById('tab-btn-inbox');
        const inboxListContainer = document.getElementById('inbox-list-container');
        const inboxEmptyState = document.getElementById('inbox-empty-state');
        const inboxLoading = document.getElementById('inbox-loading-indicator');

        // Wir rendern in die Dropdowns alle verfügbaren Artikel als Datalist (einmalig)
        let productOptions = Array.from(document.querySelectorAll('#bulk-target-select option'))
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

                        // Update Badge
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

        // Wenn der Inbox-Tab geklickt wird, laden wir neu
        if (tabBtnInbox) {
            tabBtnInbox.addEventListener('click', loadInbox);
        }
        // Wenn die Seite bereits mit Tab=Inbox lädt
        if (new URLSearchParams(window.location.search).get('tab') === 'inbox') {
            loadInbox();
        }

        // Event Delegation für Inbox Actions
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

                            productOptions += `<option value="${res.new_product.id}">${KaiHtml.escape(res.new_product.name)}</option>`;
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
        // --- Bulk Merge (Artikelstamm Checkboxen) ---
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

        // KI-Cluster akzeptieren (Erstellt Target wenn nötig, führt dann zusammen)
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

                // Um ein sauberes KI-Cluster zu mergen, nutzen wir einen zweistufigen Prozess:
                // 1. Schauen ob "targetName" schon existiert (über API oder einfach in Inbox Logik).
                // Da wir im JS keine direkte ID für den Target Name haben, legen wir ihn als Inbox "new" an
                // oder suchen ihn.
                // WORKAROUND FÜR PHASE 1.5: Wir nehmen einfach das erste Element aus den sources als "Target"
                // und überschreiben seinen Namen auf den KI-Namen, dann mergen wir den Rest hinein.

                const targetId = sourceIds.shift(); // Erstes Element ist das Ziel

                try {
                    // a) Namen des Ziels aktualisieren
                    await KaiHttp.postJson(API_URL, {
                        action: 'save_product_master',
                        id: targetId,
                        custom_label: targetName,
                        is_ignored: 0
                    });

                    // b) Rest mergen
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
        });
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
    const productMasterFilter = document.getElementById('product-master-filter');
    if (productMasterFilter) {
        productMasterFilter.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase().trim();
            const table = document.getElementById('product-master-table');
            if (!table) return;

            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(term)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    }
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
                const firstChar = Array.from(text)[0];
                window.CATEGORY_ICONS[opt.value] = firstChar;
            }
        }
    });

    window.updateCategoryDropdown = function(marketSelectId, categorySelectId) {
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

        let catsToShow = [];
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

    window.initEditSlider = function(unit) {
        if (!editSlider) return;
        if (unit === 'g') {
            currentEditSliderValues = [100, 200, 250, 400, 500, 750, 1000];
        } else if (unit === 'kg') {
            currentEditSliderValues = [0.5, 1, 1.5, 2, 2.5, 3, 5];
        } else if (unit === 'Liter') {
            currentEditSliderValues = [0.5, 1, 1.5, 2, 3, 5];
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
});

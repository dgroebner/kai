/**
 * JavaScript-Logik für die Domäne "Recipes" (Kochrezepte & Küchenbegleiter).
 *
 * Unterstützt:
 * - Multimodalen KI-Import (Foto, PDF, URL, Freitext)
 * - Portionen-Skalierung (Erwachsene / Kinder) & Multi-Topf Zielzeit-Planung
 * - Übergabe an die Einkaufsliste inkl. Vorratsschrank-Filter
 * - KI-Zutatentausch ("Alternative suchen") & Speichern als Rezeptabwandlung
 * - Interaktiven Koch-Modus mit Screen Wake Lock API & parallelen Küchen-Timern
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const escape = window.KaiHtml ? window.KaiHtml.escape : function (s) { return String(s ?? ''); };

    // =========================================================================
    // 1. ÜBERSICHT & KI-IMPORT MODAL (index.php)
    // =========================================================================
    const importModal = document.getElementById('modal-recipe-import');
    const importForm = document.getElementById('form-recipe-import');
    const importSourceInput = document.getElementById('import-source-type');
    const importFileInput = document.getElementById('import-file-input');
    const importFilePreview = document.getElementById('import-file-preview');

    let selectedFileBase64 = null;
    let selectedFileMime = null;

    function openImportModal() {
        if (!importModal) return;
        importModal.classList.remove('hidden');
    }

    function closeImportModal() {
        if (!importModal) return;
        importModal.classList.add('hidden');
    }

    if (importFileInput) {
        importFileInput.addEventListener('change', function () {
            const file = this.files && this.files[0] ? this.files[0] : null;
            selectedFileBase64 = null;
            selectedFileMime = null;

            if (!file) {
                if (importFilePreview) importFilePreview.classList.add('hidden');
                return;
            }

            if (file.size > 15 * 1024 * 1024) {
                alert('Die Datei ist zu groß (maximal 15 MB).');
                this.value = '';
                return;
            }

            selectedFileMime = file.type || (file.name.toLowerCase().endsWith('.pdf') ? 'application/pdf' : 'image/jpeg');
            if (importSourceInput) {
                importSourceInput.value = selectedFileMime === 'application/pdf' ? 'pdf' : 'photo';
            }

            const reader = new FileReader();
            reader.onload = function (ev) {
                const res = String(ev.target.result || '');
                const commaIdx = res.indexOf(',');
                selectedFileBase64 = commaIdx >= 0 ? res.substring(commaIdx + 1) : res;

                if (importFilePreview) {
                    const sizeKb = Math.round(file.size / 1024);
                    importFilePreview.textContent = '📎 Bereit: ' + file.name + ' (' + sizeKb + ' KB)';
                    importFilePreview.classList.remove('hidden');
                }
            };
            reader.readAsDataURL(file);
        });
    }

    if (importForm) {
        importForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const srcType = importSourceInput ? importSourceInput.value : 'photo';
            const submitBtn = document.getElementById('btn-submit-import');
            const statusBox = document.getElementById('import-status-box');

            let content = '';
            let mimeType = null;
            let base64Data = null;

            if (srcType === 'photo' || srcType === 'pdf') {
                if (!selectedFileBase64 || !selectedFileMime) {
                    alert('Bitte wähle zuerst ein Foto oder eine PDF-Datei aus.');
                    return;
                }
                mimeType = selectedFileMime;
                base64Data = selectedFileBase64;
                const hintEl = document.getElementById('import-photo-hint');
                content = hintEl ? hintEl.value.trim() : '';
            } else if (srcType === 'url') {
                const urlEl = document.getElementById('import-url-input');
                content = urlEl ? urlEl.value.trim() : '';
                if (!content) {
                    alert('Bitte gib eine Rezept-URL ein.');
                    return;
                }
            } else {
                const textEl = document.getElementById('import-text-input');
                content = textEl ? textEl.value.trim() : '';
                if (!content) {
                    alert('Bitte füge einen Rezepttext ein.');
                    return;
                }
            }

            if (submitBtn) submitBtn.disabled = true;
            if (statusBox) statusBox.classList.remove('hidden');

            try {
                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'import_recipe',
                    source_type: srcType,
                    content: content,
                    mime_type: mimeType,
                    base64_data: base64Data
                });

                if (res && res.success && res.recipe_id) {
                    window.location.href = 'view.php?id=' + encodeURIComponent(res.recipe_id);
                } else {
                    alert((res && res.message) ? res.message : 'Fehler beim Importieren des Rezepts.');
                }
            } catch (err) {
                alert('Netzwerkfehler beim Rezept-Import.');
            } finally {
                if (submitBtn) submitBtn.disabled = false;
                if (statusBox) statusBox.classList.add('hidden');
            }
        });
    }

    // Filter-Auto-Submit auf der Übersichtsseite
    const filterCatSelect = document.getElementById('recipe-filter-category');
    if (filterCatSelect && filterCatSelect.form) {
        filterCatSelect.addEventListener('change', function () {
            this.form.submit();
        });
    }

    // Küchenprofil & Haushalts-Präferenzen Modal (index.php)
    const profileModal = document.getElementById('modal-kitchen-profile');
    const profileForm = document.getElementById('form-kitchen-profile');

    function openProfileModal() {
        if (!profileModal) return;
        profileModal.classList.remove('hidden');
    }

    function closeProfileModal() {
        if (!profileModal) return;
        profileModal.classList.add('hidden');
    }

    if (profileForm) {
        profileForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const submitBtn = document.getElementById('btn-submit-profile');
            if (submitBtn) submitBtn.disabled = true;

            try {
                const stoveTypeEl = document.getElementById('prof-stove-type');
                const stoveLevelsEl = document.getElementById('prof-stove-levels');
                const ovenModeEl = document.getElementById('prof-oven-mode');
                const adultsEl = document.getElementById('prof-default-adults');
                const kidsEl = document.getElementById('prof-default-kids');
                const kidPctEl = document.getElementById('prof-kid-pct');
                const shallotsEl = document.getElementById('prof-prefer-shallots');
                const prefsEl = document.getElementById('prof-household-prefs');

                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'save_kitchen_profile',
                    stove_type: stoveTypeEl ? stoveTypeEl.value : 'Ceranfeld',
                    stove_levels: stoveLevelsEl ? parseInt(stoveLevelsEl.value || '12', 10) : 12,
                    oven_default_mode: ovenModeEl ? ovenModeEl.value : 'Umluft',
                    recipe_default_adults: adultsEl ? parseInt(adultsEl.value || '2', 10) : 2,
                    recipe_default_kids: kidsEl ? parseInt(kidsEl.value || '2', 10) : 2,
                    recipe_kid_portion_pct: kidPctEl ? parseInt(kidPctEl.value || '80', 10) : 80,
                    recipe_prefer_shallots: shallotsEl && shallotsEl.checked ? 1 : 0,
                    recipe_household_preferences: prefsEl ? prefsEl.value.trim() : ''
                });

                if (res && res.success) {
                    window.location.reload();
                } else {
                    alert((res && res.message) ? res.message : 'Fehler beim Speichern des Küchenprofils.');
                }
            } catch (err) {
                alert('Netzwerkfehler beim Speichern des Küchenprofils.');
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // =========================================================================
    // 2. DETAILANSICHT: SKALIERER, TIMING, EINKAUFSLISTE & VARIANTEN (view.php)
    // =========================================================================
    const detailRoot = document.getElementById('recipe-detail-root');
    const currentRecipeId = detailRoot ? parseInt(detailRoot.getAttribute('data-recipe-id') || '0', 10) : 0;
    const currentVariantId = detailRoot ? parseInt(detailRoot.getAttribute('data-variant-id') || '0', 10) : 0;
    const currentRecipeTitle = detailRoot ? (detailRoot.getAttribute('data-recipe-title') || '') : '';

    function reloadWithScalingParams(includeServeTime) {
        if (!currentRecipeId) return;
        const adultsEl = document.getElementById('input-adults');
        const kidsEl = document.getElementById('input-kids');
        const serveEl = document.getElementById('input-serve-time');

        let adults = adultsEl ? parseInt(adultsEl.value || '2', 10) : 2;
        let kids = kidsEl ? parseInt(kidsEl.value || '0', 10) : 0;
        if (isNaN(adults) || adults < 0) adults = 0;
        if (isNaN(kids) || kids < 0) kids = 0;
        if (adults === 0 && kids === 0) adults = 1;

        const params = new URLSearchParams();
        params.set('id', String(currentRecipeId));
        if (currentVariantId > 0) {
            params.set('variant_id', String(currentVariantId));
        }
        params.set('adults', String(adults));
        params.set('kids', String(kids));

        if (includeServeTime && serveEl && serveEl.value) {
            params.set('serve_time', serveEl.value);
        }

        window.location.href = 'view.php?' + params.toString();
    }

    // Zutatentausch ("Alternative suchen") Modal-Status
    const altModal = document.getElementById('modal-recipe-alternatives');
    const altTargetLabel = document.getElementById('alt-target-ingredient-label');
    const altResultsContainer = document.getElementById('alt-results-container');
    const altLoadingBox = document.getElementById('alt-loading-box');
    const altReasonInput = document.getElementById('input-alt-reason');
    let activeAltIngredient = '';
    let lastFetchedAlternatives = [];

    async function fetchIngredientAlternatives() {
        if (!currentRecipeId || !activeAltIngredient) return;
        const btn = document.getElementById('btn-fetch-ai-alternatives');
        const reason = altReasonInput ? altReasonInput.value.trim() : '';

        if (btn) btn.disabled = true;
        if (altLoadingBox) altLoadingBox.classList.remove('hidden');
        if (altResultsContainer) altResultsContainer.innerHTML = '';

        try {
            const res = await window.KaiHttp.postJson('api.php', {
                action: 'suggest_alternatives',
                recipe_id: currentRecipeId,
                variant_id: currentVariantId,
                ingredient_name: activeAltIngredient,
                reason: reason
            });

            if (res && res.success && Array.isArray(res.alternatives)) {
                lastFetchedAlternatives = res.alternatives;
                renderAlternativesList(res.alternatives);
            } else {
                if (altResultsContainer) {
                    altResultsContainer.innerHTML = '<p class="text-muted">Keine Alternativen gefunden.</p>';
                }
            }
        } catch (err) {
            if (altResultsContainer) {
                altResultsContainer.innerHTML = '<p class="text-muted">Fehler bei der KI-Anfrage.</p>';
            }
        } finally {
            if (btn) btn.disabled = false;
            if (altLoadingBox) altLoadingBox.classList.add('hidden');
        }
    }

    function renderAlternativesList(alternatives) {
        if (!altResultsContainer) return;
        if (!alternatives.length) {
            altResultsContainer.innerHTML = '<p class="text-muted">Keine Vorschläge erhalten.</p>';
            return;
        }

        let html = '';
        alternatives.forEach(function (alt, idx) {
            html += '<div class="recipe-alt-card">' +
                '<div class="recipe-alt-card-header">' +
                    '<strong>🔄 ' + escape(alt.alternative_name) + ' (' + escape(alt.quantity) + ' ' + escape(alt.unit) + ')</strong>' +
                    '<button type="button" class="btn btn-sm btn-primary js-apply-alt-variant" data-alt-index="' + idx + '">' +
                        '💾 Als Rezeptabwandlung speichern' +
                    '</button>' +
                '</div>' +
                '<p class="recipe-alt-taste">' + escape(alt.taste_note) + '</p>' +
                (alt.cooking_adjustment ? '<div class="recipe-alt-adjust">🔥 <strong>Anpassung beim Kochen:</strong> ' + escape(alt.cooking_adjustment) + '</div>' : '') +
            '</div>';
        });

        altResultsContainer.innerHTML = html;
    }

    // =========================================================================
    // 3. INTERAKTIVER KOCH-MODUS: WAKE LOCK & MULTI-TIMER (cook.php)
    // =========================================================================
    const cookRoot = document.getElementById('recipe-cook-root');
    const totalCookSteps = cookRoot ? parseInt(cookRoot.getAttribute('data-total-steps') || '0', 10) : 0;
    let currentFocusStepIdx = 0;
    let wakeLockSentinel = null;

    async function requestKitchenWakeLock() {
        if (!cookRoot || !('wakeLock' in navigator)) return;
        const badge = document.getElementById('wakelock-status-badge');
        try {
            wakeLockSentinel = await navigator.wakeLock.request('screen');
            if (badge) {
                badge.textContent = '💡 Display-Sperre deaktiviert (Aktiv)';
                badge.classList.add('is-active');
            }
            wakeLockSentinel.addEventListener('release', function () {
                if (badge) badge.classList.remove('is-active');
            });
        } catch (e) {
            if (badge) {
                badge.textContent = '💡 Display aktiv halten';
            }
        }
    }

    if (cookRoot) {
        requestKitchenWakeLock();
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                requestKitchenWakeLock();
            }
        });
    }

    function showFocusStep(stepIdx) {
        if (stepIdx < 0 || stepIdx >= totalCookSteps) return;
        currentFocusStepIdx = stepIdx;

        document.querySelectorAll('.recipe-focus-card').forEach(function (card) {
            const cIdx = parseInt(card.getAttribute('data-step-index') || '-1', 10);
            if (cIdx === stepIdx) {
                card.classList.remove('hidden');
            } else {
                card.classList.add('hidden');
            }
        });

        const counterLabel = document.getElementById('cook-step-counter-label');
        if (counterLabel) {
            counterLabel.textContent = 'Schritt ' + (stepIdx + 1) + ' von ' + totalCookSteps;
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Parallele Küchen-Timer Verwaltung
    const timersDock = document.getElementById('cook-timers-dock');
    const timersList = document.getElementById('cook-timers-list');
    let activeTimers = [];
    let timerSeq = 1;

    function playTimerAlarmSound() {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();
            const notes = [523.25, 659.25, 783.99, 1046.5];
            notes.forEach(function (freq, i) {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.25, ctx.currentTime + i * 0.18);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.18 + 0.35);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(ctx.currentTime + i * 0.18);
                osc.stop(ctx.currentTime + i * 0.18 + 0.36);
            });
        } catch (e) {
            // Audio optional
        }
    }

    function formatSeconds(sec) {
        const s = Math.max(0, Math.floor(sec));
        const m = Math.floor(s / 60);
        const rem = s % 60;
        return String(m).padStart(2, '0') + ':' + String(rem).padStart(2, '0');
    }

    function startKitchenTimer(label, durationMinutes) {
        const totalSec = Math.max(10, Math.round(durationMinutes * 60));
        const timerObj = {
            id: timerSeq++,
            label: label || 'Küchen-Timer',
            totalSeconds: totalSec,
            remainingSeconds: totalSec,
            paused: false,
            finished: false
        };
        activeTimers.push(timerObj);
        if (timersDock) timersDock.classList.remove('hidden');
        renderTimersDock();
    }

    function renderTimersDock() {
        if (!timersList) return;
        if (activeTimers.length === 0) {
            timersList.innerHTML = '';
            if (timersDock) timersDock.classList.add('hidden');
            return;
        }

        let html = '';
        activeTimers.forEach(function (t) {
            const pct = t.totalSeconds > 0 ? Math.min(100, Math.round(((t.totalSeconds - t.remainingSeconds) / t.totalSeconds) * 100)) : 100;
            const statusClass = t.finished ? 'is-finished' : (t.paused ? 'is-paused' : 'is-running');
            html += '<div class="recipe-timer-widget ' + statusClass + '" data-timer-id="' + t.id + '">' +
                '<div class="recipe-timer-info">' +
                    '<span class="recipe-timer-label">' + escape(t.label) + '</span>' +
                    '<strong class="recipe-timer-clock">' + (t.finished ? '🔔 FERTIG!' : formatSeconds(t.remainingSeconds)) + '</strong>' +
                '</div>' +
                '<div class="recipe-timer-bar-bg"><div class="recipe-timer-bar-fill" style="width:' + pct + '%"></div></div>' +
                '<div class="recipe-timer-controls">' +
                    (!t.finished ? '<button type="button" class="btn btn-sm btn-outline js-timer-toggle" data-timer-id="' + t.id + '" data-read-interactive="1">' + (t.paused ? '▶️ Weiter' : '⏸️ Pause') + '</button>' : '') +
                    '<button type="button" class="btn btn-sm btn-outline js-timer-remove" data-timer-id="' + t.id + '" data-read-interactive="1">&times; Entfernen</button>' +
                '</div>' +
            '</div>';
        });
        timersList.innerHTML = html;
    }

    if (cookRoot) {
        setInterval(function () {
            let changed = false;
            activeTimers.forEach(function (t) {
                if (!t.paused && !t.finished && t.remainingSeconds > 0) {
                    t.remainingSeconds--;
                    changed = true;
                    if (t.remainingSeconds <= 0) {
                        t.remainingSeconds = 0;
                        t.finished = true;
                        playTimerAlarmSound();
                    }
                }
            });
            if (changed) {
                renderTimersDock();
            }
        }, 1000);
    }

    // =========================================================================
    // 4. GLOBALE EVENT-DELEGATION
    // =========================================================================
    document.addEventListener('click', async function (e) {
        // Import-Modal öffnen / schließen
        if (e.target.closest('#btn-open-import-modal') || e.target.closest('#btn-empty-import')) {
            openImportModal();
            return;
        }
        if (e.target.closest('.js-close-import-modal')) {
            closeImportModal();
            return;
        }

        // Küchenprofil-Modal öffnen / schließen
        if (e.target.closest('#btn-open-profile-modal') || e.target.closest('.js-open-profile-modal')) {
            openProfileModal();
            return;
        }
        if (e.target.closest('.js-close-profile-modal')) {
            closeProfileModal();
            return;
        }

        // Import-Quelltyp-Tabs umschalten
        const importTabBtn = e.target.closest('.js-import-tab');
        if (importTabBtn) {
            const type = importTabBtn.getAttribute('data-import-type') || 'photo';
            document.querySelectorAll('.js-import-tab').forEach(function (b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline');
            });
            importTabBtn.classList.remove('btn-outline');
            importTabBtn.classList.add('btn-primary');

            if (importSourceInput) importSourceInput.value = type;
            ['photo', 'url', 'text'].forEach(function (paneKey) {
                const pane = document.getElementById('import-pane-' + paneKey);
                if (pane) {
                    if (paneKey === type) pane.classList.remove('hidden');
                    else pane.classList.add('hidden');
                }
            });
            return;
        }

        // Favoriten-Stern umschalten
        const favBtn = e.target.closest('.js-toggle-fav');
        if (favBtn) {
            const rid = parseInt(favBtn.getAttribute('data-recipe-id') || '0', 10);
            if (!rid) return;
            const res = await window.KaiHttp.postJson('api.php', {
                action: 'toggle_favorite',
                recipe_id: rid
            });
            if (res && res.success) {
                favBtn.classList.toggle('is-fav');
                favBtn.textContent = favBtn.classList.contains('is-fav') ? '⭐' : '☆';
            }
            return;
        }

        // Portionen-Stepper (+ / -)
        const stepBtn = e.target.closest('.js-portion-step');
        if (stepBtn) {
            const target = stepBtn.getAttribute('data-target');
            const delta = parseInt(stepBtn.getAttribute('data-delta') || '0', 10);
            const input = document.getElementById('input-' + target);
            if (input) {
                const nextVal = Math.max(0, (parseInt(input.value || '0', 10) || 0) + delta);
                input.value = String(nextVal);
                const hasServeUrl = new URLSearchParams(window.location.search).has('serve_time');
                reloadWithScalingParams(hasServeUrl);
            }
            return;
        }

        // Multi-Topf Essenszeit anwenden / zurücksetzen
        if (e.target.closest('#btn-apply-serve-time')) {
            reloadWithScalingParams(true);
            return;
        }
        if (e.target.closest('#btn-clear-serve-time')) {
            reloadWithScalingParams(false);
            return;
        }

        // Einkaufslisten-Modal öffnen / schließen / absenden
        const shopModal = document.getElementById('modal-recipe-shopping');
        if (e.target.closest('#btn-open-shopping-modal') && shopModal) {
            shopModal.classList.remove('hidden');
            return;
        }
        if (e.target.closest('.js-close-shopping-modal') && shopModal) {
            shopModal.classList.add('hidden');
            return;
        }
        if (e.target.closest('#btn-confirm-add-shopping')) {
            const confirmBtn = document.getElementById('btn-confirm-add-shopping');
            const checkedBoxes = document.querySelectorAll('.js-shop-item-cb:checked');
            if (!checkedBoxes.length) {
                alert('Bitte wähle mindestens eine Zutat aus.');
                return;
            }
            const items = [];
            checkedBoxes.forEach(function (cb) {
                items.push({
                    name: cb.getAttribute('data-name') || '',
                    quantity: parseFloat(cb.getAttribute('data-quantity') || '1') || 1,
                    unit: cb.getAttribute('data-unit') || 'Stück',
                    market: cb.getAttribute('data-market') || 'Rewe',
                    category: cb.getAttribute('data-category') || 'Sonstiges'
                });
            });

            if (confirmBtn) confirmBtn.disabled = true;
            try {
                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'add_to_shopping_list',
                    recipe_title: currentRecipeTitle,
                    items: items
                });
                if (res && res.success) {
                    if (shopModal) shopModal.classList.add('hidden');
                    alert(res.message || 'Zutaten wurden zur Einkaufsliste hinzugefügt!');
                } else {
                    alert((res && res.message) ? res.message : 'Fehler beim Übertragen.');
                }
            } finally {
                if (confirmBtn) confirmBtn.disabled = false;
            }
            return;
        }

        // Zutatentausch ("Alternative suchen") Modal öffnen / schließen
        const openAltBtn = e.target.closest('.js-open-alt-modal');
        if (openAltBtn && altModal) {
            activeAltIngredient = openAltBtn.getAttribute('data-ingredient') || '';
            if (altTargetLabel) altTargetLabel.textContent = '„' + activeAltIngredient + '“';
            if (altReasonInput) altReasonInput.value = '';
            if (altResultsContainer) altResultsContainer.innerHTML = '';
            altModal.classList.remove('hidden');
            fetchIngredientAlternatives();
            return;
        }
        if (e.target.closest('.js-close-alt-modal') && altModal) {
            altModal.classList.add('hidden');
            return;
        }
        if (e.target.closest('#btn-fetch-ai-alternatives')) {
            fetchIngredientAlternatives();
            return;
        }

        // Gewählte Zutat-Alternative als neue Rezeptabwandlung speichern
        const applyAltBtn = e.target.closest('.js-apply-alt-variant');
        if (applyAltBtn) {
            const idx = parseInt(applyAltBtn.getAttribute('data-alt-index') || '-1', 10);
            const chosen = lastFetchedAlternatives[idx];
            if (!chosen || !currentRecipeId) return;

            applyAltBtn.disabled = true;
            try {
                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'create_substituted_variant',
                    recipe_id: currentRecipeId,
                    variant_id: currentVariantId,
                    old_ingredient: activeAltIngredient,
                    replacement: chosen
                });
                if (res && res.success && res.variant_id) {
                    window.location.href = 'view.php?id=' + encodeURIComponent(currentRecipeId) + '&variant_id=' + encodeURIComponent(res.variant_id);
                } else {
                    alert((res && res.message) ? res.message : 'Fehler beim Speichern der Rezeptabwandlung.');
                    applyAltBtn.disabled = false;
                }
            } catch (err) {
                alert('Netzwerkfehler beim Speichern der Variante.');
                applyAltBtn.disabled = false;
            }
            return;
        }

        // Rezeptabwandlung löschen
        const delVarBtn = e.target.closest('#btn-delete-variant');
        if (delVarBtn) {
            const vid = parseInt(delVarBtn.getAttribute('data-variant-id') || '0', 10);
            if (!vid || !confirm('Möchtest du diese Rezeptabwandlung wirklich löschen?')) return;
            const res = await window.KaiHttp.postJson('api.php', {
                action: 'delete_variant',
                variant_id: vid
            });
            if (res && res.success) {
                window.location.href = 'view.php?id=' + encodeURIComponent(currentRecipeId);
            } else {
                alert((res && res.message) ? res.message : 'Variante konnte nicht gelöscht werden.');
            }
            return;
        }

        // Komplettes Rezept löschen (in Übersicht und Detailansicht)
        const delRecipeBtn = e.target.closest('#btn-delete-recipe') || e.target.closest('.js-delete-recipe');
        if (delRecipeBtn) {
            const rid = parseInt(delRecipeBtn.getAttribute('data-recipe-id') || '0', 10);
            if (!rid || !confirm('Möchtest du dieses Rezept samt aller Varianten unwiderruflich löschen?')) return;
            delRecipeBtn.disabled = true;
            try {
                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'delete_recipe',
                    recipe_id: rid
                });
                if (res && res.success) {
                    window.location.href = 'index.php';
                } else {
                    alert((res && res.message) ? res.message : 'Fehler beim Löschen des Rezepts.');
                    delRecipeBtn.disabled = false;
                }
            } catch (err) {
                alert('Netzwerkfehler beim Löschen des Rezepts.');
                delRecipeBtn.disabled = false;
            }
            return;
        }

        // Bestehendes Rezept aus der Original-Quelle neu importieren
        const reimportBtn = e.target.closest('.js-reimport-recipe');
        if (reimportBtn) {
            const rid = parseInt(reimportBtn.getAttribute('data-recipe-id') || '0', 10);
            if (!rid || !confirm('Möchtest du das Rezept jetzt frisch aus der hinterlegten Original-Quelle (z. B. Google Drive PDF / Webseite) neu importieren und das aktuelle Rezept ersetzen?')) return;

            const origText = reimportBtn.textContent;
            reimportBtn.disabled = true;
            reimportBtn.textContent = '⏳ Importiere neu...';

            try {
                const res = await window.KaiHttp.postJson('api.php', {
                    action: 'reimport_recipe',
                    recipe_id: rid
                });
                if (res && res.success && res.recipe_id) {
                    window.location.href = 'view.php?id=' + encodeURIComponent(res.recipe_id);
                } else {
                    alert((res && res.message) ? res.message : 'Fehler beim Neu-Importieren des Rezepts.');
                    reimportBtn.disabled = false;
                    reimportBtn.textContent = origText;
                }
            } catch (err) {
                alert('Netzwerkfehler beim Neu-Importieren.');
                reimportBtn.disabled = false;
                reimportBtn.textContent = origText;
            }
            return;
        }

        // --- KOCH-MODUS INTERAKTIONEN ---
        const cookViewBtn = e.target.closest('.js-cook-view-btn');
        if (cookViewBtn) {
            const mode = cookViewBtn.getAttribute('data-cook-view') || 'focus';
            document.querySelectorAll('.js-cook-view-btn').forEach(function (b) {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline');
            });
            cookViewBtn.classList.remove('btn-outline');
            cookViewBtn.classList.add('btn-primary');

            const focusSec = document.getElementById('cook-view-focus');
            const allSec = document.getElementById('cook-view-all');
            if (mode === 'focus') {
                if (focusSec) focusSec.classList.remove('hidden');
                if (allSec) allSec.classList.add('hidden');
            } else {
                if (focusSec) focusSec.classList.add('hidden');
                if (allSec) allSec.classList.remove('hidden');
            }
            return;
        }

        if (e.target.closest('.js-cook-prev-step')) {
            showFocusStep(currentFocusStepIdx - 1);
            return;
        }
        if (e.target.closest('.js-cook-next-step')) {
            // Aktuellen Schritt in der Checkliste als erledigt markieren
            const cb = document.querySelector('.js-step-done-cb[data-step-index="' + currentFocusStepIdx + '"]');
            if (cb) {
                cb.checked = true;
                const row = cb.closest('.js-cook-checklist-row');
                if (row) row.classList.add('is-step-done');
            }
            showFocusStep(currentFocusStepIdx + 1);
            return;
        }

        const jumpBtn = e.target.closest('.js-jump-to-step');
        if (jumpBtn) {
            const targetIdx = parseInt(jumpBtn.getAttribute('data-step-index') || '0', 10);
            const focusBtn = document.querySelector('.js-cook-view-btn[data-cook-view="focus"]');
            if (focusBtn) focusBtn.click();
            showFocusStep(targetIdx);
            return;
        }

        // Timer starten
        const startTimerBtn = e.target.closest('.js-start-step-timer');
        if (startTimerBtn) {
            const mins = parseFloat(startTimerBtn.getAttribute('data-minutes') || '1') || 1;
            const label = startTimerBtn.getAttribute('data-label') || 'Kochschritt';
            startKitchenTimer(label, mins);
            return;
        }

        if (e.target.closest('.js-add-custom-timer')) {
            const minsStr = prompt('Für wie viele Minuten soll der Timer gestellt werden?', '10');
            if (!minsStr) return;
            const mins = parseFloat(minsStr.replace(',', '.'));
            if (isNaN(mins) || mins <= 0) return;
            const lbl = prompt('Bezeichnung für den Timer (z. B. Kartoffeln):', 'Eigener Timer') || 'Eigener Timer';
            startKitchenTimer(lbl, mins);
            return;
        }

        const toggleTimerBtn = e.target.closest('.js-timer-toggle');
        if (toggleTimerBtn) {
            const tid = parseInt(toggleTimerBtn.getAttribute('data-timer-id') || '0', 10);
            activeTimers.forEach(function (t) {
                if (t.id === tid) t.paused = !t.paused;
            });
            renderTimersDock();
            return;
        }

        const removeTimerBtn = e.target.closest('.js-timer-remove');
        if (removeTimerBtn) {
            const tid = parseInt(removeTimerBtn.getAttribute('data-timer-id') || '0', 10);
            activeTimers = activeTimers.filter(function (t) { return t.id !== tid; });
            renderTimersDock();
        }
    });

    document.addEventListener('change', function (e) {
        const stepDoneCb = e.target.closest('.js-step-done-cb');
        if (stepDoneCb) {
            const row = stepDoneCb.closest('.js-cook-checklist-row');
            if (row) {
                row.classList.toggle('is-step-done', stepDoneCb.checked);
            }
        }
    });
});

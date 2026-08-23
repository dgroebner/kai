// Scope-Variablen auf Modulebene
let activePopover = null;
let activeFilterTagId = null;
let activeRuleModal = null;
let liveTestTimeout = null;

document.addEventListener('DOMContentLoaded', () => {
    // 1. Tags sicher aus dem HTML data-Attribut parsen
    const container = document.getElementById('giro-container');
    if (container && container.dataset.tags) {
        try {
            window.AVAILABLE_TAGS = JSON.parse(container.dataset.tags);
        } catch (e) {
            console.error('Fehler beim Parsen der Tags:', e);
            window.AVAILABLE_TAGS = [];
        }
    } else {
        window.AVAILABLE_TAGS = [];
    }

    // 2. Transaktions-Highlight (bei Aufruf mit ?tx=ID)
    const urlParams = new URLSearchParams(window.location.search);
    const highlightTxId = urlParams.get('tx');
    if (highlightTxId) {
        const targetRow = document.querySelector(`tr[data-tx-id="${highlightTxId}"]`);
        if (targetRow) {
            targetRow.scrollIntoView({behavior: 'smooth', block: 'center'});
            targetRow.style.transition = 'background-color 0.5s ease';
            targetRow.style.backgroundColor = 'color-mix(in srgb, var(--accent) 25%, transparent)';
            setTimeout(() => {
                targetRow.style.backgroundColor = '';
            }, 2500);
        }
    }

    // 3. Vertrags-Highlight (bei Aufruf mit Anker wie #contract-ID)
    if (window.location.hash && window.location.hash.startsWith('#contract-')) {
        const targetRow = document.querySelector(window.location.hash);
        if (targetRow) {
            targetRow.scrollIntoView({behavior: 'smooth', block: 'center'});
            targetRow.style.transition = 'background-color 0.5s ease';
            targetRow.style.backgroundColor = 'color-mix(in srgb, var(--accent) 25%, transparent)';
            setTimeout(() => {
                targetRow.style.backgroundColor = '';
            }, 2500);
        }
    }
});

// Globaler Klick-Dispatcher
document.addEventListener('click', (e) => {
    // A: Klick auf Zauberstab / Blitz (Rule Builder Modal)
    const ruleBtn = e.target.closest('.js-open-rule-builder');
    if (ruleBtn) {
        e.preventDefault();
        e.stopPropagation();
        openRuleBuilderModal(ruleBtn);
        return;
    }

    // Klick auf Tag-Badge in der Tabelle (zum Bearbeiten von Name & Farbe)
    const tagBadge = e.target.closest('.tag-badge.clickable-tag');
    if (tagBadge && !e.target.classList.contains('remove-tag-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const tagId = tagBadge.dataset.tagId;
        const tag = (window.AVAILABLE_TAGS || []).find(t => t.id === tagId);
        if (tag) {
            openEditTagModal(tag);
        }
        return;
    }

    // Klick auf Kreditkarten-Kategorie-Badge (Inline Category Editor)
    const ccCategoryBadge = e.target.closest('.category-badge.clickable-badge');
    if (ccCategoryBadge) {
        e.preventDefault();
        e.stopPropagation();
        openCcCategoryDropdown(ccCategoryBadge);
        return;
    }

    // B: Klick auf Tag-Kachel in der Statistik (Filter schalten)
    const filterCard = e.target.closest('.js-filter-tag-card');
    if (filterCard) {
        e.preventDefault();
        e.stopPropagation();
        const tagId = filterCard.dataset.filterTagId;
        toggleTagFilter(tagId);
        return;
    }

    const detailBtn = e.target.closest('.js-open-details');
    if (detailBtn) {
        e.preventDefault();
        e.stopPropagation();
        try {
            const tx = JSON.parse(detailBtn.dataset.tx);
            showTxDetailsModal(tx);
        } catch (err) {
            console.error("Fehler beim Parsen der Transaktionsdaten:", err);
        }
        return;
    }

    // C: Reset-Button für Filter
    const resetBtn = e.target.closest('#btn-reset-tag-filter');
    if (resetBtn) {
        e.preventDefault();
        e.stopPropagation();
        resetTagFilter();
        return;
    }

    // D: Tag aus Transaktionszeile entfernen
    const removeBtn = e.target.closest('.remove-tag-btn');
    if (removeBtn) {
        e.preventDefault();
        e.stopPropagation();
        removeTagFromTx(removeBtn.dataset.txId, removeBtn.dataset.tagId);
        return;
    }

    // E: Popover öffnen
    const openBtn = e.target.closest('.js-open-tag-popover');
    if (openBtn) {
        e.preventDefault();
        e.stopPropagation();
        openTagPopover(openBtn, openBtn.dataset.txId);
        return;
    }

    // F: Klick auf existierendes Tag im Popover
    const tagOption = e.target.closest('.js-tag-option');
    if (tagOption) {
        e.preventDefault();
        e.stopPropagation();
        const txId = tagOption.dataset.txId;
        const tagId = tagOption.dataset.tagId;
        const tag = (window.AVAILABLE_TAGS || []).find(t => t.id === tagId);
        if (tag) {
            addExistingTagToTx(txId, tag);
        }
        return;
    }

    // Klick auf Vertrags-Zuordnungs-Button
    const contractBtn = e.target.closest('.js-open-contract-rule');
    if (contractBtn) {
        e.preventDefault();
        e.stopPropagation();
        openContractRuleBuilderModal(contractBtn);
        return;
    }

    // G: Klick auf "Neu anlegen" im Popover
    const createOption = e.target.closest('.js-tag-create');
    if (createOption && activePopover) {
        e.preventDefault();
        e.stopPropagation();
        const txId = createOption.dataset.txId;
        const name = createOption.dataset.tagName;
        const colorInput = activePopover.querySelector('.js-tag-color');
        const color = colorInput ? colorInput.value : '#3b82f6';
        createNewTagAndAssign(txId, name, color);
        return;
    }

    const editBtn = e.target.closest('.js-edit-contract');
    if (editBtn) {
        e.preventDefault();
        const contractData = JSON.parse(editBtn.dataset.contract || '{}');
        openContractModal(contractData);
        return;
    }

    const addBtn = e.target.closest('#btn-add-contract');
    if (addBtn) {
        e.preventDefault();
        openContractModal(null); // Modus: Neu anlegen
        return;
    }

    if (activePopover && activePopover.contains(e.target)) {
        return;
    }

    if (activePopover) {
        closePopover();
    }

    const paginationLink = e.target.closest('.pagination a');
    if (paginationLink) {
        e.preventDefault();
        fetchAndReplaceContent(paginationLink.href);
    }
});

// ----------------------------------------------------
// Visual Regex Builder Modal (Phase 3.2 - 3.4)
// ----------------------------------------------------

function openRuleBuilderModal(btn) {
    closeRuleModal();

    const txId = btn.dataset.txId;
    const ruleId = btn.dataset.ruleId || null;

    // Quelldaten der Buchung: Buchungstext und Beteiligte (Auftraggeber / Debitor / Kreditor)
    const remittanceInfo = btn.dataset.remittanceInfo || '';
    const payeeSources = [
        {label: 'Auftraggeber', value: btn.dataset.remitter || ''},
        {label: 'Zahlungspflichtiger', value: btn.dataset.debitor || ''},
        {label: 'Empfänger', value: btn.dataset.creditor || ''}
    ].filter(src => src.value.trim() !== '');

    const initialTextPattern = btn.dataset.textPattern || '';
    const initialPayeePattern = btn.dataset.payeePattern || '';

    // Aktuell an der Transaktion befindliche Tags auslesen (Vorselektion)
    const rowGroup = document.querySelector(`.js-tag-group[data-tx-id="${txId}"]`);
    const existingTagBadges = rowGroup ? rowGroup.querySelectorAll('.tag-badge') : [];
    const selectedTagIds = Array.from(existingTagBadges).map(b => parseInt(b.dataset.tagId, 10));

    const overlay = document.createElement('div');
    overlay.className = 'rule-modal-overlay';

    const payeeSourceHtml = payeeSources.length
        ? payeeSources.map(src => `
            <div class="rule-source-row">
                <span class="rule-source-label">${escapeHtml(src.label)}</span>
                <div class="word-segment-wrap js-word-segments" data-source-value="${escapeHtml(src.value)}"></div>
            </div>`).join('')
        : '<p class="rule-empty-hint">Für diese Buchung sind keine Beteiligten hinterlegt.</p>';

    overlay.innerHTML = `
        <div class="rule-modal-card">
            <div class="rule-modal-header">
                <h3>🪄 Rule Builder ${ruleId ? '(Regel bearbeiten)' : '(Neue Regel erstellen)'}</h3>
                <button type="button" class="rule-modal-close js-close-modal">&times;</button>
            </div>
            
            <div class="rule-modal-body">
                <div class="rule-live-row">
                    <span class="rule-group-label">Beide Muster werden UND-verknüpft geprüft.</span>
                    <span class="live-match-pill js-live-match-pill">Prüfe Matches...</span>
                </div>

                <div class="rule-pattern-group js-pattern-group" data-pattern-field="text">
                    <label class="chart-label rule-group-label">Buchungstext (klickbar für Regex):</label>
                    <div class="word-segment-wrap js-word-segments" data-source-value="${escapeHtml(remittanceInfo)}"></div>

                    <div class="helper-btn-group">
                        <button type="button" class="btn-helper js-helper-exact">🔤 Exakter Wortstamm</button>
                        <button type="button" class="btn-helper js-helper-start">^ Startet mit</button>
                        <button type="button" class="btn-helper js-helper-num">🔢 Zahlen durch \\d+ ersetzen</button>
                        <button type="button" class="btn-helper js-helper-wild">.* Wildcard</button>
                        <button type="button" class="btn-helper js-helper-clear">✖ Leeren</button>
                    </div>

                    <label class="chart-label rule-group-label">Muster für Buchungstext (Regex):</label>
                    <input type="text" class="tag-search-input rule-pattern-input js-rule-pattern"
                           value="${escapeHtml(initialTextPattern)}"
                           placeholder="z. B. Miete oder ^DAUERAUFTRAG.*">
                </div>

                <div class="rule-pattern-group js-pattern-group" data-pattern-field="payee">
                    <label class="chart-label rule-group-label">Beteiligte (klickbar für Regex):</label>
                    ${payeeSourceHtml}

                    <div class="helper-btn-group">
                        <button type="button" class="btn-helper js-helper-exact">🔤 Exakter Wortstamm</button>
                        <button type="button" class="btn-helper js-helper-start">^ Startet mit</button>
                        <button type="button" class="btn-helper js-helper-num">🔢 Zahlen durch \\d+ ersetzen</button>
                        <button type="button" class="btn-helper js-helper-wild">.* Wildcard</button>
                        <button type="button" class="btn-helper js-helper-clear">✖ Leeren</button>
                    </div>

                    <label class="chart-label rule-group-label">Muster für Auftraggeber / Empfänger (Regex):</label>
                    <input type="text" class="tag-search-input rule-pattern-input js-rule-pattern"
                           value="${escapeHtml(initialPayeePattern)}"
                           placeholder="z. B. REWE oder ^AMAZON.*">
                    <p class="rule-empty-hint">Wird gegen Auftraggeber, Zahlungspflichtigen und Empfänger geprüft — ein Treffer genügt.</p>
                </div>

                <div>
                    <label class="chart-label rule-group-label">Zuweisende Tags:</label>
                    <div class="tag-pill-group js-modal-tags-wrap"></div>
                </div>
            </div>

            <div class="rule-modal-footer">
                <div>
                    ${ruleId ? `<button type="button" class="btn btn-outline btn-danger-outline js-delete-rule">🗑 Regel löschen</button>` : ''}
                </div>
                <div class="rule-modal-actions">
                    <button type="button" class="btn btn-outline js-close-modal">Abbrechen</button>
                    <button type="button" class="btn js-save-rule">Speichern & Anwenden</button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);
    activeRuleModal = overlay;

    // Beide Muster-Gruppen (Buchungstext & Beteiligte) identisch verdrahten
    overlay.querySelectorAll('.js-pattern-group').forEach(group => setupPatternGroup(group, overlay));

    // Modal-Tag-Picker rendern
    renderModalTagPicker(overlay.querySelector('.js-modal-tags-wrap'), selectedTagIds);

    // Initialen Live-Check ausführen
    triggerLiveMatchCheck(overlay);

    // Event Listener für Buttons im Footer & Close
    overlay.querySelectorAll('.js-close-modal').forEach(b => b.addEventListener('click', closeRuleModal));

    overlay.querySelector('.js-save-rule').addEventListener('click', async () => {
        const {textPattern, payeePattern} = readRulePatterns(overlay);
        const currentTagIds = Array.from(overlay.querySelectorAll('.js-modal-tag-chip.active')).map(c => parseInt(c.dataset.tagId, 10));

        if (!textPattern && !payeePattern) {
            alert('Bitte mindestens ein Muster (Buchungstext oder Beteiligte) angeben.');
            return;
        }

        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'save_rule',
                rule_id: ruleId ? parseInt(ruleId, 10) : null,
                tx_id: parseInt(txId, 10),
                text_pattern: textPattern,
                payee_pattern: payeePattern,
                tag_ids: currentTagIds
            });

            if (data && data.success) {
                closeRuleModal();
                window.location.reload(); // Nach Regelspeicherung Tabelle neu aufbauen
            }
        } catch (err) {
            console.error('Fehler beim Speichern der Regel:', err);
        }
    });

    if (ruleId) {
        overlay.querySelector('.js-delete-rule').addEventListener('click', async () => {
            if (!confirm('Regel wirklich löschen?')) return;
            try {
                const data = await KaiHttp.postJson('api.php', {
                    action: 'delete_rule',
                    rule_id: parseInt(ruleId, 10)
                });
                if (data && data.success) {
                    closeRuleModal();
                    window.location.reload();
                }
            } catch (err) {
                console.error('Fehler beim Löschen der Regel:', err);
            }
        });
    }
}

/**
 * Verdrahtet eine Muster-Gruppe: Wort-Chips, Helper-Buttons und Live-Prüfung.
 */
function setupPatternGroup(group, overlay) {
    const patternInput = group.querySelector('.js-rule-pattern');
    if (!patternInput) return;

    // Wort-Chips aus allen Quellfeldern der Gruppe aufbauen
    group.querySelectorAll('.js-word-segments').forEach(wordsContainer => {
        const source = wordsContainer.dataset.sourceValue || '';
        const words = source.split(/\s+/).filter(w => w.trim().length > 0);

        if (words.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'rule-empty-hint';
            empty.textContent = 'Keine Daten vorhanden';
            wordsContainer.appendChild(empty);
            return;
        }

        words.forEach(word => {
            const chip = document.createElement('span');
            chip.className = 'word-chip';
            chip.textContent = word;

            chip.addEventListener('click', () => {
                chip.classList.toggle('selected');

                // Auswahl bleibt auf ein Quellfeld begrenzt, damit das Muster gegen ein Feld matcht
                group.querySelectorAll('.js-word-segments').forEach(other => {
                    if (other !== wordsContainer) {
                        other.querySelectorAll('.word-chip.selected').forEach(c => c.classList.remove('selected'));
                    }
                });

                // Alle in diesem Quellfeld ausgewählten Wort-Chips zu einem Muster verbinden
                const selectedChips = Array.from(wordsContainer.querySelectorAll('.word-chip.selected'));
                patternInput.value = selectedChips
                    .map(c => escapeRegex(c.textContent.trim()))
                    .join('.*');

                triggerLiveMatchCheck(overlay);
            });

            wordsContainer.appendChild(chip);
        });
    });

    // Helper Buttons wirken immer auf das Muster der eigenen Gruppe
    group.querySelector('.js-helper-exact').addEventListener('click', () => {
        if (patternInput.value) {
            patternInput.value = `\\b${patternInput.value.replace(/^\\b|\\b$/g, '')}\\b`;
            triggerLiveMatchCheck(overlay);
        }
    });

    group.querySelector('.js-helper-start').addEventListener('click', () => {
        if (patternInput.value && !patternInput.value.startsWith('^')) {
            patternInput.value = `^${patternInput.value}`;
            triggerLiveMatchCheck(overlay);
        }
    });

    group.querySelector('.js-helper-num').addEventListener('click', () => {
        patternInput.value = patternInput.value.replace(/\d+/g, '\\d+');
        triggerLiveMatchCheck(overlay);
    });

    group.querySelector('.js-helper-wild').addEventListener('click', () => {
        patternInput.value = patternInput.value + '.*';
        triggerLiveMatchCheck(overlay);
    });

    group.querySelector('.js-helper-clear').addEventListener('click', () => {
        patternInput.value = '';
        group.querySelectorAll('.word-chip.selected').forEach(c => c.classList.remove('selected'));
        triggerLiveMatchCheck(overlay);
    });

    patternInput.addEventListener('input', () => triggerLiveMatchCheck(overlay));
}

/**
 * Liest beide Muster aus dem Dialog aus.
 */
function readRulePatterns(overlay) {
    const textGroup = overlay.querySelector('.js-pattern-group[data-pattern-field="text"] .js-rule-pattern');
    const payeeGroup = overlay.querySelector('.js-pattern-group[data-pattern-field="payee"] .js-rule-pattern');

    return {
        textPattern: textGroup ? textGroup.value.trim() : '',
        payeePattern: payeeGroup ? payeeGroup.value.trim() : ''
    };
}


function closeRuleModal() {
    if (activeRuleModal) {
        activeRuleModal.remove();
        activeRuleModal = null;
    }
}

function renderModalTagPicker(wrapEl, selectedIds) {
    wrapEl.innerHTML = '';

    (window.AVAILABLE_TAGS || []).forEach(tag => {
        const isSelected = selectedIds.includes(parseInt(tag.id, 10));
        const chip = document.createElement('span');

        // Bei Selektion .active anhängen, sonst .inactive
        chip.className = `badge clickable-tag js-modal-tag-chip ${isSelected ? 'active' : 'inactive'}`;
        chip.dataset.tagId = tag.id;
        chip.style.cssText = `
            cursor: pointer;
            transition: all 0.15s ease;
            ${isSelected
            ? `color: ${tag.color}; border-color: ${tag.color}; background-color: color-mix(in srgb, ${tag.color} 15%, transparent); box-shadow: 0 0 8px color-mix(in srgb, ${tag.color} 30%, transparent);`
            : 'color: var(--text-muted); border-color: rgba(255, 255, 255, 0.1); background-color: rgba(255, 255, 255, 0.02); opacity: 0.55;'
        }
        `;

        chip.textContent = isSelected ? `✓ ${tag.name}` : tag.name;

        chip.addEventListener('click', () => {
            const tagIdNum = parseInt(tag.id, 10);
            const idx = selectedIds.indexOf(tagIdNum);
            if (idx > -1) {
                selectedIds.splice(idx, 1);
            } else {
                selectedIds.push(tagIdNum);
            }
            renderModalTagPicker(wrapEl, selectedIds);
        });

        wrapEl.appendChild(chip);
    });
}

function triggerLiveMatchCheck(overlay) {
    if (liveTestTimeout) clearTimeout(liveTestTimeout);

    const modal = overlay || activeRuleModal;
    if (!modal) return;

    const pill = modal.querySelector('.js-live-match-pill');
    if (pill) pill.textContent = 'Prüfe...';

    const {textPattern, payeePattern} = readRulePatterns(modal);

    if (!textPattern && !payeePattern) {
        if (pill) pill.textContent = '🎯 Gilt für 0 Buchungen';
        return;
    }

    liveTestTimeout = setTimeout(async () => {
        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'test_rule_pattern',
                text_pattern: textPattern,
                payee_pattern: payeePattern
            });

            if (pill && data && data.success) {
                pill.textContent = `🎯 Gilt für ${data.match_count} Buchung${data.match_count === 1 ? '' : 'en'}`;
            }
        } catch (err) {
            if (pill) pill.textContent = '⚠️ Regex-Fehler';
        }
    }, 300);
}

function escapeRegex(str) {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// ----------------------------------------------------
// Client-Side Filtering & Popover (Bestehender Code)
// ----------------------------------------------------
function toggleTagFilter(tagId) {
    const urlParams = new URLSearchParams(window.location.search);
    const currentTagId = urlParams.get('tag_id');

    if (currentTagId === String(tagId)) {
        urlParams.delete('tag_id');
    } else {
        urlParams.set('tag_id', tagId);
        urlParams.set('page', '1'); // Bei Filterwechsel auf Seite 1 zurück
    }

    fetchAndReplaceContent('index.php?' + urlParams.toString());
}

function resetTagFilter() {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.delete('tag_id');
    urlParams.delete('page');
    fetchAndReplaceContent('index.php?' + urlParams.toString());
}

function applyTableFilter() {
    const rows = document.querySelectorAll('tr[data-tx-id]');
    const cards = document.querySelectorAll('.js-filter-tag-card');
    const resetBtn = document.getElementById('btn-reset-tag-filter');

    rows.forEach(row => {
        if (!activeFilterTagId) {
            row.classList.remove('hidden');
            return;
        }

        const hasTag = row.querySelector(`.tag-badge[data-tag-id="${activeFilterTagId}"]`);
        if (hasTag) {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
        }
    });

    cards.forEach(card => {
        if (activeFilterTagId && card.dataset.filterTagId === activeFilterTagId) {
            card.classList.add('active');
        } else {
            card.classList.remove('active');
        }
    });

    if (resetBtn) {
        if (activeFilterTagId) {
            resetBtn.classList.remove('hidden');
        } else {
            resetBtn.classList.add('hidden');
        }
    }
}

function closePopover() {
    if (activePopover) {
        activePopover.remove();
        activePopover = null;
    }
}

function openTagPopover(anchorBtn, txId) {
    closePopover();

    const popover = document.createElement('div');
    popover.className = 'tag-popover shadow-lg';

    popover.innerHTML = `
        <input type="text" class="tag-search-input js-tag-search" placeholder="Tag suchen oder neu..." autofocus>
        
        <div class="color-picker-row js-color-row hidden" style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem; padding: 0 0.2rem;">
            <span style="font-size:0.75rem; color:var(--text-muted);">Farbe:</span>
            <input type="color" class="js-tag-color" value="#3b82f6" style="border:none; width:28px; height:28px; cursor:pointer; background:transparent;">
        </div>

        <div class="tag-list js-tag-list" style="max-height: 180px; overflow-y: auto;"></div>
    `;

    anchorBtn.parentNode.appendChild(popover);
    activePopover = popover;

    const searchInput = popover.querySelector('.js-tag-search');
    const tagListEl = popover.querySelector('.js-tag-list');
    const colorRow = popover.querySelector('.js-color-row');

    searchInput.focus();

    const renderOptions = (query) => {
        tagListEl.innerHTML = '';
        const lowerQuery = query.toLowerCase();

        const matches = (window.AVAILABLE_TAGS || []).filter(t => t.name.toLowerCase().includes(lowerQuery));

        matches.forEach(tag => {
            const item = document.createElement('div');
            item.className = 'tag-option-item js-tag-option';
            item.dataset.txId = txId;
            item.dataset.tagId = tag.id;
            item.style.cssText = 'padding: 0.35rem 0.5rem; cursor: pointer; border-radius: 4px; display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem;';
            item.innerHTML = `
                <span style="width: 10px; height: 10px; border-radius: 50%; background: ${tag.color}; display: inline-block;"></span>
                <span>${escapeHtml(tag.name)}</span>
            `;
            tagListEl.appendChild(item);
        });

        const exactMatch = (window.AVAILABLE_TAGS || []).some(t => t.name.toLowerCase() === lowerQuery);
        if (query.length > 0 && !exactMatch) {
            colorRow.classList.remove('hidden');

            const createBtn = document.createElement('div');
            createBtn.className = 'tag-option-create js-tag-create';
            createBtn.dataset.txId = txId;
            createBtn.dataset.tagName = query;
            createBtn.style.cssText = 'padding: 0.4rem 0.5rem; cursor: pointer; border-top: 1px dashed var(--bg-surface-hover); margin-top: 0.3rem; color: var(--accent); font-weight: 600; font-size: 0.85rem;';
            createBtn.innerHTML = `➕ "${escapeHtml(query)}" neu anlegen`;

            tagListEl.appendChild(createBtn);
        } else {
            colorRow.classList.add('hidden');
        }
    };

    renderOptions('');

    searchInput.addEventListener('input', (e) => {
        renderOptions(e.target.value.trim());
    });
}

// ----------------------------------------------------
// AJAX-Aktionen für manuelles Tagging
// ----------------------------------------------------

async function addExistingTagToTx(txId, tag) {
    try {
        closePopover();
        const data = await KaiHttp.postJson('api.php', {
            action: 'add_tag_to_tx',
            tx_id: parseInt(txId, 10),
            tag_id: parseInt(tag.id, 10)
        });

        if (data && data.success) {
            appendBadgeToUI(txId, tag);
        }
    } catch (err) {
        console.error('Fehler beim Zuweisen:', err);
    }
}

async function createNewTagAndAssign(txId, name, color) {
    try {
        closePopover();
        const data = await KaiHttp.postJson('api.php', {
            action: 'create_and_assign_tag',
            tx_id: parseInt(txId, 10),
            name: name,
            color: color
        });

        if (data && data.success && data.tag) {
            window.AVAILABLE_TAGS.push(data.tag);
            appendBadgeToUI(txId, data.tag);
        }
    } catch (err) {
        console.error('Fehler beim Erstellen:', err);
    }
}

async function removeTagFromTx(txId, tagId) {
    try {
        const data = await KaiHttp.postJson('api.php', {
            action: 'remove_tag_from_tx',
            tx_id: parseInt(txId, 10),
            tag_id: parseInt(tagId, 10)
        });

        if (data && data.success) {
            removeBadgeFromUI(txId, tagId);
        }
    } catch (err) {
        console.error('Fehler beim Entfernen:', err);
    }
}

function appendBadgeToUI(txId, tag) {
    const group = document.querySelector(`.js-tag-group[data-tx-id="${txId}"]`);
    if (!group) return;

    if (group.querySelector(`[data-tag-id="${tag.id}"]`)) return;

    const openBtn = group.querySelector('.js-open-tag-popover');

    const badge = document.createElement('span');
    badge.className = 'badge tag-badge clickable-tag';
    badge.dataset.tagId = tag.id;

    const tagColor = tag.color || '#3b82f6';
    badge.style.color = tagColor;
    badge.style.borderColor = tagColor;
    badge.style.backgroundColor = 'transparent';

    badge.innerHTML = `
        ${escapeHtml(tag.name)}
        <span class="remove-tag-btn" data-tx-id="${txId}" data-tag-id="${tag.id}">&times;</span>
    `;

    if (openBtn) {
        group.insertBefore(badge, openBtn);
    } else {
        group.appendChild(badge);
    }

    updateTagStatsBar();
    applyTableFilter();
}

function removeBadgeFromUI(txId, tagId) {
    const group = document.querySelector(`.js-tag-group[data-tx-id="${txId}"]`);
    if (group) {
        const badge = group.querySelector(`[data-tag-id="${tagId}"]`);
        if (badge) {
            badge.remove();
        }
    }

    updateTagStatsBar();
    applyTableFilter();
}

function updateTagStatsBar() {
    const expGrid = document.getElementById('exp-tags-grid');
    const incGrid = document.getElementById('inc-tags-grid');
    if (!expGrid && !incGrid) return;

    const expMap = {};
    const incMap = {};

    let expTotalAbs = 0, expMaxAbs = 0;
    let incTotalAbs = 0, incMaxAbs = 0;

    const rows = document.querySelectorAll('tr[data-tx-id]');
    rows.forEach(row => {
        const amount = parseFloat(row.dataset.amount || '0');
        const badges = row.querySelectorAll('.tag-badge');

        badges.forEach(badge => {
            const tagId = badge.dataset.tagId;
            const tagName = badge.textContent.replace('×', '').trim();
            const tagColor = badge.style.color || '#3b82f6';

            const targetMap = amount < 0 ? expMap : incMap;

            if (!targetMap[tagId]) {
                targetMap[tagId] = {
                    id: tagId,
                    name: tagName,
                    color: tagColor,
                    count: 0,
                    total: 0,
                    absTotal: 0
                };
            }

            targetMap[tagId].count += 1;
            targetMap[tagId].total += amount;
            targetMap[tagId].absTotal += Math.abs(amount);
        });
    });

    Object.values(expMap).forEach(s => {
        expTotalAbs += s.absTotal;
        if (s.absTotal > expMaxAbs) expMaxAbs = s.absTotal;
    });

    Object.values(incMap).forEach(s => {
        incTotalAbs += s.absTotal;
        if (s.absTotal > incMaxAbs) incMaxAbs = s.absTotal;
    });

    const renderGroup = (gridEl, barEl, statsMap, totalAbs, maxAbs, isExpense) => {
        if (!gridEl) return;

        const sorted = Object.values(statsMap).sort((a, b) => b.absTotal - a.absTotal);

        if (barEl) {
            barEl.innerHTML = '';
            sorted.forEach(stat => {
                const pct = totalAbs > 0 ? (stat.absTotal / totalAbs) * 100 : 0;
                if (pct <= 0) return;
                const formattedAmt = stat.absTotal.toLocaleString('de-DE', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }) + ' €';
                const seg = document.createElement('div');
                seg.className = 'tag-bar-segment';
                seg.style.width = `${pct.toFixed(2)}%`;
                seg.style.backgroundColor = stat.color;
                seg.title = `${stat.name}: ${pct.toFixed(1)}% (${formattedAmt} | ${stat.count} Buchungen)`;
                barEl.appendChild(seg);
            });
        }

        gridEl.innerHTML = '';
        sorted.forEach(stat => {
            const sign = isExpense ? '-' : '+';
            const formattedAmt = stat.absTotal.toLocaleString('de-DE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' €';
            const fillPct = maxAbs > 0 ? (stat.absTotal / maxAbs) * 100 : 0;
            const isActive = activeFilterTagId === stat.id;

            const card = document.createElement('div');
            card.className = `tag-stat-card js-filter-tag-card ${isActive ? 'active' : ''}`;
            card.dataset.filterTagId = stat.id;
            card.style.setProperty('--tag-color', stat.color);
            card.style.cursor = 'pointer';

            card.innerHTML = `
                <div class="tag-stat-bg-bar" style="width: ${fillPct.toFixed(1)}%;"></div>
                <div class="tag-stat-content">
                    <div class="tag-stat-header">
                        <span class="tag-color-dot" style="background-color: ${stat.color};"></span>
                        <span class="tag-stat-name">${escapeHtml(stat.name)}</span>
                    </div>
                    <div class="tag-stat-metrics">
                        <span class="tag-stat-amount ${isExpense ? 'text-danger' : 'text-success'}">
                            ${sign}${formattedAmt}
                        </span>
                        <span class="tag-stat-count">${stat.count} Buchung${stat.count === 1 ? '' : 'en'}</span>
                    </div>
                </div>
            `;
            gridEl.appendChild(card);
        });
    };

    renderGroup(expGrid, document.getElementById('exp-distribution-bar'), expMap, expTotalAbs, expMaxAbs, true);
    renderGroup(incGrid, document.getElementById('inc-distribution-bar'), incMap, incTotalAbs, incMaxAbs, false);
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function openEditTagModal(tag) {
    // Vorherige Modals/Popups schließen
    closePopover();

    const overlay = document.createElement('div');
    overlay.className = 'rule-modal-overlay';

    overlay.innerHTML = `
        <div class="rule-modal-card" style="max-width: 380px;">
            <div class="rule-modal-header">
                <h3>🏷️ Tag bearbeiten</h3>
                <button type="button" class="rule-modal-close js-close-edit-tag">&times;</button>
            </div>
            
            <div class="rule-modal-body" style="gap: 1rem;">
                <div>
                    <label class="chart-label" style="margin-bottom: 0.3rem;">Name des Tags:</label>
                    <input type="text" class="tag-search-input js-edit-tag-name" value="${escapeHtml(tag.name)}" style="font-weight: 600;">
                </div>

                <div>
                    <label class="chart-label" style="margin-bottom: 0.3rem;">Farbe:</label>
                    <div style="display: flex; align-items: center; gap: 0.8rem;">
                        <input type="color" class="js-edit-tag-color" value="${tag.color || '#3b82f6'}" style="border: none; width: 42px; height: 42px; cursor: pointer; background: transparent; padding: 0;">
                        <span class="js-color-hex-label" style="font-family: monospace; font-size: 0.9rem; color: var(--text-muted);">${tag.color || '#3b82f6'}</span>
                    </div>
                </div>
            </div>

            <div class="rule-modal-footer">
                <button type="button" class="btn btn-outline js-close-edit-tag">Abbrechen</button>
                <button type="button" class="btn js-save-edit-tag">Speichern</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    const nameInput = overlay.querySelector('.js-edit-tag-name');
    const colorInput = overlay.querySelector('.js-edit-tag-color');
    const colorHexLabel = overlay.querySelector('.js-color-hex-label');

    colorInput.addEventListener('input', (e) => {
        colorHexLabel.textContent = e.target.value;
    });

    const closeModal = () => overlay.remove();

    overlay.querySelectorAll('.js-close-edit-tag').forEach(b => b.addEventListener('click', closeModal));

    overlay.querySelector('.js-save-edit-tag').addEventListener('click', async () => {
        const newName = nameInput.value.trim();
        const newColor = colorInput.value;

        if (!newName) {
            alert('Bitte einen Namen eingeben.');
            return;
        }

        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'update_tag',
                tag_id: parseInt(tag.id, 10),
                name: newName,
                color: newColor
            });

            if (data && data.success) {
                // Lokales Tag im Speicher aktualisieren
                tag.name = newName;
                tag.color = newColor;

                closeModal();
                window.location.reload(); // Neuladen, damit Tabelle & Statistik-Kacheln direkt aktualisiert werden
            }
        } catch (err) {
            console.error('Fehler beim Aktualisieren des Tags:', err);
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    // 1. Girokonto Tags laden
    const giroContainer = document.getElementById('giro-container');
    if (giroContainer && giroContainer.dataset.tags) {
        try {
            window.AVAILABLE_TAGS = JSON.parse(giroContainer.dataset.tags);
        } catch (e) {
            console.error('Fehler beim Parsen der Tags:', e);
            window.AVAILABLE_TAGS = [];
        }
    } else {
        window.AVAILABLE_TAGS = [];
    }

    // 2. Kreditkarten-Detailseite (Donut Chart & Legende rendern)
    const ccApp = document.getElementById('bankDetailApp');
    if (ccApp) {
        initCreditCardDetail(ccApp);
    }
});

/**
 * Initialisiert Donut-Chart, Legende und Kategorie-Farben für die Kreditkarten-Detailansicht
 */
function initCreditCardDetail(appEl) {
    const rawTxs = appEl.dataset.transactions ? JSON.parse(appEl.dataset.transactions) : [];
    const totalAmount = parseFloat(appEl.dataset.total || '0');

    if (!rawTxs.length) return;

    // Farbpalette für Kreditkarten-Kategorien
    const colorPalette = [
        '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
        '#ec4899', '#06b6d4', '#84cc16', '#f97316', '#64748b'
    ];

    // Umsätze nach Kategorien aggregieren
    const catMap = {};
    rawTxs.forEach(tx => {
        const catName = tx.category_name || 'Sonstiges';
        const amt = Math.abs(parseFloat(tx.amount || '0'));
        if (!catMap[catName]) {
            catMap[catName] = {name: catName, total: 0, count: 0};
        }
        catMap[catName].total += amt;
        catMap[catName].count += 1;
    });

    const sortedCats = Object.values(catMap).sort((a, b) => b.total - a.total);

    // Farben zuweisen
    sortedCats.forEach((cat, idx) => {
        cat.color = colorPalette[idx % colorPalette.length];
    });

    // A: Kategorie-Badges in der Tabelle einfärben
    document.querySelectorAll('#transactionsTable tbody tr').forEach(row => {
        const catName = row.dataset.categoryName || 'Sonstiges';
        const match = sortedCats.find(c => c.name === catName);
        if (match) {
            const badge = row.querySelector('.category-badge');
            if (badge) {
                badge.style.borderColor = match.color;
                badge.style.color = match.color;
                badge.style.backgroundColor = 'rgba(255, 255, 255, 0.03)';
            }
        }
    });

    // B: Klickbare Legende rendern + Filter-Event
    const legendEl = document.getElementById('categoryLegend');
    const resetBtn = document.getElementById('resetFilterBtn');

    if (legendEl) {
        legendEl.innerHTML = '';
        sortedCats.forEach(cat => {
            const pct = totalAmount > 0 ? ((cat.total / totalAmount) * 100).toFixed(1) : 0;
            const formattedAmt = cat.total.toLocaleString('de-DE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' €';

            const row = document.createElement('div');
            row.className = 'legend-row';
            row.dataset.categoryName = cat.name;
            row.style.cursor = 'pointer';
            row.innerHTML = `
                <span class="legend-dot" style="background-color: ${cat.color};"></span>
                <span class="legend-label">${escapeHtml(cat.name)}</span>
                <span class="legend-percent">${pct}%</span>
                <span class="legend-value">${formattedAmt}</span>
            `;

            // Klick auf Legendenzeile -> Tabelle nach Kategorie filtern
            row.addEventListener('click', () => {
                const isAlreadyActive = row.classList.contains('active');

                // Alle Zeilen in Legende zurücksetzen
                legendEl.querySelectorAll('.legend-row').forEach(r => r.classList.remove('active'));

                if (isAlreadyActive) {
                    filterCcTable(null);
                    if (resetBtn) resetBtn.classList.add('hidden');
                } else {
                    row.classList.add('active');
                    filterCcTable(cat.name);
                    if (resetBtn) resetBtn.classList.remove('hidden');
                }
            });

            legendEl.appendChild(row);
        });
    }

    // Reset-Button Event
    if (resetBtn) {
        resetBtn.onclick = () => {
            if (legendEl) {
                legendEl.querySelectorAll('.legend-row').forEach(r => r.classList.remove('active'));
            }
            filterCcTable(null);
            resetBtn.classList.add('hidden');
        };
    }

    // C: ChartJS Donut-Chart zeichnen (falls Chart.js geladen ist)
    const canvas = document.getElementById('categoryChart');
    if (canvas && typeof Chart !== 'undefined') {
        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: sortedCats.map(c => c.name),
                datasets: [{
                    data: sortedCats.map(c => c.total),
                    backgroundColor: sortedCats.map(c => c.color),
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '75%',
                plugins: {
                    legend: {display: false},
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ` ${ctx.label}: ${ctx.raw.toLocaleString('de-DE', {minimumFractionDigits: 2})} €`;
                            }
                        }
                    }
                }
            }
        });
    }
}

/**
 * Filtert die Kreditkarten-Tabelle nach Kategorie-Namen
 */
function filterCcTable(categoryName) {
    const rows = document.querySelectorAll('#transactionsTable tbody tr');
    rows.forEach(row => {
        if (!categoryName) {
            row.style.display = '';
            return;
        }

        const rowCat = row.dataset.categoryName || 'Sonstiges';
        if (rowCat.toLowerCase() === categoryName.toLowerCase()) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function openCcCategoryDropdown(badgeEl) {
    const cell = badgeEl.closest('.category-cell');
    if (!cell || cell.querySelector('.category-select-custom')) return; // Bereits im Edit-Modus

    const txId = badgeEl.dataset.txId;
    const currentText = badgeEl.querySelector('.badge-text')?.textContent.trim() || '';

    // Kategorien aus dem data-Attribut des Haupt-Containers lesen
    const ccApp = document.getElementById('bankDetailApp');
    const availableCategories = ccApp && ccApp.dataset.categories ? JSON.parse(ccApp.dataset.categories) : [];

    // Select-Element aufbauen
    const select = document.createElement('select');
    select.className = 'category-select-custom';

    availableCategories.forEach(cat => {
        const option = document.createElement('option');
        option.value = cat.id;
        option.textContent = cat.name;
        if (cat.name.toLowerCase() === currentText.toLowerCase()) {
            option.selected = true;
        }
        select.appendChild(option);
    });

    // Badge ausblenden, Select anzeigen
    badgeEl.style.display = 'none';
    cell.appendChild(select);
    select.focus();

    const saveChange = async () => {
        const newCatId = select.value;
        const selectedOptionText = select.options[select.selectedIndex].text;

        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'update_cc_transaction_category',
                tx_id: parseInt(txId, 10),
                category_id: parseInt(newCatId, 10)
            });

            if (data && data.success) {
                // UI ohne Reload aktualisieren
                badgeEl.querySelector('.badge-text').textContent = selectedOptionText;
                const row = badgeEl.closest('tr');
                if (row) {
                    row.dataset.categoryName = selectedOptionText;
                    row.dataset.categoryId = newCatId;
                }

                // Chart & Farbschema neu berechnen
                if (ccApp) {
                    initCreditCardDetail(ccApp);
                }
            }
        } catch (err) {
            console.error('Fehler beim Aktualisieren der Kategorie:', err);
        } finally {
            select.remove();
            badgeEl.style.display = 'inline-flex';
        }
    };

    select.addEventListener('change', saveChange);
    select.addEventListener('blur', () => {
        select.remove();
        badgeEl.style.display = 'inline-flex';
    });
}

async function fetchAndReplaceContent(targetUrl) {
    try {
        // Optional: Transaktions-Container leicht ausgrauen während des Ladens
        const container = document.querySelector('main section.card');
        if (container) container.style.opacity = '0.5';

        const response = await fetch(targetUrl);
        const htmlText = await response.text();

        // HTML parsen
        const parser = new DOMParser();
        const doc = parser.parseFromString(htmlText, 'text/html');

        // Neue Tabelle und Paginierung extrahieren
        // Ins aktuelle DOM übernehmen
        document.querySelector('main').innerHTML = doc.querySelector('main').innerHTML;

        // URL im Browser aktualisieren (ohne Reload)
        window.history.pushState({path: targetUrl}, '', targetUrl);

        // Aktiv-Zustände der Kacheln im DOM aktualisieren
        const urlParams = new URLSearchParams(targetUrl.split('?')[1]);
        const activeTagId = urlParams.get('tag_id');

        document.querySelectorAll('.js-filter-tag-card').forEach(card => {
            if (card.dataset.filterTagId === activeTagId) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        });

        const resetBtn = document.getElementById('btn-reset-tag-filter');
        if (resetBtn) {
            resetBtn.classList.toggle('hidden', !activeTagId);
        }
        if (container) container.style.opacity = '1';

    } catch (err) {
        console.error('AJAX Ladefehler:', err);
        // Fallback auf normalen Reload bei Netzwerkfehlern
        window.location.href = targetUrl;
    }
}

// ==========================================
// Comdirect API Sync (Token-Lifecycle & photoTAN)
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    const btnOpenSync = document.getElementById('btn-open-sync');
    const syncModal = document.getElementById('sync-modal');
    const btnCancelSync = document.getElementById('btn-cancel-sync');
    const btnCancelSyncX = document.getElementById('btn-cancel-sync-x');
    const btnSubmitCredentials = document.getElementById('btn-submit-credentials');
    const btnCloseSync = document.getElementById('btn-close-sync');

    const stepCredentials = document.getElementById('sync-step-credentials');
    const stepPhototan = document.getElementById('sync-step-phototan');
    const stepProgress = document.getElementById('sync-step-progress');
    const accessIdInput = document.getElementById('sync-access-id');
    const pinInput = document.getElementById('sync-pin-input');
    const resultMsg = document.getElementById('sync-result-msg');

    const phototanConfirmChk = document.getElementById('sync-phototan-confirm');
    const btnSubmitPhototan = document.getElementById('btn-submit-phototan');
    const btnCancelPhototanBtn = document.getElementById('btn-cancel-phototan-btn');
    const phototanLockContainer = document.getElementById('phototan-lock-container');
    const resetLockChk = document.getElementById('sync-reset-lock-chk');

    // UI-Elemente für die Aufgaben-Checkliste
    const tasks = {
        auth: document.getElementById('task-auth'),
        balance: document.getElementById('task-balance'),
        tx: document.getElementById('task-tx'),
        rules: document.getElementById('task-rules')
    };

    if (!btnOpenSync || !syncModal) return;

    // --- Hilfsfunktionen ---

    const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    const hideAllSteps = () => {
        if (stepCredentials) stepCredentials.classList.add('hidden');
        if (stepPhototan) stepPhototan.classList.add('hidden');
        if (stepProgress) stepProgress.classList.add('hidden');
    };

    const closeModal = () => {
        syncModal.classList.add('hidden');
        syncModal.style.display = 'none';
    };

    const resetTaskList = () => {
        Object.values(tasks).forEach(el => {
            if (!el) return;
            el.style.color = 'var(--text-muted)';
            const stripped = el.innerText.replace(/^[✅❌⏳]\s*/, '');
            el.innerText = '⏳ ' + stripped;
        });
        if (resultMsg) resultMsg.innerText = '';
        if (btnCloseSync) btnCloseSync.classList.add('hidden');
    };

    const setTaskActive = (key) => {
        const el = tasks[key];
        if (el) el.style.color = 'var(--text-main, #1f2937)';
    };

    const completeTask = (key, text) => {
        const el = tasks[key];
        if (!el) return;
        el.style.color = 'var(--color-green, #10b981)';
        el.innerText = `✅ ${text}`;
    };

    const failTask = (key, text) => {
        const el = tasks[key];
        if (!el) return;
        el.style.color = 'var(--color-red, #ef4444)';
        el.innerText = `❌ ${text}`;
    };

    // --- Modal öffnen & Token-Status prüfen ---

    btnOpenSync.addEventListener('click', async () => {
        resetTaskList();
        if (accessIdInput) accessIdInput.value = '';
        if (pinInput) pinInput.value = '';

        syncModal.classList.remove('hidden');
        syncModal.style.display = 'flex';

        try {
            const response = await KaiHttp.postJson('api.php', {action: 'check_token_status'});
            const tokensValid = response && response.success && response.tokens_valid;

            hideAllSteps();
            if (tokensValid) {
                stepProgress.classList.remove('hidden');
                await runSyncProcess();
            } else {
                stepCredentials.classList.remove('hidden');
            }
        } catch (err) {
            console.error('Token-Check Fehler:', err);
            hideAllSteps();
            stepCredentials.classList.remove('hidden');
        }
    });

    // Schließen-Handler
    if (btnCancelSync) btnCancelSync.addEventListener('click', closeModal);
    if (btnCancelSyncX) btnCancelSyncX.addEventListener('click', closeModal);
    if (btnCancelPhototanBtn) btnCancelPhototanBtn.addEventListener('click', closeModal);

    btnCloseSync.addEventListener('click', () => window.location.reload());

    // Checkbox aktiviert "Sync fortsetzen"-Button
    if (phototanConfirmChk && btnSubmitPhototan) {
        phototanConfirmChk.addEventListener('change', () => {
            btnSubmitPhototan.disabled = !phototanConfirmChk.checked;
        });
    }

    // --- Zugangsdaten absenden → Auth-Flow starten ---

    if (btnSubmitCredentials) {
        btnSubmitCredentials.addEventListener('click', async () => {
            const accessId = accessIdInput ? accessIdInput.value.trim() : '';
            const pin = pinInput ? pinInput.value.trim() : '';

            if (!accessId || !pin) {
                alert('Bitte Zugangsnummer und PIN eingeben.');
                return;
            }

            btnSubmitCredentials.disabled = true;
            btnSubmitCredentials.innerText = 'Bitte warten...';

            try {
                const response = await KaiHttp.postJson('api.php', {
                    action: 'start_auth_flow',
                    access_id: accessId,
                    pin: pin
                });

                if (!response || !response.success) {
                    alert(response?.message || 'Fehler beim Starten des Auth-Flows.');
                    btnSubmitCredentials.disabled = false;
                    btnSubmitCredentials.innerText = 'Token anfordern & Sync starten';
                    return;
                }

                // photoTAN-Push gesendet → Hinweis-Schritt zeigen
                hideAllSteps();
                if (stepPhototan) {
                    stepPhototan.classList.remove('hidden');
                    if (phototanLockContainer) phototanLockContainer.classList.add('hidden');
                    if (phototanConfirmChk) phototanConfirmChk.checked = false;
                    if (btnSubmitPhototan) btnSubmitPhototan.disabled = true;
                }
                btnSubmitCredentials.disabled = false;
                btnSubmitCredentials.innerText = 'Token anfordern & Sync starten';

            } catch (err) {
                console.error('Auth-Flow Fehler:', err);
                alert('Netzwerkfehler beim Login. Bitte versuche es erneut.');
                btnSubmitCredentials.disabled = false;
                btnSubmitCredentials.innerText = 'Token anfordern & Sync starten';
            }
        });
    }

    // --- photoTAN bestätigt → Aktivierungs-Check & Secondary-Token ---

    if (btnSubmitPhototan) {
        btnSubmitPhototan.addEventListener('click', async () => {
            btnSubmitPhototan.disabled = true;
            btnSubmitPhototan.innerText = 'Prüfe Freigabe...';

            const resetLock = resetLockChk ? resetLockChk.checked : false;

            try {
                const response = await KaiHttp.postJson('api.php', {
                    action: 'check_phototan_status',
                    reset_lock: resetLock
                });

                if (response && response.status === 'blocked') {
                    if (phototanLockContainer) phototanLockContainer.classList.remove('hidden');
                    if (phototanConfirmChk) phototanConfirmChk.checked = false;
                    btnSubmitPhototan.disabled = true;
                    btnSubmitPhototan.innerText = 'Sync fortsetzen';
                    alert(response.message || 'photoTAN gesperrt. Bitte erst auf der comdirect-Webseite anmelden.');
                    return;
                }

                if (response && response.status === 'pending') {
                    btnSubmitPhototan.disabled = false;
                    btnSubmitPhototan.innerText = 'Sync fortsetzen';
                    alert('Die photoTAN-Freigabe ist noch ausstehend. Bitte zuerst in der App bestätigen.');
                    return;
                }

                if (response && response.status === 'authenticated') {
                    hideAllSteps();
                    stepProgress.classList.remove('hidden');
                    await runSyncProcess();
                    return;
                }

                // Sonstiger Fehler
                const msg = response?.message || 'Unbekannter Fehler bei der TAN-Prüfung.';
                if (phototanLockContainer && msg.toLowerCase().includes('gesperrt')) {
                    phototanLockContainer.classList.remove('hidden');
                }
                alert(msg);
                btnSubmitPhototan.disabled = false;
                btnSubmitPhototan.innerText = 'Sync fortsetzen';

            } catch (err) {
                console.error('photoTAN-Check Fehler:', err);
                alert('Netzwerkfehler beim Prüfen der photoTAN.');
                btnSubmitPhototan.disabled = false;
                btnSubmitPhototan.innerText = 'Sync fortsetzen';
            }
        });
    }

    // --- Eigentlicher Sync-Prozess mit Live-Checkliste ---

    const runSyncProcess = async () => {
        setTaskActive('auth');

        try {
            const syncResponse = await KaiHttp.postJson('api.php', {action: 'run_sync'});

            if (!syncResponse || !syncResponse.success) {
                failTask('auth', 'Authentifizierung/Sync fehlgeschlagen');
                if (resultMsg) {
                    resultMsg.style.color = 'var(--color-red, #ef4444)';
                    resultMsg.innerText = syncResponse?.message || 'Sync fehlgeschlagen.';
                }
                btnCloseSync.classList.remove('hidden');
                return;
            }

            completeTask('auth', 'Authentifizierung & Token-Validierung erfolgreich');
            await delay(300);

            setTaskActive('balance');
            await delay(400);
            completeTask('balance', 'Konten- & Salden-Abgleich abgeschlossen');
            await delay(300);

            setTaskActive('tx');
            await delay(400);
            const importedCount = syncResponse.imported ?? 0;
            const ignoredCount = syncResponse.ignored ?? 0;
            completeTask('tx', `${importedCount} neue Transaktion(en) importiert (${ignoredCount} Duplikate ignoriert)`);
            await delay(300);

            setTaskActive('rules');
            await delay(400);
            const taggedCount = syncResponse.tagged ?? 0;
            completeTask('rules', `KI-Kategorisierung: ${taggedCount} Transaktion(en) getaggt`);

            if (resultMsg) {
                resultMsg.style.color = 'var(--color-green, #10b981)';
                resultMsg.innerText = 'Sync erfolgreich abgeschlossen!';
            }
            btnCloseSync.classList.remove('hidden');

        } catch (err) {
            console.error('Sync-Prozess Fehler:', err);
            failTask('auth', 'Netzwerkfehler beim Sync');
            if (resultMsg) {
                resultMsg.style.color = 'var(--color-red, #ef4444)';
                resultMsg.innerText = 'Netzwerkfehler. Bitte versuche es erneut.';
            }
            btnCloseSync.classList.remove('hidden');
        }
    };
});

function showTxDetailsModal(tx) {
    const overlay = document.createElement('div');
    overlay.className = 'rule-modal-overlay';

    const opt = (val) => val ? escapeHtml(val) : '<span class="text-muted">-</span>';

    overlay.innerHTML = `
        <div class="rule-modal-card">
            <div class="rule-modal-header">
                <h3>🔍 Buchungsdetails</h3>
                <button type="button" class="rule-modal-close js-close-details-modal">&times;</button>
            </div>
            <div class="rule-modal-body">
                <table style="width:100%; border-spacing: 10px;">
                    <tr><td>Referenz:</td><td>${opt(tx.transaction_id)}</td></tr>
                    <tr><td>Datum:</td><td>${escapeHtml(tx.booking_date || '')} (Valuta: ${escapeHtml(tx.valuta_date || '')})</td></tr>
                    <tr><td>Typ:</td><td>${escapeHtml(tx.type || '')}</td></tr>
                    <tr><td>Auftraggeber:</td><td>${opt(tx.remitter)}</td></tr>
                    <tr><td>Empfänger:</td><td>${opt(tx.creditor)}</td></tr>
                    <tr><td>Buchungstext:</td><td>${opt(tx.remittance_info)}</td></tr>
                    <tr><td>Betrag:</td><td class="${tx.amount < 0 ? 'text-danger' : 'text-success'}"><strong>${tx.amount} €</strong></td></tr>
                    ${tx.end_to_end_reference ? `<tr><td>End-to-End:</td><td>${escapeHtml(tx.end_to_end_reference)}</td></tr>` : ''}
                    ${tx.dc_creditor_id ? `<tr><td>Gläubiger-ID:</td><td>${escapeHtml(tx.dc_creditor_id)}</td></tr>` : ''}
                    ${tx.dc_mandate_id ? `<tr><td>Mandatsref:</td><td>${escapeHtml(tx.dc_mandate_id)}</td></tr>` : ''}
                </table>
            </div>
        </div>
    `;

    // Schließen beim Klick auf den Schließen-Button
    overlay.querySelector('.js-close-details-modal').addEventListener('click', () => {
        overlay.remove();
    });

    // Schließen beim Klick auf den Hintergrund (Overlay)
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            overlay.remove();
        }
    });

    document.body.appendChild(overlay);
}

async function openContractRuleBuilderModal(btn) {
    const txId = btn.dataset.txId;
    const remittanceInfo = btn.dataset.remittanceInfo || '';
    const remitter = btn.dataset.remitter || '';
    const creditor = btn.dataset.creditor || '';
    const mandateId = btn.dataset.mandateId || '';
    const creditorId = btn.dataset.creditorId || '';

    const overlay = document.createElement('div');
    overlay.className = 'rule-modal-overlay';

    overlay.innerHTML = `
        <div class="rule-modal-card" style="max-width: 580px;">
            <div class="rule-modal-header">
                <h3>📑 Vertrag & Zuordnung festlegen</h3>
                <button type="button" class="rule-modal-close js-close-modal">&times;</button>
            </div>
            
            <div class="rule-modal-body">
                <!-- 1. Ziel-Vertrag auswählen oder neu anlegen -->
                <div style="margin-bottom: 1.2rem;">
                    <label class="chart-label rule-group-label">Ziel-Vertrag:</label>
                    <select id="modal-contract-select" class="tag-search-input" style="width: 100%; padding: 0.6rem;">
                        <option value="">-- Neuen Vertrag aus Buchung erstellen --</option>
                    </select>
                </div>
            
                <!-- Checkbox für Einmalzuordnung ohne Regel -->
                <div style="margin-bottom: 1.2rem; padding-top: 0.5rem; border-top: 1px solid rgba(255,255,255,0.05);">
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; cursor: pointer;">
                        <input type="checkbox" id="modal-assign-only">
                        Nur diesen Umsatz zuordnen (keine automatische Regel erstellen)
                    </label>
                </div>
            
                <!-- Live-Trefferanzeige -->
                <div class="rule-live-row" style="margin-bottom: 1rem;">
                    <span class="rule-group-label">Kriterien werden kombiniert geprüft.</span>
                    <span class="live-match-pill js-live-match-pill">Prüfe Matches...</span>
                </div>

                <!-- 2. Primäre Kriterien: Mandat, Gläubiger-ID & Auftraggeber -->
                <div style="background: var(--bg-surface-hover); padding: 1rem; border-radius: 6px; margin-bottom: 1.2rem;">
                    <strong style="font-size: 0.9rem; display: block; margin-bottom: 0.6rem;">Primäre Identifikation (Empfohlen):</strong>
                    
                    ${mandateId ? `
                        <div style="margin-bottom: 0.6rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; cursor: pointer;">
                                <input type="checkbox" id="modal-use-mandate" checked class="js-contract-trigger">
                                Über SEPA-Mandatsnummer: <strong style="font-family: monospace; font-size: 0.8rem;">${escapeHtml(mandateId)}</strong>
                            </label>
                        </div>
                    ` : ''}

                    ${creditorId ? `
                        <div style="margin-bottom: 0.6rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; cursor: pointer;">
                                <input type="checkbox" id="modal-use-creditor-id" checked class="js-contract-trigger">
                                Über Gläubiger-ID: <strong style="font-family: monospace; font-size: 0.8rem;">${escapeHtml(creditorId)}</strong>
                            </label>
                        </div>
                    ` : ''}

                    <div>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; cursor: pointer; margin-bottom: 0.3rem;">
                            <input type="checkbox" id="modal-use-auftraggeber" checked class="js-contract-trigger">
                            Über Auftraggeber / Empfänger:
                        </label>
                        <input type="text" id="modal-auftraggeber-val" class="tag-search-input js-contract-trigger" 
                               value="${escapeHtml(remitter || creditor)}" 
                               style="font-size: 0.85rem; padding: 0.4rem 0.6rem;">
                    </div>
                </div>

                <!-- 3. Sekundäre Option: Muster auf Buchungstext (Optional mit Chips) -->
                <details style="margin-bottom: 1rem;" class="js-details-accordion">
                    <summary style="font-size: 0.85rem; color: var(--text-muted); cursor: pointer; user-select: none;">
                        Erweiterte Optionen: Muster auf Buchungstext (Optional)
                    </summary>
                    <div style="margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px solid rgba(255,255,255,0.1);">
                        <label class="chart-label rule-group-label">Buchungstext-Schnipsel (klickbar):</label>
                        <div class="word-segment-wrap js-word-segments" data-source-value="${escapeHtml(remittanceInfo)}" style="margin-bottom: 0.5rem;"></div>
                        
                        <input type="text" id="modal-text-pattern" class="tag-search-input rule-pattern-input js-contract-trigger" 
                               placeholder="z. B. Abrechnung Nr. ..." style="font-size: 0.85rem;">
                    </div>
                </details>
            </div>

            <div class="rule-modal-footer">
                <button type="button" class="btn btn-outline js-close-modal">Abbrechen</button>
                <button type="button" class="btn js-save-contract-rule">Speichern & Zuordnen</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    // Verträge async laden und Select befüllen
    try {
        const res = await KaiHttp.postJson('api.php', {action: 'get_contracts'});
        if (res && res.success && res.contracts) {
            const selectEl = overlay.querySelector('#modal-contract-select');
            res.contracts.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = `${c.name} (${c.type})`;
                selectEl.appendChild(opt);
            });
        }
    } catch (err) {
        console.error('Fehler beim Laden der Verträge:', err);
    }

    // Wort-Chips für den Buchungstext im Akkordeon aufbauen
    const wordsContainer = overlay.querySelector('.js-word-segments');
    if (wordsContainer) {
        const words = remittanceInfo.split(/\s+/).filter(w => w.trim().length > 0);
        words.forEach(word => {
            const chip = document.createElement('span');
            chip.className = 'word-chip';
            chip.textContent = word;
            chip.addEventListener('click', () => {
                chip.classList.toggle('selected');
                const selectedChips = Array.from(wordsContainer.querySelectorAll('.word-chip.selected'));
                const patternInput = overlay.querySelector('#modal-text-pattern');
                if (patternInput) {
                    patternInput.value = selectedChips.map(c => escapeRegex(c.textContent.trim())).join('.*');
                    triggerContractLiveCheck(overlay, mandateId, creditorId);
                }
            });
            wordsContainer.appendChild(chip);
        });
    }

    // Live-Check Trigger bei Eingabeänderungen
    overlay.querySelectorAll('.js-contract-trigger').forEach(el => {
        el.addEventListener('input', () => triggerContractLiveCheck(overlay, mandateId, creditorId));
        el.addEventListener('change', () => triggerContractLiveCheck(overlay, mandateId, creditorId));
    });

    // Initialen Check ausführen
    triggerContractLiveCheck(overlay, mandateId, creditorId);

    overlay.querySelectorAll('.js-close-modal').forEach(b => b.addEventListener('click', () => overlay.remove()));

    // Speichern-Button Logik
    overlay.querySelector('.js-save-contract-rule').addEventListener('click', async () => {
        const contractId = overlay.querySelector('#modal-contract-select').value;
        const assignOnly = overlay.querySelector('#modal-assign-only')?.checked || false;
        const useMandate = overlay.querySelector('#modal-use-mandate')?.checked || false;
        const useCreditorId = overlay.querySelector('#modal-use-creditor-id')?.checked || false;
        const useAuftraggeber = overlay.querySelector('#modal-use-auftraggeber')?.checked || false;
        const auftraggeberVal = overlay.querySelector('#modal-auftraggeber-val')?.value || '';
        const textPattern = overlay.querySelector('#modal-text-pattern')?.value || '';

        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'save_contract_rule',
                tx_id: parseInt(txId, 10),
                contract_id: contractId ? parseInt(contractId, 10) : null,
                assign_only: assignOnly, // Neu übergeben
                use_mandate: useMandate,
                mandate_id: mandateId,
                use_creditor_id: useCreditorId,
                creditor_id: creditorId,
                use_auftraggeber: useAuftraggeber,
                auftraggeber_val: auftraggeberVal,
                text_pattern: textPattern
            });

            if (data && data.success) {
                overlay.remove();
                window.location.reload();
            }
        } catch (err) {
            console.error('Fehler beim Speichern der Vertragszuordnung:', err);
        }
    });
}

// Live-Check für Vertragsregeln (Zählt treffende Buchungen)
let contractLiveTimeout = null;

function triggerContractLiveCheck(overlay, mandateId, creditorId) {
    if (contractLiveTimeout) clearTimeout(contractLiveTimeout);
    const pill = overlay.querySelector('.js-live-match-pill');
    if (pill) pill.textContent = 'Prüfe...';

    const useMandate = overlay.querySelector('#modal-use-mandate')?.checked || false;
    const useCreditorId = overlay.querySelector('#modal-use-creditor-id')?.checked || false;
    const useAuftraggeber = overlay.querySelector('#modal-use-auftraggeber')?.checked || false;
    const auftraggeberVal = overlay.querySelector('#modal-auftraggeber-val')?.value || '';
    const textPattern = overlay.querySelector('#modal-text-pattern')?.value || '';

    contractLiveTimeout = setTimeout(async () => {
        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'test_contract_rule_pattern',
                use_mandate: useMandate,
                mandate_id: mandateId,
                use_creditor_id: useCreditorId,
                creditor_id: creditorId,
                use_auftraggeber: useAuftraggeber,
                auftraggeber_val: auftraggeberVal,
                text_pattern: textPattern
            });

            if (pill && data && data.success) {
                pill.textContent = `🎯 Gilt für ${data.match_count} Buchung${data.match_count === 1 ? '' : 'en'}`;
            }
        } catch (err) {
            if (pill) pill.textContent = '⚠️ Prüffehler';
        }
    }, 300);
}

// ----------------------------------------------------
// Vertrags-Editor Modal (Erstellen & Bearbeiten)
// ----------------------------------------------------

let activeContractModal = null;

async function openContractModal(contract) {
    if (activeContractModal) {
        activeContractModal.remove();
        activeContractModal = null;
    }

    const isEdit = contract && contract.id;
    const cName = isEdit ? (contract.name || '') : '';
    const cType = isEdit ? (contract.type || 'fixkosten') : 'fixkosten';
    const cFrequenz = isEdit ? (contract.frequenz || 'monatlich') : 'monatlich';
    const cBetrag = isEdit ? (contract.betrag || '') : '';
    const cStatus = isEdit ? (contract.status || 'aktiv') : 'aktiv';
    const cAuftraggeber = isEdit ? (contract.auftraggeber || '') : '';
    const cMandat = isEdit ? (contract.mandatsnummer || '') : '';
    const cVariabel = isEdit ? (contract.variabel === 1) : false;

    const overlay = document.createElement('div');
    overlay.className = 'rule-modal-overlay';

    const recentTxsSection = isEdit ? `
        <div style="margin-top: 0.5rem; border-top: 1px solid var(--bg-surface-hover); padding-top: 0.8rem;">
            <label class="chart-label" style="margin-bottom: 0.5rem;">Letzte zugeordnete Buchungen:</label>
            <div class="js-modal-recent-txs" style="font-size: 0.85rem; color: var(--text-muted);">Lade Buchungen...</div>
        </div>
    ` : '';

    overlay.innerHTML = `
        <div class="rule-modal-card" style="max-width: 520px;">
            <div class="rule-modal-header">
                <h3>📑 Vertrag ${isEdit ? 'bearbeiten' : 'neu anlegen'}</h3>
                <button type="button" class="rule-modal-close js-close-contract-modal">&times;</button>
            </div>
            
            <div class="rule-modal-body" style="gap: 1rem;">
                <div>
                    <label class="chart-label" style="margin-bottom: 0.3rem;">Name des Vertrags / Abos:</label>
                    <input type="text" id="modal-contract-name" class="tag-search-input" value="${escapeHtml(cName)}" placeholder="z. B. Netflix, Miete, Haftpflicht" style="font-weight: 600;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="chart-label" style="margin-bottom: 0.3rem;">Typ:</label>
                        <select id="modal-contract-type" class="tag-search-input" style="width: 100%; padding: 0.4rem;">
                            <option value="vertrag" ${cType === 'vertrag' ? 'selected' : ''}>Vertrag</option>
                            <option value="abo" ${cType === 'abo' ? 'selected' : ''}>Abo</option>
                            <option value="abgabe" ${cType === 'abgabe' ? 'selected' : ''}>Abgabe</option>
                            <option value="kredit" ${cType === 'kredit' ? 'selected' : ''}>Kredit</option>
                        </select>
                    </div>
                    <div>
                        <label class="chart-label" style="margin-bottom: 0.3rem;">Status:</label>
                        <select id="modal-contract-status" class="tag-search-input" style="width: 100%; padding: 0.4rem;">
                            <option value="aktiv" ${cStatus === 'aktiv' ? 'selected' : ''}>Aktiv</option>
                            <option value="pausiert" ${cStatus === 'pausiert' ? 'selected' : ''}>Pausiert</option>
                            <option value="gekuendigt" ${cStatus === 'gekuendigt' ? 'selected' : ''}>Gekündigt</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div>
                        <label class="chart-label" style="margin-bottom: 0.3rem;">Betrag (€):</label>
                        <input type="number" step="0.01" id="modal-contract-betrag" class="tag-search-input" value="${escapeHtml(cBetrag)}" placeholder="0.00">
                    </div>
                    <div>
                        <label class="chart-label" style="margin-bottom: 0.3rem;">Frequenz:</label>
                        <select id="modal-contract-frequenz" class="tag-search-input" style="width: 100%; padding: 0.4rem;">
                            <option value="monatlich" ${cFrequenz === 'monatlich' ? 'selected' : ''}>Monatlich</option>
                            <option value="vierteljaehrlich" ${cFrequenz === 'vierteljaehrlich' ? 'selected' : ''}>Vierteljährlich</option>
                            <option value="halbjaehrlich" ${cFrequenz === 'halbjaehrlich' ? 'selected' : ''}>Halbjährlich</option>
                            <option value="jaehrlich" ${cFrequenz === 'jaehrlich' ? 'selected' : ''}>Jährlich</option>
                            <option value="einmalig" ${cFrequenz === 'einmalig' ? 'selected' : ''}>Einmalig</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="chart-label" style="margin-bottom: 0.3rem;">Auftraggeber / Empfänger (optional):</label>
                    <input type="text" id="modal-contract-auftraggeber" class="tag-search-input" value="${escapeHtml(cAuftraggeber)}" placeholder="Exakter Name aus Buchung">
                </div>

                <div>
                    <label class="chart-label" style="margin-bottom: 0.3rem;">SEPA-Mandatsnummer (optional):</label>
                    <input type="text" id="modal-contract-mandat" class="tag-search-input" value="${escapeHtml(cMandat)}" placeholder="Mandatsreferenz">
                </div>

                <div>
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.85rem; cursor: pointer;">
                        <input type="checkbox" id="modal-contract-variabel" ${cVariabel ? 'checked' : ''}>
                        Variabler Betrag (Schwankungen erlaubt)
                    </label>
                </div>

                ${recentTxsSection}
            </div>

            <div class="rule-modal-footer">
                <div>
                    ${isEdit ? `<button type="button" class="btn btn-outline btn-danger-outline js-delete-contract">🗑 Löschen</button>` : ''}
                </div>
                <div class="rule-modal-actions">
                    <button type="button" class="btn btn-outline js-close-contract-modal">Abbrechen</button>
                    <button type="button" class="btn js-save-contract">Speichern</button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);
    activeContractModal = overlay;

    // Asynchron die letzten Buchungen laden (falls Bearbeitungsmodus)
    if (isEdit) {
        try {
            const txRes = await KaiHttp.postJson('api.php', {
                action: 'get_contract_transactions',
                contract_id: parseInt(contract.id, 10),
                limit: 5
            });

            const container = overlay.querySelector('.js-modal-recent-txs');
            if (container) {
                if (txRes && txRes.success && txRes.transactions && txRes.transactions.length > 0) {
                    container.innerHTML = `
                        <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                            ${txRes.transactions.map(tx => `
                                <div style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02); padding: 0.3rem 0.5rem; border-radius: 4px;">
                                    <span>${escapeHtml(tx.booking_date)} – ${escapeHtml(tx.remitter || tx.creditor || tx.remittance_info || 'Buchung')}</span>
                                    <strong class="${tx.amount < 0 ? 'text-danger' : 'text-success'}">${tx.amount} €</strong>
                                </div>
                            `).join('')}
                        </div>
                    `;
                } else {
                    container.innerHTML = 'Keine zugeordneten Buchungen gefunden.';
                }
            }
        } catch (err) {
            console.error('Fehler beim Laden der Buchungen:', err);
            const container = overlay.querySelector('.js-modal-recent-txs');
            if (container) container.innerHTML = `<span style="color: var(--color-red);">Fehler: ${escapeHtml(err.message || 'Unbekannt')}</span>`;
        }
    }

    const closeModal = () => {
        overlay.remove();
        activeContractModal = null;
    };

    overlay.querySelectorAll('.js-close-contract-modal').forEach(b => b.addEventListener('click', closeModal));

    // Speichern-Aktion
    overlay.querySelector('.js-save-contract').addEventListener('click', async () => {
        const name = overlay.querySelector('#modal-contract-name').value.trim();
        const type = overlay.querySelector('#modal-contract-type').value;
        const status = overlay.querySelector('#modal-contract-status').value;
        const betrag = parseFloat(overlay.querySelector('#modal-contract-betrag').value || '0');
        const frequenz = overlay.querySelector('#modal-contract-frequenz').value;
        const auftraggeber = overlay.querySelector('#modal-contract-auftraggeber').value.trim();
        const mandatsnummer = overlay.querySelector('#modal-contract-mandat').value.trim();
        const variabel = overlay.querySelector('#modal-contract-variabel').checked ? 1 : 0;

        if (!name) {
            alert('Bitte einen Namen für den Vertrag eingeben.');
            return;
        }

        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'save_contract_details',
                contract_id: isEdit ? parseInt(contract.id, 10) : null,
                name: name,
                type: type,
                status: status,
                betrag: betrag,
                frequenz: frequenz,
                auftraggeber: auftraggeber,
                mandatsnummer: mandatsnummer,
                variabel: variabel
            });

            if (data && data.success) {
                closeModal();
                window.location.reload();
            } else {
                alert(data?.message || 'Fehler beim Speichern des Vertrags.');
            }
        } catch (err) {
            console.error('Fehler beim Speichern des Vertrags:', err);
        }
    });

    // Löschen-Aktion (falls im Bearbeitungsmodus)
    if (isEdit) {
        overlay.querySelector('.js-delete-contract').addEventListener('click', async () => {
            if (!confirm('Diesen Vertrag wirklich löschen?')) return;
            try {
                const data = await KaiHttp.postJson('api.php', {
                    action: 'delete_contract',
                    contract_id: parseInt(contract.id, 10)
                });

                if (data && data.success) {
                    closeModal();
                    window.location.reload();
                } else {
                    alert(data?.message || 'Fehler beim Löschen.');
                }
            } catch (err) {
                console.error('Fehler beim Löschen des Vertrags:', err);
            }
        });
    }
}
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
        const tag = (window.AVAILABLE_TAGS || []).find(t => t.id == tagId);
        if (tag) {
            openEditTagModal(tag);
        }
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
        const tag = (window.AVAILABLE_TAGS || []).find(t => t.id == tagId);
        if (tag) {
            addExistingTagToTx(txId, tag);
        }
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

    if (activePopover && activePopover.contains(e.target)) {
        return;
    }

    if (activePopover) {
        closePopover();
    }
});

// ----------------------------------------------------
// Visual Regex Builder Modal (Phase 3.2 - 3.4)
// ----------------------------------------------------

function openRuleBuilderModal(btn) {
    closeRuleModal();

    const txId = btn.dataset.txId;
    const ruleId = btn.dataset.ruleId || null;
    const merchantRaw = btn.dataset.merchantRaw || '';
    const initialPattern = btn.dataset.textPattern || '';

    // Aktuell an der Transaktion befindliche Tags auslesen (Vorselektion)
    const rowGroup = document.querySelector(`.js-tag-group[data-tx-id="${txId}"]`);
    const existingTagBadges = rowGroup ? rowGroup.querySelectorAll('.tag-badge') : [];
    const selectedTagIds = Array.from(existingTagBadges).map(b => parseInt(b.dataset.tagId, 10));

    const overlay = document.createElement('div');
    overlay.className = 'rule-modal-overlay';

    overlay.innerHTML = `
        <div class="rule-modal-card">
            <div class="rule-modal-header">
                <h3>🪄 Rule Builder ${ruleId ? '(Regel bearbeiten)' : '(Neue Regel ersteller)'}</h3>
                <button type="button" class="rule-modal-close js-close-modal">&times;</button>
            </div>
            
            <div class="rule-modal-body">
                <div>
                    <label class="chart-label" style="margin-bottom:0.3rem;">Buchungstext (Klickbar für Regex):</label>
                    <div class="word-segment-wrap js-word-segments"></div>
                </div>

                <div>
                    <label class="chart-label" style="margin-bottom:0.3rem;">Helper & Schnellbausteine:</label>
                    <div class="helper-btn-group">
                        <button type="button" class="btn-helper js-helper-exact">🔤 Exakter Wortstamm</button>
                        <button type="button" class="btn-helper js-helper-start">^ Startet mit</button>
                        <button type="button" class="btn-helper js-helper-num">🔢 Zahlen durch \\d+ ersetzen</button>
                        <button type="button" class="btn-helper js-helper-wild">.* Wildcard</button>
                    </div>
                </div>

                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.3rem;">
                        <label class="chart-label" style="margin-bottom:0;">Regel-Muster (Regex):</label>
                        <span class="live-match-pill js-live-match-pill"> Prüfe Matches...</span>
                    </div>
                    <input type="text" class="tag-search-input js-rule-pattern" value="${escapeHtml(initialPattern)}" placeholder="z. B. REWE oder ^REWE.*" style="font-family:monospace; font-size:0.95rem;">
                </div>

                <div>
                    <label class="chart-label" style="margin-bottom:0.3rem;">Zuweisende Tags:</label>
                    <div class="tag-pill-group js-modal-tags-wrap" style="min-height:32px;"></div>
                </div>
            </div>

            <div class="rule-modal-footer">
                <div>
                    ${ruleId ? `<button type="button" class="btn btn-outline js-delete-rule" style="border-color:var(--color-red); color:var(--color-red);">🗑 Regel löschen</button>` : ''}
                </div>
                <div style="display:flex; gap:0.5rem;">
                    <button type="button" class="btn btn-outline js-close-modal">Abbrechen</button>
                    <button type="button" class="btn js-save-rule">Speichern & Anwenden</button>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);
    activeRuleModal = overlay;

    // Wort-Chips aus merchantRaw aufbauen
    const wordsContainer = overlay.querySelector('.js-word-segments');
    const words = merchantRaw.split(/(\s+)/).filter(w => w.trim().length > 0);
    
    words.forEach(word => {
        const chip = document.createElement('span');
        chip.className = 'word-chip';
        chip.textContent = word;
        
        chip.addEventListener('click', () => {
            chip.classList.toggle('selected');

            // Alle aktuell ausgewählten Wort-Chips auslesen
            const selectedChips = Array.from(wordsContainer.querySelectorAll('.word-chip.selected'));
            
            if (selectedChips.length > 0) {
                // Ausgewählte Wörter escapen und mit .* verknüpfen
                const combinedPattern = selectedChips
                    .map(c => escapeRegex(c.textContent.trim()))
                    .join('.*');

                patternInput.value = combinedPattern;
            } else {
                patternInput.value = '';
            }

            triggerLiveMatchCheck(patternInput.value);
        });

        wordsContainer.appendChild(chip);
    });

    // Helper Buttons Event Binding
    const patternInput = overlay.querySelector('.js-rule-pattern');

    overlay.querySelector('.js-helper-exact').addEventListener('click', () => {
        if (patternInput.value) {
            patternInput.value = `\\b${patternInput.value.replace(/^\\b|\\b$/g, '')}\\b`;
            triggerLiveMatchCheck(patternInput.value);
        }
    });

    overlay.querySelector('.js-helper-start').addEventListener('click', () => {
        if (patternInput.value && !patternInput.value.startsWith('^')) {
            patternInput.value = `^${patternInput.value}`;
            triggerLiveMatchCheck(patternInput.value);
        }
    });

    overlay.querySelector('.js-helper-num').addEventListener('click', () => {
        patternInput.value = patternInput.value.replace(/\d+/g, '\\d+');
        triggerLiveMatchCheck(patternInput.value);
    });

    overlay.querySelector('.js-helper-wild').addEventListener('click', () => {
        patternInput.value = patternInput.value + '.*';
        triggerLiveMatchCheck(patternInput.value);
    });

    // Pattern Live-Input Handler
    patternInput.addEventListener('input', (e) => {
        triggerLiveMatchCheck(e.target.value);
    });

    // Modal-Tag-Picker rendern
    renderModalTagPicker(overlay.querySelector('.js-modal-tags-wrap'), selectedTagIds);

    // Initialen Live-Check ausführen
    triggerLiveMatchCheck(patternInput.value || merchantRaw);

    // Event Listener für Buttons im Footer & Close
    overlay.querySelectorAll('.js-close-modal').forEach(b => b.addEventListener('click', closeRuleModal));
    
    overlay.querySelector('.js-save-rule').addEventListener('click', async () => {
        const textPattern = patternInput.value.trim();
		const currentTagIds = Array.from(overlay.querySelectorAll('.js-modal-tag-chip.active')).map(c => parseInt(c.dataset.tagId, 10));

        if (!textPattern) {
            alert('Bitte ein Muster angeben.');
            return;
        }

        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'save_rule',
                rule_id: ruleId ? parseInt(ruleId, 10) : null,
                tx_id: parseInt(txId, 10),
                text_pattern: textPattern,
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

function triggerLiveMatchCheck(pattern) {
    if (liveTestTimeout) clearTimeout(liveTestTimeout);
    
    const pill = document.querySelector('.js-live-match-pill');
    if (pill) pill.textContent = 'Prüfe...';

    liveTestTimeout = setTimeout(async () => {
        try {
            const data = await KaiHttp.postJson('api.php', {
                action: 'test_rule_pattern',
                text_pattern: pattern
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
    if (activeFilterTagId === tagId) {
        resetTagFilter();
        return;
    }

    activeFilterTagId = tagId;
    applyTableFilter();
}

function resetTagFilter() {
    activeFilterTagId = null;
    applyTableFilter();
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
                const formattedAmt = stat.absTotal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
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
            const formattedAmt = stat.absTotal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
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
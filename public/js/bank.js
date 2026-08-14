// Scope-Variablen auf Modulebene
let activePopover = null;

document.addEventListener('DOMContentLoaded', () => {
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
    const removeBtn = e.target.closest('.remove-tag-btn');
    if (removeBtn) {
        e.preventDefault();
        e.stopPropagation();
        removeTagFromTx(removeBtn.dataset.txId, removeBtn.dataset.tagId);
        return;
    }

    const openBtn = e.target.closest('.js-open-tag-popover');
    if (openBtn) {
        e.preventDefault();
        e.stopPropagation();
        openTagPopover(openBtn, openBtn.dataset.txId);
        return;
    }

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
// AJAX-Aktionen
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

// ----------------------------------------------------
// Dynamische UI-Updates
// ----------------------------------------------------

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
}

/**
 * Aggregiert die sichtbar zugewiesenen Tags der aktuellen Tabelle neu
 * und aktualisiert den Verteilungsbalken + die Kacheln gewichtet nach Euro-Beträgen.
 */
function updateTagStatsBar() {
    const grid = document.getElementById('active-tags-grid');
    const distBar = document.querySelector('.tag-distribution-bar');
    if (!grid) return;

    const statsMap = {};
    let totalAbsoluteAmount = 0;
    let maxTagAmount = 0;

    // 1. Beträge pro Tag aufsummieren
    const rows = document.querySelectorAll('tr[data-tx-id]');
    rows.forEach(row => {
        const amount = parseFloat(row.dataset.amount || '0');
        const badges = row.querySelectorAll('.tag-badge');

        badges.forEach(badge => {
            const tagId = badge.dataset.tagId;
            const tagName = badge.textContent.replace('×', '').trim();
            const tagColor = badge.style.color || '#3b82f6';

            if (!statsMap[tagId]) {
                statsMap[tagId] = {
                    id: tagId,
                    name: tagName,
                    color: tagColor,
                    count: 0,
                    total: 0,
                    absTotal: 0
                };
            }

            statsMap[tagId].count += 1;
            statsMap[tagId].total += amount;
            statsMap[tagId].absTotal += Math.abs(amount);
        });
    });

    // Max und Summe bestimmen
    Object.values(statsMap).forEach(stat => {
        totalAbsoluteAmount += stat.absTotal;
        if (stat.absTotal > maxTagAmount) {
            maxTagAmount = stat.absTotal;
        }
    });

    // Sortierung nach absolutem Euro-Betrag
    const sortedStats = Object.values(statsMap).sort((a, b) => b.absTotal - a.absTotal);

    // 2. Verteilungsbalken mit Betragsanteilen rendern
    if (distBar) {
        distBar.innerHTML = '';
        sortedStats.forEach(stat => {
            const pct = totalAbsoluteAmount > 0 ? (stat.absTotal / totalAbsoluteAmount) * 100 : 0;
            if (pct <= 0) return;

            const formattedAmt = stat.absTotal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
            const seg = document.createElement('div');
            seg.className = 'tag-bar-segment';
            seg.style.width = `${pct.toFixed(2)}%`;
            seg.style.backgroundColor = stat.color;
            seg.title = `${stat.name}: ${pct.toFixed(1)}% (${formattedAmt} | ${stat.count} Buchungen)`;
            distBar.appendChild(seg);
        });
    }

    // 3. Kacheln neu rendern
    grid.innerHTML = '';
    const currentUrlParams = new URLSearchParams(window.location.search);
    const type = currentUrlParams.get('type') || 'monat';
    const date = currentUrlParams.get('date') || '';

    sortedStats.forEach(stat => {
        const sign = stat.total < 0 ? '-' : '+';
        const formattedAmt = stat.absTotal.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
        const fillPct = maxTagAmount > 0 ? (stat.absTotal / maxTagAmount) * 100 : 0;
        const isDanger = stat.total < 0;

        const card = document.createElement('a');
        card.href = `?type=${encodeURIComponent(type)}&date=${encodeURIComponent(date)}&tag_id=${stat.id}`;
        card.className = 'tag-stat-card';
        card.dataset.statTagId = stat.id;
        card.style.setProperty('--tag-color', stat.color);

        card.innerHTML = `
            <div class="tag-stat-bg-bar" style="width: ${fillPct.toFixed(1)}%;"></div>
            <div class="tag-stat-content">
                <div class="tag-stat-header">
                    <span class="tag-color-dot" style="background-color: ${stat.color};"></span>
                    <span class="tag-stat-name">${escapeHtml(stat.name)}</span>
                </div>
                <div class="tag-stat-metrics">
                    <span class="tag-stat-amount ${isDanger ? 'text-danger' : 'text-success'}">
                        ${sign}${formattedAmt}
                    </span>
                    <span class="tag-stat-count">${stat.count} Buchung${stat.count === 1 ? '' : 'en'}</span>
                </div>
            </div>
        `;

        grid.appendChild(card);
    });
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
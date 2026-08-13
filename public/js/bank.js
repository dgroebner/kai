// Scope-Variablen auf Modulebene
let activePopover = null;

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

// 2. Ein einziger globaler Klick-Dispatcher (Event Delegation)
document.addEventListener('click', (e) => {
    // A: Tag entfernen
    const removeBtn = e.target.closest('.remove-tag-btn');
    if (removeBtn) {
        e.stopPropagation();
        removeTagFromTx(removeBtn.dataset.txId, removeBtn.dataset.tagId);
        return;
    }

    // B: Popover öffnen
    const openBtn = e.target.closest('.js-open-tag-popover');
    if (openBtn) {
        e.stopPropagation();
        openTagPopover(openBtn, openBtn.dataset.txId);
        return;
    }

    // C: Klick auf existierendes Tag im Popover
    const tagOption = e.target.closest('.js-tag-option');
    if (tagOption) {
        e.stopPropagation();
        const txId = tagOption.dataset.txId;
        const tagId = tagOption.dataset.tagId;
        const tag = (window.AVAILABLE_TAGS || []).find(t => t.id == tagId);
        if (tag) {
            addExistingTagToTx(txId, tag);
        }
        return;
    }

    // D: Klick auf "Neu anlegen" im Popover
    const createOption = e.target.closest('.js-tag-create');
    if (createOption && activePopover) {
        e.stopPropagation();
        const txId = createOption.dataset.txId;
        const name = createOption.dataset.tagName;
        const colorInput = activePopover.querySelector('.js-tag-color');
        const color = colorInput ? colorInput.value : '#3b82f6';
        createNewTagAndAssign(txId, name, color);
        return;
    }

    // E: Klick außerhalb schließt das Popover
    if (activePopover && !activePopover.contains(e.target)) {
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

    // Reines Rendering ohne Event-Binding!
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

    // Nur der Tastatur-Input aktualisiert das Markup
    searchInput.addEventListener('input', (e) => {
        renderOptions(e.target.value.trim());
    });
}

async function addExistingTagToTx(txId, tag) {
    try {
        const data = await KaiHttp.postJson('api.php', {
            action: 'add_tag_to_tx',
            tx_id: parseInt(txId, 10),
            tag_id: parseInt(tag.id, 10)
        });

        if (data.success) {
            appendBadgeToUI(txId, tag);
            closePopover();
        }
    } catch (err) {
        console.error('Fehler beim Zuweisen:', err);
    }
}

async function createNewTagAndAssign(txId, name, color) {
    try {
        const data = await KaiHttp.postJson('api.php', {
            action: 'create_and_assign_tag',
            tx_id: parseInt(txId, 10),
            name: name,
            color: color
        });

        if (data.success && data.tag) {
            window.AVAILABLE_TAGS.push(data.tag);
            appendBadgeToUI(txId, data.tag);
            closePopover();
        }
    } catch (err) {
        console.error('Fehler beim Erstellen:', err);
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
    badge.style.color = tag.color;
    badge.style.borderColor = tag.color;
    badge.innerHTML = `
        ${escapeHtml(tag.name)}
        <span class="remove-tag-btn" data-tx-id="${txId}" data-tag-id="${tag.id}">&times;</span>
    `;

    group.insertBefore(badge, openBtn);
}

async function removeTagFromTx(txId, tagId) {
    try {
        const data = await KaiHttp.postJson('api.php', {
            action: 'remove_tag_from_tx',
            tx_id: parseInt(txId, 10),
            tag_id: parseInt(tagId, 10)
        });

        if (data.success) {
            const group = document.querySelector(`.js-tag-group[data-tx-id="${txId}"]`);
            if (group) {
                const badge = group.querySelector(`[data-tag-id="${tagId}"]`);
                if (badge) badge.remove();
            }
        }
    } catch (err) {
        console.error('Fehler beim Entfernen:', err);
    }
}

function escapeHtml(str) {
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
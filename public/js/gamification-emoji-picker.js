/**
 * Gamification Emoji Picker
 * Stellt einen wiederverwendbaren, modalen Emoji-Auswahldialog für Mitspieler-Symbole,
 * Aufgaben-Icons, Belohnungen und Abzeichen bereit.
 */
window.GamifEmojiPicker = (() => {
    'use strict';

    const EMOJI_CATEGORIES = [
        {
            id: 'animals',
            label: '🦁 Tiere',
            emojis: [
                '🦁', '🐯', '🐱', '🐶', '🐺', '🦊', '🦝', '🐻', '🐼', '🐨',
                '🐰', '🐹', '🐭', '🦄', '🐴', '🦓', '🦒', '🐘', '🦏', '🦛',
                '🦖', '🦕', '🐢', '🐬', '🐳', '🦈', '🐙', '🦋', '🐝', '🦉',
                '🦅', '🐧', '🦜', '🐸', '🐲', '🐵', '🐮', '🐷', '🐑', '🦔'
            ]
        },
        {
            id: 'fantasy',
            label: '🦸 Helden & Fantasie',
            emojis: [
                '⭐', '🌟', '✨', '👑', '🧙', '🧙‍♀️', '🦸', '🦸‍♀️', '🥷', '🤺',
                '🤖', '👾', '👻', '👽', '🚀', '🛸', '⚔️', '🛡️', '🏹', '💎',
                '🔮', '🪄', '⚡', '🔥', '🌈', '🥇', '🏆', '🎖️', '🎯', '💫'
            ]
        },
        {
            id: 'sports',
            label: '⚽ Hobbys & Spiele',
            emojis: [
                '⚽', '🏀', '🏈', '🎾', '🏐', '🛹', '🚲', '🛴', '🏎️', '🎮',
                '🕹️', '🎲', '🧩', '🎨', '🖌️', '🎸', '🎹', '🥁', '🎤', '📚',
                '🔭', '⛺', '🥋', '🏊', '🎣', '⛷️', '🧗', '🥊', '🎳', '🪁'
            ]
        },
        {
            id: 'food',
            label: '🍔 Essen & Naschen',
            emojis: [
                '🍕', '🍔', '🍟', '🌭', '🥪', '🌮', '🍦', '🍧', '🍨', '🍩',
                '🍪', '🎂', '🍰', '🍫', '🍬', '🍭', '🍿', '🍎', '🍓', '🍉',
                '🍇', '🍌', '🍒', '🍑', '🥞', '🥨', '🥐', '🧁', '🍹', '🧃'
            ]
        },
        {
            id: 'faces',
            label: '😄 Spaß & Gesten',
            emojis: [
                '😎', '🤩', '🥳', '🤠', '🤓', '🥰', '🤗', '😺', '😸', '😻',
                '💖', '🎉', '💡', '💪', '✌️', '🙌', '👏', '☀️', '🌸', '🍀'
            ]
        }
    ];

    let modalEl = null;
    let gridEl = null;
    let currentCallback = null;
    let activeCategory = 'all';

    function initModal() {
        if (modalEl) return;

        modalEl = document.createElement('div');
        modalEl.id = 'modal-global-emoji-picker';
        modalEl.className = 'modal-overlay hidden';

        modalEl.innerHTML = `
            <div class="modal-card modal-card--emoji">
                <div class="modal-header">
                    <h3>Symbol auswählen ✨</h3>
                    <button type="button" class="btn btn-outline btn-sm js-picker-close">✕</button>
                </div>
                <div class="modal-body">
                    <div class="gamif-emoji-categories">
                        <button type="button" class="gamif-emoji-cat-btn active" data-cat="all">Alle</button>
                        ${EMOJI_CATEGORIES.map(c => `<button type="button" class="gamif-emoji-cat-btn" data-cat="${c.id}">${c.label}</button>`).join('')}
                    </div>
                    <div class="gamif-emoji-grid" id="global-emoji-grid"></div>
                    <div class="modal-actions" style="display:flex; justify-content:flex-end; margin-top:1rem;">
                        <button type="button" class="btn btn-outline js-picker-close">Abbrechen</button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalEl);
        gridEl = modalEl.querySelector('#global-emoji-grid');

        // Category switching
        modalEl.querySelectorAll('.gamif-emoji-cat-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                modalEl.querySelectorAll('.gamif-emoji-cat-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeCategory = btn.getAttribute('data-cat');
                renderEmojis();
            });
        });

        // Close handlers
        modalEl.querySelectorAll('.js-picker-close').forEach(btn => {
            btn.addEventListener('click', close);
        });

        modalEl.addEventListener('click', (e) => {
            if (e.target === modalEl) close();
        });

        // Emoji selection via event delegation
        gridEl.addEventListener('click', (e) => {
            const tile = e.target.closest('.gamif-emoji-tile');
            if (!tile) return;
            const emoji = tile.getAttribute('data-emoji');
            if (typeof currentCallback === 'function') {
                currentCallback(emoji);
            }
            close();
        });
    }

    function renderEmojis() {
        if (!gridEl) return;
        let list = [];
        if (activeCategory === 'all') {
            EMOJI_CATEGORIES.forEach(c => {
                list = list.concat(c.emojis);
            });
            // remove duplicates if any
            list = Array.from(new Set(list));
        } else {
            const cat = EMOJI_CATEGORIES.find(c => c.id === activeCategory);
            list = cat ? cat.emojis : [];
        }

        gridEl.innerHTML = list.map(emoji => `
            <button type="button" class="gamif-emoji-tile" data-emoji="${KaiHtml.escape(emoji)}" title="${KaiHtml.escape(emoji)}">
                ${KaiHtml.escape(emoji)}
            </button>
        `).join('');
    }

    function open(options = {}) {
        initModal();
        currentCallback = options.onSelect || null;
        renderEmojis();
        modalEl.classList.remove('hidden');
    }

    function close() {
        if (modalEl) {
            modalEl.classList.add('hidden');
        }
        currentCallback = null;
    }

    // Auto-hook `.js-open-emoji-picker` clicks
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-open-emoji-picker');
        if (!btn) return;

        const targetInputSel = btn.getAttribute('data-target-input');
        const targetPreviewSel = btn.getAttribute('data-target-preview');

        const targetInput = targetInputSel ? document.querySelector(targetInputSel) : null;
        const targetPreview = targetPreviewSel ? document.querySelector(targetPreviewSel) : null;

        open({
            onSelect: (emoji) => {
                if (targetInput) {
                    targetInput.value = emoji;
                    // Trigger input and change events so listening forms detect changes
                    targetInput.dispatchEvent(new Event('input', { bubbles: true }));
                    targetInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (targetPreview) {
                    targetPreview.textContent = emoji;
                }
            }
        });
    });

    return {
        open,
        close
    };
})();

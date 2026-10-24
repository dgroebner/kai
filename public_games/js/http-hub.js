/**
 * http-hub.js - Leichtgewichtiger AJAX-Helfer für games.agent-smith.de
 */
const HubHttp = {
    async postJson(url, data = {}) {
        const playerToken = localStorage.getItem('kai_gh_player_token') || '';
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Player-Token': playerToken
                },
                body: JSON.stringify(data)
            });
            const json = await res.json();
            return { ok: res.ok, status: res.status, data: json };
        } catch (err) {
            return { ok: false, status: 0, error: err.message };
        }
    },

    async getJson(url) {
        const playerToken = localStorage.getItem('kai_gh_player_token') || '';
        try {
            const res = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Player-Token': playerToken
                }
            });
            const json = await res.json();
            return { ok: res.ok, status: res.status, data: json };
        } catch (err) {
            return { ok: false, status: 0, error: err.message };
        }
    }
};

const HubHtml = {
    escape(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
};

const HubDialog = {
    _overlay: null,

    _ensureModal() {
        if (this._overlay && document.body.contains(this._overlay)) {
            return this._overlay;
        }

        let el = document.getElementById('hub-dialog-overlay');
        if (!el) {
            el = document.createElement('div');
            el.id = 'hub-dialog-overlay';
            el.className = 'hub-modal-overlay hidden';
            el.setAttribute('role', 'dialog');
            el.setAttribute('aria-modal', 'true');
            el.innerHTML = `
                <div class="hub-modal text-center" style="max-width: 400px; animation: modal-in 0.2s cubic-bezier(0.16, 1, 0.3, 1);">
                    <div id="hub-dialog-icon" style="font-size: 2.75rem; margin-bottom: 0.5rem; line-height: 1;">ℹ️</div>
                    <h3 id="hub-dialog-title" class="hub-modal-title" style="margin-bottom: 0.5rem; font-size: 1.25rem;">Hinweis</h3>
                    <div id="hub-dialog-message" style="color: var(--text-muted); font-size: 0.93rem; line-height: 1.5; margin-bottom: 1.35rem; white-space: pre-line;"></div>
                    <div id="hub-dialog-actions" class="hub-modal-actions" style="justify-content: center; gap: 0.65rem;">
                        <button type="button" class="btn btn-outline" id="hub-dialog-cancel">Abbrechen</button>
                        <button type="button" class="btn btn-primary" id="hub-dialog-confirm">OK</button>
                    </div>
                </div>
            `;
            document.body.appendChild(el);
        }
        this._overlay = el;
        return el;
    },

    alert(title, message, icon = 'ℹ️') {
        return new Promise((resolve) => {
            const overlay = this._ensureModal();
            const iconEl = document.getElementById('hub-dialog-icon');
            const titleEl = document.getElementById('hub-dialog-title');
            const msgEl = document.getElementById('hub-dialog-message');
            const btnCancel = document.getElementById('hub-dialog-cancel');
            const btnConfirm = document.getElementById('hub-dialog-confirm');

            if (iconEl) iconEl.textContent = icon;
            if (titleEl) titleEl.textContent = title;
            if (msgEl) msgEl.textContent = message;

            if (btnCancel) btnCancel.classList.add('hidden');
            if (btnConfirm) {
                btnConfirm.className = 'btn btn-primary btn-lg';
                btnConfirm.style.width = '100%';
                btnConfirm.textContent = 'Verstanden';
            }

            const onConfirm = () => {
                cleanup();
                resolve(true);
            };

            const cleanup = () => {
                overlay.classList.add('hidden');
                btnConfirm?.removeEventListener('click', onConfirm);
            };

            btnConfirm?.addEventListener('click', onConfirm);
            overlay.classList.remove('hidden');
        });
    },

    confirm(title, message, options = {}) {
        return new Promise((resolve) => {
            const overlay = this._ensureModal();
            const iconEl = document.getElementById('hub-dialog-icon');
            const titleEl = document.getElementById('hub-dialog-title');
            const msgEl = document.getElementById('hub-dialog-message');
            const btnCancel = document.getElementById('hub-dialog-cancel');
            const btnConfirm = document.getElementById('hub-dialog-confirm');

            const icon = options.icon || '❓';
            const confirmText = options.confirmText || 'Bestätigen';
            const cancelText = options.cancelText || 'Abbrechen';
            const confirmClass = options.confirmClass || 'btn-primary';

            if (iconEl) iconEl.textContent = icon;
            if (titleEl) titleEl.textContent = title;
            if (msgEl) msgEl.textContent = message;

            if (btnCancel) {
                btnCancel.classList.remove('hidden');
                btnCancel.className = 'btn btn-outline';
                btnCancel.style.flex = '1';
                btnCancel.textContent = cancelText;
            }
            if (btnConfirm) {
                btnConfirm.className = `btn ${confirmClass}`;
                btnConfirm.style.flex = '1';
                btnConfirm.textContent = confirmText;
            }

            const onCancel = () => {
                cleanup();
                resolve(false);
            };

            const onConfirm = () => {
                cleanup();
                resolve(true);
            };

            const cleanup = () => {
                overlay.classList.add('hidden');
                btnCancel?.removeEventListener('click', onCancel);
                btnConfirm?.removeEventListener('click', onConfirm);
            };

            btnCancel?.addEventListener('click', onCancel);
            btnConfirm?.addEventListener('click', onConfirm);
            overlay.classList.remove('hidden');
        });
    }
};

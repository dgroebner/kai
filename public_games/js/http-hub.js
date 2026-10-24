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

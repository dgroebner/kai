/**
 * labyrinth.js - Quarxl "Das verrückte Labyrinth" Client
 * Reines Rendering + Eingaben; die Spiellogik liegt vollständig auf dem Server.
 */
(function () {
    'use strict';

    const COLORS = ['yellow', 'red', 'green', 'blue'];
    const ARROW_GLYPHS = { TOP: '▼', BOTTOM: '▲', LEFT: '▶', RIGHT: '◀' };
    const MODE_DESC = {
        fast: 'Schnelle Runde: 3 Schätze pro Spieler',
        standard: 'Standard-Runde: 5 Schätze pro Spieler',
        classic: 'Klassisch: alle 24 Schätze werden verteilt',
    };

    const state = {
        roomCode: '',
        playerToken: '',
        isHost: false,
        data: null,
        spareSteps: 0,
        lastEventId: null,
        lastRenderKey: '',
        pollTimer: null,
        pollIntervalMs: 1500,
        isPolling: false,
        busy: false,
    };

    const el = (id) => document.getElementById(id);
    const safeColor = (c) => (COLORS.includes(c) ? c : 'yellow');

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        state.roomCode = (urlParams.get('room') || document.body.dataset.room || '').toUpperCase().trim();
        state.playerToken = localStorage.getItem('kai_gh_player_token') || '';

        bindStaticHandlers();
        initRoom();
    });

    // -------------------------------------------------------------------------
    // EVENTS (Delegation)
    // -------------------------------------------------------------------------
    function bindStaticHandlers() {
        document.addEventListener('click', (e) => {
            const target = e.target;
            if (target.closest('.js-btn-rules')) el('rules-modal').classList.remove('hidden');
            if (target.closest('.js-close-rules')) el('rules-modal').classList.add('hidden');
            if (target.closest('.js-btn-back-hub')) window.location.href = '../index.php';
            if (target.closest('.js-share-whatsapp')) shareWhatsapp();
            if (target.closest('.js-btn-edit-name')) editName();
            if (target.closest('.js-btn-confirm-join')) confirmJoin();
            if (target.closest('#btn-add-bot')) hostAction('add_bot');
            if (target.closest('#btn-start-game')) hostAction('start');
            if (target.closest('#btn-play-again')) hostAction('rematch');
            if (target.closest('#btn-rotate-left')) rotateSpare(-1);
            if (target.closest('#btn-rotate-right')) rotateSpare(1);
            if (target.closest('#btn-stay')) stayInPlace();

            const removeBtn = target.closest('.js-remove-bot');
            if (removeBtn) hostAction('remove_bot', { bot_id: parseInt(removeBtn.dataset.botId, 10) });

            const arrow = target.closest('.lb-arrow.is-active');
            if (arrow) shiftTile(arrow.dataset.side, parseInt(arrow.dataset.index, 10));

            const tile = target.closest('.lb-tile.is-reachable');
            if (tile) movePawn(parseInt(tile.dataset.r, 10), parseInt(tile.dataset.c, 10));
        });

        const diffSelect = el('select-bot-difficulty');
        if (diffSelect) {
            diffSelect.addEventListener('change', async () => {
                await hostAction('set_bot_difficulty', { bot_difficulty: diffSelect.value });
            });
        }
    }

    function shareWhatsapp() {
        const text = `Komm an meinen Labyrinth-Tisch auf Quarxl! Raum-Code: ${state.roomCode}\n${window.location.href}`;
        window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
    }

    async function editName() {
        const current = localStorage.getItem('kai_gh_player_name') || 'Ich';
        const next = await HubDialog.prompt('Spielername ändern', 'Dein neuer Spielername:', current);
        const trimmed = (next || '').trim().substring(0, 25);
        if (!trimmed || trimmed === current) return;

        const res = await api('update_name', { player_name: trimmed });
        if (res.ok && res.data?.success) {
            localStorage.setItem('kai_gh_player_name', trimmed);
            pollState();
        }
    }

    async function confirmJoin() {
        const errBox = el('join-error');
        const name = el('join-player-name')?.value.trim() || 'Gast';
        const res = await HubHttp.postJson('./api.php?action=join', { room_code: state.roomCode, player_name: name });

        if (res.ok && res.data?.success) {
            state.playerToken = res.data.player_token;
            localStorage.setItem('kai_gh_player_token', state.playerToken);
            localStorage.setItem('kai_gh_player_name', name);
            el('join-modal').classList.add('hidden');
            startPolling();
            pollState();
        } else if (errBox) {
            errBox.textContent = res.data?.message || 'Fehler beim Beitritt';
            errBox.classList.remove('hidden');
        }
    }

    async function hostAction(action, extra = {}) {
        const res = await api(action, extra);
        if (!(res.ok && res.data?.success)) {
            await HubDialog.alert('Aktion nicht möglich', res.data?.message || 'Unbekannter Fehler', '⚠️');
        }
        pollState(true);
    }

    function api(action, payload = {}) {
        return HubHttp.postJson(`./api.php?action=${action}`, { room_code: state.roomCode, ...payload });
    }

    // -------------------------------------------------------------------------
    // SPIELZÜGE
    // -------------------------------------------------------------------------
    function rotateSpare(direction) {
        state.spareSteps = (state.spareSteps + direction + 4) % 4;
        if (state.data) renderSide(state.data);
    }

    async function shiftTile(side, index) {
        if (state.busy || !state.data?.is_my_turn || state.data.phase !== 'shift') return;
        state.busy = true;
        const rotation = (state.data.spare.rot + state.spareSteps * 90) % 360;
        const res = await api('shift', { side, index, rotation });
        state.busy = false;
        if (res.ok && res.data?.success) {
            state.spareSteps = 0;
        } else {
            await HubDialog.alert('Einschub nicht möglich', res.data?.message || 'Fehler', '🚫');
        }
        pollState(true);
    }

    async function movePawn(row, col) {
        if (state.busy || !state.data?.is_my_turn || state.data.phase !== 'move') return;
        state.busy = true;
        const res = await api('move', { row, col });
        state.busy = false;
        if (!(res.ok && res.data?.success)) {
            await HubDialog.alert('Zug nicht möglich', res.data?.message || 'Fehler', '🚫');
        }
        pollState(true);
    }

    function stayInPlace() {
        const me = state.data?.players.find((p) => p.is_me);
        if (me?.pos) movePawn(me.pos[0], me.pos[1]);
    }

    // -------------------------------------------------------------------------
    // POLLING
    // -------------------------------------------------------------------------
    async function initRoom() {
        if (!state.roomCode) {
            window.location.href = '../index.php';
            return;
        }
        el('room-code-display').textContent = `RAUM: ${state.roomCode}`;
        const savedName = localStorage.getItem('kai_gh_player_name');
        if (savedName) {
            document.querySelectorAll('.js-my-name-display').forEach((n) => { n.textContent = savedName; });
        }

        if (!state.playerToken || !(await pollState(true))) {
            const nameInput = el('join-player-name');
            if (savedName && nameInput) nameInput.value = savedName;
            el('join-modal').classList.remove('hidden');
            return;
        }
        startPolling();
    }

    function startPolling(intervalMs = 1500) {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollIntervalMs = intervalMs;
        state.pollTimer = setInterval(() => pollState(), intervalMs);
    }

    async function pollState(force = false) {
        if (!state.roomCode || !state.playerToken || (state.isPolling && !force)) return false;
        state.isPolling = true;
        try {
            const res = await HubHttp.getJson(`./api.php?action=state&room=${encodeURIComponent(state.roomCode)}`);
            if (res.ok && res.data?.success) {
                renderGame(res.data.data);
                return true;
            }
            if (res.status === 401 || res.status === 404) {
                clearInterval(state.pollTimer);
                el('join-modal').classList.remove('hidden');
                return false;
            }
        } catch (err) {
            console.error('Labyrinth Poll Fehler:', err);
        } finally {
            state.isPolling = false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // RENDERING
    // -------------------------------------------------------------------------
    function renderGame(data) {
        const { recent_reactions: _ignored, ...stable } = data;
        const key = JSON.stringify(stable);
        if (key === state.lastRenderKey) return;
        state.lastRenderKey = key;

        const previous = state.data;
        state.data = data;

        const me = data.players.find((p) => p.is_me);
        if (me) {
            state.isHost = me.is_host;
            document.querySelectorAll('.js-my-name-display').forEach((n) => { n.textContent = me.player_name; });
        }

        const botTurn = data.status === 'active' && !data.is_my_turn && data.players.some((p) => p.is_active && p.is_bot);
        const wanted = botTurn ? 1000 : 1500;
        if (wanted !== state.pollIntervalMs) startPolling(wanted);

        const diffLabelsShort = {
            easy: '🌱 Leicht',
            normal: '⚔️ Normal',
            master: '🧠 Meister',
        };
        const diffBadge = data.players.some((p) => p.is_bot)
            ? ` • ${diffLabelsShort[data.bot_difficulty] || '⚔️ Normal'}`
            : '';
        el('game-mode-badge').textContent = `Labyrinth (${data.status === 'lobby' ? 'Lobby' : data.cards_per_player + ' Schätze'}${diffBadge})`;
        el('lobby-mode-desc').textContent = MODE_DESC[data.game_mode] || MODE_DESC.standard;

        const inLobby = data.status === 'lobby';
        el('pane-lobby').classList.toggle('hidden', !inLobby);
        el('pane-game').classList.toggle('hidden', inLobby);
        el('modal-winner').classList.toggle('hidden', data.status !== 'finished');

        if (inLobby) {
            state.spareSteps = 0;
            renderLobby(data);
            return;
        }

        const evt = data.last_event;
        const lastShift = data.last_shift;
        if (evt?.type === 'shift' || evt?.type === 'move') state.spareSteps = 0;
        if (evt?.message) el('event-ticker').textContent = evt.message;

        // Anzeige des letzten Einschubs im Banner
        const shiftPill = el('last-shift-pill');
        if (shiftPill) {
            if (lastShift) {
                const p = data.players.find((pl) => pl.id === lastShift.player_id);
                const dot = p ? `<span class="lb-dot lb-dot-sm lb-c-${safeColor(p.color)}"></span>` : '';
                const glyph = ARROW_GLYPHS[lastShift.side] || '▶';
                shiftPill.innerHTML = `${dot}<span>Letzter Einschub: <strong>${HubHtml.escape(lastShift.player_name)}</strong> schob ${HubHtml.escape(lastShift.where)} (${glyph})</span>`;
                shiftPill.classList.remove('hidden');
            } else {
                shiftPill.classList.add('hidden');
            }
        }

        renderPlayers(data);
        renderBoard(data);
        renderSide(data);

        const isNewShift = lastShift && lastShift.shift_id && lastShift.shift_id !== state.lastShiftId;
        if (isNewShift && previous) {
            flashShiftedLine(lastShift);
            state.lastShiftId = lastShift.shift_id;
        }
        state.lastEventId = evt ? evt.event_id : null;

        if (data.status === 'finished') renderWinner(data);
    }

    function renderLobby(data) {
        el('lobby-players-list').innerHTML = data.players.map((p, idx) => {
            const role = p.is_host ? '👑 Host' : (p.is_bot ? '🤖 Bot' : 'Spieler');
            const removeBtn = state.isHost && p.is_bot
                ? `<button type="button" class="btn btn-sm btn-outline js-remove-bot" data-bot-id="${p.id}" title="Bot entfernen">✕</button>`
                : '';
            return `
                <div class="lb-lobby-row">
                    <div class="lb-lobby-row-main">
                        <strong>#${idx + 1}</strong>
                        <span>${HubHtml.escape(p.player_name)} ${p.is_me ? '<strong>(Du)</strong>' : ''}</span>
                    </div>
                    <div class="lb-lobby-row-main"><span class="badge">${role}</span>${removeBtn}</div>
                </div>`;
        }).join('');

        el('btn-add-bot').classList.toggle('hidden', !state.isHost || data.players.length >= 4);
        el('btn-start-game').classList.toggle('hidden', !state.isHost);
        el('lobby-waiting-notice').classList.toggle('hidden', state.isHost);

        // Bot-Schwierigkeitsgrad Anzeige & Steuerung in der Lobby
        const diff = data.bot_difficulty || 'normal';
        const diffLabels = {
            easy: '🌱 Leicht (Gelegenheitsspieler)',
            normal: '⚔️ Normal (Ausgewogen)',
            master: '🧠 Meister (Gnadenlos)',
        };
        const diffSelect = el('select-bot-difficulty');
        if (diffSelect && document.activeElement !== diffSelect) {
            diffSelect.value = diff;
        }
        const guestDiff = el('guest-diff-display');
        if (guestDiff) {
            guestDiff.textContent = diffLabels[diff] || diff;
        }
        el('host-diff-controls')?.classList.toggle('hidden', !state.isHost);
        el('guest-diff-display')?.classList.toggle('hidden', state.isHost);
    }

    function renderPlayers(data) {
        el('players-bar').innerHTML = data.players.map((p) => {
            const done = p.collected.length;
            const total = done + p.remaining;
            const progress = p.returning_home ? '🏠 Rückweg!' : `${done}/${total} Schätze`;
            const found = p.collected.map((id) => HubHtml.escape(data.treasures[id]?.emoji || '')).join('');
            return `
                <div class="lb-player-chip ${p.is_active ? 'is-active' : ''} ${p.is_me ? 'is-me' : ''}">
                    <span class="lb-dot lb-c-${safeColor(p.color)}"></span>
                    <div class="lb-player-meta">
                        <span class="lb-player-name">${HubHtml.escape(p.player_name)}${p.is_me ? ' <span class="lb-me-pill">Du</span>' : ''}</span>
                        <span class="lb-player-progress">${progress} ${found}</span>
                    </div>
                </div>`;
        }).join('');
    }

    function tileHtml(tile, data, opts = {}) {
        const open = opts.steps ? tile.open.map((d) => (d + opts.steps) % 4) : tile.open;
        const arms = open.map((d) => `<span class="lb-arm d${d}"></span>`).join('');
        const treasure = tile.treasure && data.treasures[tile.treasure]
            ? `<span class="lb-treasure">${HubHtml.escape(data.treasures[tile.treasure].emoji)}</span>` : '';
        const classes = ['lb-tile'];
        if (tile.fixed) classes.push('is-fixed');
        if (tile.start) classes.push('is-start', `lb-c-${safeColor(tile.start)}`);
        if (opts.reachable) classes.push('is-reachable');
        if (opts.target) classes.push('is-target');
        if (opts.isShiftedLine) classes.push('is-shifted-line');
        if (opts.isNewTile) classes.push('is-new-tile');

        const pawnsList = opts.pawns || [];
        if (pawnsList.length > 0) classes.push('has-pawns');

        const pawnsHtml = pawnsList.map((p) => {
            const c = safeColor(p.color);
            const isMeClass = p.is_me ? 'is-me' : '';
            const star = p.is_me ? '<circle cx="12" cy="22" r="3.2" fill="#fff" stroke="#111" stroke-width="0.8"/>' : '';
            return `
                <svg viewBox="0 0 24 32" class="lb-pawn-fig lb-pawn-${c} ${isMeClass}" title="${HubHtml.escape(p.player_name)}">
                    <path d="M12 2 C8.7 2 6 4.7 6 8 C6 10.3 7.3 12.3 9.2 13.3 C7.5 15.5 5 21 4 26 C3.5 28.5 5.5 30 12 30 C18.5 30 20.5 28.5 20 26 C19 21 16.5 15.5 14.8 13.3 C16.7 12.3 18 10.3 18 8 C18 4.7 15.3 2 12 2 Z" fill="url(#lb-grad-${c})" stroke="#111" stroke-width="1.8" stroke-linejoin="round"/>
                    <ellipse cx="10" cy="6" rx="2.2" ry="1.3" fill="#fff" opacity="0.65"/>
                    ${star}
                </svg>`;
        }).join('');

        const multiClass = pawnsList.length > 1 ? ' is-multi' : '';
        const coords = opts.r === undefined ? '' : ` data-r="${opts.r}" data-c="${opts.c}"`;
        return `<div class="${classes.join(' ')}"${coords}>${arms}${treasure}<div class="lb-pawns${multiClass}">${pawnsHtml}</div></div>`;
    }

    function renderBoard(data) {
        const myTurnShift = data.is_my_turn && data.phase === 'shift';
        const reachable = new Set((data.reachable || []).map(([r, c]) => `${r},${c}`));
        const targetCell = data.my_target_cell ? `${data.my_target_cell[0]},${data.my_target_cell[1]}` : '';
        const lastShift = data.last_shift;
        const cells = [];

        for (let gr = 0; gr < 9; gr++) {
            for (let gc = 0; gc < 9; gc++) {
                const inner = gr >= 1 && gr <= 7 && gc >= 1 && gc <= 7;
                if (inner) {
                    const r = gr - 1;
                    const c = gc - 1;
                    const key = `${r},${c}`;
                    const pawns = data.players.filter((p) => p.pos && p.pos[0] === r && p.pos[1] === c);

                    let isShiftedLine = false;
                    let isNewTile = false;
                    if (lastShift) {
                        if ((lastShift.side === 'TOP' || lastShift.side === 'BOTTOM') && c === lastShift.index) {
                            isShiftedLine = true;
                            if (lastShift.side === 'TOP' && r === 0) isNewTile = true;
                            if (lastShift.side === 'BOTTOM' && r === 6) isNewTile = true;
                        } else if ((lastShift.side === 'LEFT' || lastShift.side === 'RIGHT') && r === lastShift.index) {
                            isShiftedLine = true;
                            if (lastShift.side === 'LEFT' && c === 0) isNewTile = true;
                            if (lastShift.side === 'RIGHT' && c === 6) isNewTile = true;
                        }
                    }

                    cells.push(tileHtml(data.board[r][c], data, {
                        r,
                        c,
                        pawns,
                        reachable: reachable.has(key),
                        target: key === targetCell,
                        isShiftedLine,
                        isNewTile,
                    }));
                    continue;
                }
                cells.push(arrowHtml(gr, gc, data, myTurnShift, lastShift));
            }
        }
        el('lb-board').innerHTML = cells.join('');
    }

    function arrowHtml(gr, gc, data, active, lastShift) {
        const evenCol = gc % 2 === 0 && gc >= 2 && gc <= 6;
        const evenRow = gr % 2 === 0 && gr >= 2 && gr <= 6;
        let side = null;
        let index = null;
        if (gr === 0 && evenCol) { side = 'TOP'; index = gc - 1; }
        else if (gr === 8 && evenCol) { side = 'BOTTOM'; index = gc - 1; }
        else if (gc === 0 && evenRow) { side = 'LEFT'; index = gr - 1; }
        else if (gc === 8 && evenRow) { side = 'RIGHT'; index = gr - 1; }
        if (!side) return '<div></div>';

        const forbidden = data.forbidden && data.forbidden.side === side && data.forbidden.index === index;
        const isLastEntry = lastShift && lastShift.side === side && lastShift.index === index;
        const classes = ['lb-arrow'];
        if (forbidden) classes.push('is-forbidden');
        if (isLastEntry) classes.push('is-last-entry');
        if (active && !forbidden) classes.push('is-active');

        let glyph = ARROW_GLYPHS[side];
        if (forbidden) glyph = '🚫';
        else if (isLastEntry) glyph = `✨${ARROW_GLYPHS[side]}`;

        const tip = isLastEntry ? 'Zuletzt hier eingeschoben' : (forbidden ? 'Gegenzug verboten' : 'Kachel einschieben');
        return `<div class="lb-arrow-slot"><button type="button" class="${classes.join(' ')}" data-side="${side}" data-index="${index}" title="${tip}">${glyph}</button></div>`;
    }

    function renderSide(data) {
        const target = el('my-target');
        if (data.my_target === 'home') {
            target.innerHTML = '<span class="lb-target-emoji">🏠</span><span class="lb-target-name">Zurück zum Startfeld!</span>';
        } else if (data.my_target && data.treasures[data.my_target]) {
            const t = data.treasures[data.my_target];
            target.innerHTML = `<span class="lb-target-emoji">${HubHtml.escape(t.emoji)}</span><span class="lb-target-name">${HubHtml.escape(t.name)}</span>`;
        } else {
            target.textContent = '–';
        }

        if (data.spare) {
            el('spare-holder').innerHTML = tileHtml(data.spare, data, { steps: state.spareSteps });
        }

        const badge = el('turn-status-badge');
        const guide = el('turn-guide-text');
        const active = data.players.find((p) => p.is_active);
        badge.classList.toggle('is-my-turn', !!data.is_my_turn);
        el('btn-stay').classList.toggle('hidden', !(data.is_my_turn && data.phase === 'move'));

        if (data.status === 'finished') {
            badge.textContent = '🏁 Beendet';
            guide.textContent = 'Das Spiel ist vorbei.';
        } else if (data.is_my_turn) {
            badge.textContent = '🟢 DU BIST AM ZUG';
            guide.textContent = data.phase === 'shift'
                ? 'Drehe die freie Kachel (↺/↻) und schiebe sie über einen Pfeil am Rand ein.'
                : 'Tippe auf ein grün markiertes Feld, um dorthin zu ziehen.';
        } else {
            badge.textContent = '⏳ Warte';
            guide.textContent = active ? `${active.player_name} ist am Zug...` : 'Warte auf den nächsten Zug...';
        }
    }

    function flashShiftedLine(shift) {
        if (!shift) return;
        const cells = [];
        for (let i = 0; i < 7; i++) {
            if (shift.side === 'TOP') cells.push([i, shift.index]);
            else if (shift.side === 'BOTTOM') cells.push([6 - i, shift.index]);
            else if (shift.side === 'LEFT') cells.push([shift.index, i]);
            else cells.push([shift.index, 6 - i]);
        }
        cells.forEach(([r, c], delayIdx) => {
            const tile = el('lb-board').querySelector(`.lb-tile[data-r="${r}"][data-c="${c}"]`);
            if (tile) {
                setTimeout(() => {
                    tile.classList.remove('is-flash');
                    void tile.offsetWidth;
                    tile.classList.add('is-flash');
                }, delayIdx * 55);
            }
        });
    }

    function renderWinner(data) {
        el('winner-message').textContent = `${data.winner_name} ist zurück am Startfeld und gewinnt das Labyrinth! 🏆`;
        el('btn-play-again').classList.toggle('hidden', !state.isHost);
        el('winner-wait-notice').classList.toggle('hidden', state.isHost);
    }
})();

/**
 * cards.js - Quarxl Kartenspiel-Arena (Uno, Uno Extreme, Mau-Mau)
 */
(function () {
    'use strict';

    const roomCode = document.body.dataset.room || '';
    let pollTimer = null;
    let lastStateVersion = 0;
    let isSubmitting = false;
    let pendingCardPlay = null; // Zwischenspeicher für Karte vor Farbwahl
    let unoCalledLocally = false;

    // Web Audio Synthesizer (Keine externen MP3-Dateien nötig)
    const AudioEngine = {
        ctx: null,
        init() {
            if (!this.ctx && (window.AudioContext || window.webkitAudioContext)) {
                this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            }
        },
        playCardSound() {
            this.init();
            if (!this.ctx) return;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(320, this.ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(140, this.ctx.currentTime + 0.08);
            gain.gain.setValueAtTime(0.25, this.ctx.currentTime);
            gain.gain.linearRampToValueAtTime(0.01, this.ctx.currentTime + 0.08);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start();
            osc.stop(this.ctx.currentTime + 0.09);
        },
        playUnoSound() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            [440, 660].forEach((freq, i) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, now + i * 0.12);
                gain.gain.setValueAtTime(0.3, now + i * 0.12);
                gain.gain.linearRampToValueAtTime(0.01, now + (i + 1) * 0.12);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + i * 0.12);
                osc.stop(now + (i + 1) * 0.13);
            });
        },
        playLauncherSound(cardsOut) {
            this.init();
            if (!this.ctx) return;
            // Motor-Surren
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(90, now);
            osc.frequency.linearRampToValueAtTime(220, now + 0.35);
            gain.gain.setValueAtTime(0.2, now);
            gain.gain.linearRampToValueAtTime(0.01, now + 0.35);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.36);

            // Auswurf-Klicks
            if (cardsOut > 0) {
                for (let k = 0; k < cardsOut; k++) {
                    const clickTime = now + 0.38 + (k * 0.08);
                    const cOsc = this.ctx.createOscillator();
                    const cGain = this.ctx.createGain();
                    cOsc.type = 'square';
                    cOsc.frequency.setValueAtTime(400 + (k * 50), clickTime);
                    cGain.gain.setValueAtTime(0.18, clickTime);
                    cGain.gain.linearRampToValueAtTime(0.01, clickTime + 0.05);
                    cOsc.connect(cGain);
                    cGain.connect(this.ctx.destination);
                    cOsc.start(clickTime);
                    cOsc.stop(clickTime + 0.06);
                }
            }
        },
        playVictorySound() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            [523.25, 659.25, 783.99, 1046.50].forEach((freq, i) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, now + i * 0.14);
                gain.gain.setValueAtTime(0.35, now + i * 0.14);
                gain.gain.linearRampToValueAtTime(0.01, now + i * 0.14 + 0.25);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + i * 0.14);
                osc.stop(now + i * 0.14 + 0.26);
            });
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        if (!roomCode) {
            window.location.href = '../index.php';
            return;
        }

        bindGlobalEvents();
        startPolling();
    });

    function bindGlobalEvents() {
        // WhatsApp Teilen
        document.querySelectorAll('.js-share-whatsapp').forEach(btn => {
            btn.addEventListener('click', () => {
                const url = window.location.href;
                const text = `Komm an den Kartentisch in Quarxl! 🃏 Raumcode: ${roomCode}\n${url}`;
                window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
            });
        });

        // Namen ändern
        document.querySelectorAll('.js-btn-edit-name').forEach(btn => {
            btn.addEventListener('click', async () => {
                const cur = localStorage.getItem('kai_gh_player_name') || 'Spieler';
                const newName = await HubDialog.prompt('Spielername anpassen', 'Wie möchtest du am Tisch heißen?', cur);
                if (newName && newName.trim() !== '') {
                    localStorage.setItem('kai_gh_player_name', newName.trim());
                    document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = newName.trim());
                }
            });
        });

        // Beitritt bestätigen (Modal)
        const joinBtn = document.querySelector('.js-btn-confirm-join');
        if (joinBtn) {
            joinBtn.addEventListener('click', handleJoin);
        }

        // Host: Bot hinzufügen
        const addBotBtn = document.getElementById('btn-add-bot');
        if (addBotBtn) {
            addBotBtn.addEventListener('click', async () => {
                const res = await HubHttp.postJson(`./api.php?action=add_bot&room=${encodeURIComponent(roomCode)}`);
                if (res.ok && res.data && res.data.success) {
                    fetchState(true);
                } else {
                    HubDialog.alert('Hinweis', res.data?.message || 'Konnte Bot nicht hinzufügen.', '🤖');
                }
            });
        }

        // Host: Spiel starten
        const startBtn = document.getElementById('btn-start-game');
        if (startBtn) {
            startBtn.addEventListener('click', async () => {
                const res = await HubHttp.postJson(`./api.php?action=start&room=${encodeURIComponent(roomCode)}`);
                if (res.ok && res.data && res.data.success) {
                    fetchState(true);
                } else {
                    HubDialog.alert('Hinweis', res.data?.message || 'Konnte Spiel nicht starten.', '⚠️');
                }
            });
        }

        // Uno-Ruf Button
        const unoBtn = document.getElementById('btn-uno-shout');
        if (unoBtn) {
            unoBtn.addEventListener('click', async () => {
                unoCalledLocally = true;
                unoBtn.classList.add('uno-active');
                AudioEngine.playUnoSound();
                await HubHttp.postJson(`./api.php?action=call_uno&room=${encodeURIComponent(roomCode)}`);
            });
        }

        // Nochmal spielen
        document.querySelectorAll('.js-btn-play-again').forEach(btn => {
            btn.addEventListener('click', () => {
                window.location.reload();
            });
        });
    }

    async function handleJoin() {
        const nameInput = document.getElementById('join-player-name');
        const errEl = document.getElementById('join-error');
        const name = (nameInput?.value || '').trim() || 'Gast';

        if (errEl) errEl.classList.add('hidden');

        const res = await HubHttp.postJson('./api.php?action=join', {
            room_code: roomCode,
            player_name: name
        });

        if (res.ok && res.data && res.data.success) {
            if (res.data.player_token) {
                localStorage.setItem('kai_gh_player_token', res.data.player_token);
            }
            localStorage.setItem('kai_gh_player_name', name);
            const modal = document.getElementById('join-modal');
            if (modal) modal.classList.add('hidden');
            fetchState(true);
        } else {
            if (errEl) {
                errEl.textContent = res.data?.message || 'Beitritt fehlgeschlagen.';
                errEl.classList.remove('hidden');
            }
        }
    }

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        fetchState(true);
        pollTimer = setInterval(() => fetchState(false), 1200);
    }

    async function fetchState(force = false) {
        if (isSubmitting) return;

        const res = await HubHttp.getJson(`./api.php?action=state&room=${encodeURIComponent(roomCode)}`);

        if (res.status === 401) {
            // Spieler noch nicht registriert -> Beitrittsmodal zeigen
            const modal = document.getElementById('join-modal');
            if (modal) {
                modal.classList.remove('hidden');
                const nameInput = document.getElementById('join-player-name');
                if (nameInput) {
                    nameInput.value = localStorage.getItem('kai_gh_player_name') || '';
                    nameInput.focus();
                }
            }
            return;
        }

        if (!res.ok || !res.data || !res.data.success) {
            return;
        }

        const data = res.data.data;

        // Versions-Prüfung für effizientes Re-Rendering
        if (!force && data.state_version === lastStateVersion) {
            return;
        }

        lastStateVersion = data.state_version;
        renderState(data);
    }

    function renderState(data) {
        // Modus-Badge
        const modeBadge = document.getElementById('game-mode-badge');
        if (modeBadge) {
            const modeNames = { 'uno': 'Uno Klassik', 'uno_extreme': 'Uno Extreme', 'mau_mau': 'Mau-Mau' };
            modeBadge.textContent = modeNames[data.game_mode] || 'Kartenspiel';
        }

        // Uno-Button Label anpassen
        const unoBtn = document.getElementById('btn-uno-shout');
        if (unoBtn) {
            unoBtn.textContent = (data.game_mode === 'mau_mau') ? 'MAU!' : 'UNO!';
        }

        // Eigener Name
        const myPlayer = data.players.find(p => p.is_me);
        if (myPlayer) {
            document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = myPlayer.name);
        }

        // Phasen umschalten
        const paneLobby = document.getElementById('pane-lobby');
        const paneGame = document.getElementById('pane-game');
        const paneFinished = document.getElementById('pane-finished');

        if (data.status === 'lobby') {
            if (paneLobby) paneLobby.classList.remove('hidden');
            if (paneGame) paneGame.classList.add('hidden');
            if (paneFinished) paneFinished.classList.add('hidden');
            renderLobby(data, myPlayer);
        } else if (data.status === 'active') {
            if (paneLobby) paneLobby.classList.add('hidden');
            if (paneGame) paneGame.classList.remove('hidden');
            if (paneFinished) paneFinished.classList.add('hidden');
            renderGameTable(data, myPlayer);
        } else if (data.status === 'finished') {
            if (paneLobby) paneLobby.classList.add('hidden');
            if (paneGame) paneGame.classList.add('hidden');
            if (paneFinished) paneFinished.classList.remove('hidden');
            renderFinished(data);
        }
    }

    function renderLobby(data, myPlayer) {
        const listEl = document.getElementById('lobby-players-list');
        if (!listEl) return;

        let html = '';
        data.players.forEach(p => {
            const hostBadge = p.is_host ? '<span style="color: #fbbf24; font-size: 0.75rem; font-weight: 700;">(Host)</span>' : '';
            const botBadge = p.is_bot ? '<span style="color: #38bdf8; font-size: 0.75rem;">🤖 KI</span>' : '';
            html += `
                <div class="lobby-player-row">
                    <div>
                        <strong>${HubHtml.escape(p.name)}</strong> ${hostBadge} ${botBadge}
                    </div>
                    <span style="color: #22c55e; font-size: 0.8rem;">● Bereit</span>
                </div>
            `;
        });
        listEl.innerHTML = html;

        // Host-Buttons steuern
        const isHost = myPlayer ? myPlayer.is_host : false;
        const addBotBtn = document.getElementById('btn-add-bot');
        const startBtn = document.getElementById('btn-start-game');
        const waitNotice = document.getElementById('lobby-waiting-notice');

        if (isHost) {
            if (addBotBtn) addBotBtn.classList.remove('hidden');
            if (startBtn) startBtn.classList.remove('hidden');
            if (waitNotice) waitNotice.classList.add('hidden');
        } else {
            if (addBotBtn) addBotBtn.classList.add('hidden');
            if (startBtn) startBtn.classList.add('hidden');
            if (waitNotice) waitNotice.classList.remove('hidden');
        }
    }

    function renderGameTable(data, myPlayer) {
        // Event-Ticker
        const ticker = document.getElementById('event-ticker-text');
        if (ticker && data.last_event) {
            ticker.textContent = data.last_event.message || 'Spiel läuft...';
        }

        // Gegner-Leiste
        const oppBar = document.getElementById('opponents-bar');
        if (oppBar) {
            const opponents = data.players.filter(p => !p.is_me);
            let oppHtml = '';
            opponents.forEach(op => {
                const turnClass = op.is_active ? 'active-turn' : '';
                const unoAlarm = (op.card_count === 1) ? `<span class="opponent-uno-alarm">${data.game_mode === 'mau_mau' ? 'MAU!' : 'UNO!'}</span>` : '';
                const avatar = op.is_bot ? '🤖' : '👤';
                oppHtml += `
                    <div class="opponent-slot ${turnClass}">
                        ${unoAlarm}
                        <div class="opponent-avatar">${avatar}</div>
                        <div class="opponent-name" title="${HubHtml.escape(op.name)}">${HubHtml.escape(op.name)}</div>
                        <div class="opponent-cards-badge">🃏 ${op.card_count}</div>
                    </div>
                `;
            });
            oppBar.innerHTML = oppHtml;
        }

        // Tischmitte: Ablagestapel (Oberste Karte)
        const discardCont = document.getElementById('discard-pile-container');
        if (discardCont && data.top_card) {
            discardCont.innerHTML = renderCardHtml(data.top_card, false, true);
        }

        // Tischmitte: Nachziehstapel / Launcher
        const drawCont = document.getElementById('draw-pile-container');
        if (drawCont) {
            if (data.game_mode === 'uno_extreme') {
                const lState = data.launcher_state || {};
                const firingClass = (lState.status === 'firing') ? 'launcher-firing' : '';
                drawCont.innerHTML = `
                    <div class="extreme-launcher ${firingClass}" id="extreme-launcher-btn" role="button" title="Uno Extreme Launcher betätigen">
                        <div class="launcher-led"></div>
                        <div class="launcher-mouth"></div>
                        <div class="launcher-button-label">PUSH</div>
                    </div>
                `;
                const launcherEl = document.getElementById('extreme-launcher-btn');
                if (launcherEl) {
                    launcherEl.addEventListener('click', handleDrawOrLaunch);
                }
            } else {
                drawCont.innerHTML = `
                    <div class="draw-deck-card" id="deck-draw-btn" role="button" title="Karte ziehen">
                        <span style="font-size: 1.5rem;">🂠</span>
                        <span class="draw-deck-count">${data.draw_pile_count}</span>
                    </div>
                `;
                const deckEl = document.getElementById('deck-draw-btn');
                if (deckEl) {
                    deckEl.addEventListener('click', handleDrawOrLaunch);
                }
            }
        }

        // Wunschfarben-Indikator
        const activeColorEl = document.getElementById('active-color-indicator');
        if (activeColorEl) {
            if (data.active_color) {
                const colorMap = {
                    'red': { label: 'Rot', bg: '#ef4444' },
                    'yellow': { label: 'Gelb', bg: '#ca8a04' },
                    'green': { label: 'Grün', bg: '#22c55e' },
                    'blue': { label: 'Blau', bg: '#3b82f6' },
                    'hearts': { label: 'Herz ♥', bg: '#dc2626' },
                    'diamonds': { label: 'Karo ♦', bg: '#f97316' },
                    'spades': { label: 'Pik ♠', bg: '#334155' },
                    'clubs': { label: 'Kreuz ♣', bg: '#1e293b' }
                };
                const cInfo = colorMap[data.active_color] || { label: data.active_color, bg: '#6366f1' };
                activeColorEl.innerHTML = `<span class="active-color-pill" style="background: ${cInfo.bg}; color: white;">Wunsch: ${cInfo.label}</span>`;
                activeColorEl.classList.remove('hidden');
            } else {
                activeColorEl.classList.add('hidden');
            }
        }

        // Mein Zug Status
        const isMyTurn = myPlayer ? myPlayer.is_active : false;
        const turnStatusEl = document.getElementById('my-turn-status');
        if (turnStatusEl) {
            if (isMyTurn) {
                turnStatusEl.innerHTML = '<span style="color: #22c55e; font-size: 0.95rem;">👉 Du bist am Zug!</span>';
            } else {
                const curPlayer = data.players.find(p => p.is_active);
                const curName = curPlayer ? curPlayer.name : 'jemand anderes';
                turnStatusEl.innerHTML = `Warte auf: <strong>${HubHtml.escape(curName)}</strong>`;
            }
        }

        // Uno-Button Pulsieren wenn 1 oder 2 Karten
        const unoBtn = document.getElementById('btn-uno-shout');
        if (unoBtn && myPlayer) {
            const handCount = myPlayer.card_count || 0;
            if (handCount <= 2 && !myPlayer.has_called_uno && !unoCalledLocally) {
                unoBtn.style.animation = 'bounce 0.8s infinite';
            } else {
                unoBtn.style.animation = 'none';
            }
        }

        // Eigene Handkarten rendern
        const fannedCont = document.getElementById('player-hand-fanned');
        if (fannedCont && myPlayer && Array.isArray(myPlayer.cards)) {
            let cardsHtml = '';
            myPlayer.cards.forEach(c => {
                cardsHtml += renderCardHtml(c, c.can_play, false);
            });
            fannedCont.innerHTML = cardsHtml;

            // Klick-Events an spielbare Karten binden
            fannedCont.querySelectorAll('.game-card.can-play').forEach(cardEl => {
                cardEl.addEventListener('click', () => {
                    const cardId = cardEl.dataset.cardId;
                    const cardType = cardEl.dataset.cardType;
                    const card = myPlayer.cards.find(x => x.id === cardId);
                    if (card) {
                        onCardClicked(card, data);
                    }
                });
            });
        }
    }

    function renderCardHtml(card, canPlay, isTopDiscard) {
        const canPlayClass = canPlay ? 'can-play' : '';
        const topClass = isTopDiscard ? 'top-discard-card' : '';
        const colorClass = `card-${card.color}`;
        const label = HubHtml.escape(card.label);

        return `
            <div class="game-card ${colorClass} ${canPlayClass} ${topClass}" 
                 data-card-id="${card.id}" 
                 data-card-type="${card.type}"
                 data-card-color="${card.color}">
                <div class="card-corner-top">${label}</div>
                <div class="card-center-symbol">${label}</div>
                <div class="card-corner-bottom">${label}</div>
            </div>
        `;
    }

    function onCardClicked(card, data) {
        // Benötigt die Karte eine Farbwahl?
        const needsColor = (card.type === 'wild' || card.type === 'wild_draw4' || card.type === 'extreme_hit' || card.type === 'jack');

        if (needsColor) {
            pendingCardPlay = card;
            openColorChoiceModal(data.game_mode);
        } else {
            executePlayCard(card.id, null);
        }
    }

    function openColorChoiceModal(gameMode) {
        const modal = document.getElementById('modal-color-choice');
        const grid = document.getElementById('color-choice-container');
        if (!modal || !grid) return;

        let colors = [];
        if (gameMode === 'mau_mau') {
            colors = [
                { id: 'hearts', label: 'Herz ♥', bg: '#ef4444' },
                { id: 'diamonds', label: 'Karo ♦', bg: '#f97316' },
                { id: 'spades', label: 'Pik ♠', bg: '#334155' },
                { id: 'clubs', label: 'Kreuz ♣', bg: '#0f172a' },
            ];
        } else {
            colors = [
                { id: 'red', label: 'Rot', bg: '#ef4444' },
                { id: 'yellow', label: 'Gelb', bg: '#ca8a04' },
                { id: 'green', label: 'Grün', bg: '#22c55e' },
                { id: 'blue', label: 'Blau', bg: '#3b82f6' },
            ];
        }

        let html = '';
        colors.forEach(c => {
            html += `
                <button type="button" class="color-choice-btn" data-color="${c.id}" style="background: ${c.bg};">
                    ${c.label}
                </button>
            `;
        });
        grid.innerHTML = html;

        grid.querySelectorAll('.color-choice-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const chosen = btn.dataset.color;
                modal.classList.add('hidden');
                if (pendingCardPlay) {
                    executePlayCard(pendingCardPlay.id, chosen);
                    pendingCardPlay = null;
                }
            });
        });

        modal.classList.remove('hidden');
    }

    async function executePlayCard(cardId, chosenColor) {
        if (isSubmitting) return;
        isSubmitting = true;

        AudioEngine.playCardSound();

        const res = await HubHttp.postJson(`./api.php?action=play_card&room=${encodeURIComponent(roomCode)}`, {
            card_id: cardId,
            chosen_color: chosenColor,
            called_uno: unoCalledLocally
        });

        isSubmitting = false;

        if (res.ok && res.data && res.data.success) {
            unoCalledLocally = false;
            fetchState(true);
        } else {
            HubDialog.alert('Zug ungültig', res.data?.message || 'Diese Karte kann nicht gelegt werden.', '⚠️');
            fetchState(true);
        }
    }

    async function handleDrawOrLaunch() {
        if (isSubmitting) return;
        isSubmitting = true;

        const res = await HubHttp.postJson(`./api.php?action=draw&room=${encodeURIComponent(roomCode)}`);
        isSubmitting = false;

        if (res.ok && res.data && res.data.success) {
            const count = res.data.drawn_count || 0;
            AudioEngine.playLauncherSound(count);
            unoCalledLocally = false;
            fetchState(true);
        } else {
            HubDialog.alert('Hinweis', res.data?.message || 'Aktion nicht möglich.', '⚠️');
        }
    }

    function renderFinished(data) {
        const winner = data.players.find(p => p.id === data.winner_id);
        const nameEl = document.getElementById('winner-name-display');
        const descEl = document.getElementById('winner-desc');

        if (nameEl) {
            nameEl.textContent = winner ? `${winner.name} hat gewonnen!` : 'Spiel beendet!';
        }
        if (descEl) {
            descEl.textContent = 'Herzlichen Glückwunsch! Alle Karten wurden meisterhaft abgelegt.';
        }

        AudioEngine.playVictorySound();
    }
})();

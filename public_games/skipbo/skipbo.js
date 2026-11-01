/**
 * skipbo.js - Quarxl Skip-Bo Spielfeld & Originalkarten Client
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // SOUND-EFFEKTE (Web Audio API Synthesizer - keine externen MP3s nötig)
    // -------------------------------------------------------------------------
    const SoundFx = {
        ctx: null,
        init() {
            if (!this.ctx) {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (AudioContext) {
                    this.ctx = new AudioContext();
                }
            }
            if (this.ctx && this.ctx.state === 'suspended') {
                this.ctx.resume();
            }
        },
        playCard() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(420, now);
            osc.frequency.exponentialRampToValueAtTime(120, now + 0.08);
            gain.gain.setValueAtTime(0.3, now);
            gain.gain.exponentialRampToValueAtTime(0.01, now + 0.08);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.08);
        },
        pileCompleted() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            // 3 aufsteigende Töne für vollendeten 12er-Stapel
            [523.25, 659.25, 783.99, 1046.5].forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                const start = now + (idx * 0.08);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, start);
                gain.gain.setValueAtTime(0.3, start);
                gain.gain.exponentialRampToValueAtTime(0.01, start + 0.2);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(start);
                osc.stop(start + 0.2);
            });
        },
        gameWon() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            [440, 554.37, 659.25, 880].forEach((freq, idx) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                const start = now + (idx * 0.12);
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, start);
                gain.gain.setValueAtTime(0.4, start);
                gain.gain.exponentialRampToValueAtTime(0.01, start + 0.4);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(start);
                osc.stop(start + 0.4);
            });
        },
        error() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(140, now);
            gain.gain.setValueAtTime(0.2, now);
            gain.gain.exponentialRampToValueAtTime(0.01, now + 0.15);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.15);
        }
    };

    // -------------------------------------------------------------------------
    // APP-ZUSTAND
    // -------------------------------------------------------------------------
    const state = {
        roomCode: '',
        playerToken: '',
        myPlayerId: null,
        isHost: false,
        gameStatus: 'lobby',
        selectedCard: null, // { source: 'hand'|'stock'|'discard', sourceIndex: ..., card: ..., playableTo: [] }
        lastServerState: null,
        pollTimer: null,
        isPolling: false,
    };

    document.addEventListener('DOMContentLoaded', () => {
        const bodyRoom = document.body.dataset.room || '';
        const urlParams = new URLSearchParams(window.location.search);
        state.roomCode = (urlParams.get('room') || bodyRoom).toUpperCase().trim();
        state.playerToken = localStorage.getItem('kai_gh_player_token') || '';

        // UI-Elemente
        const paneLobby = document.getElementById('pane-lobby');
        const paneGame = document.getElementById('pane-game');
        const joinModal = document.getElementById('join-modal');
        const rulesModal = document.getElementById('rules-modal');
        const winnerModal = document.getElementById('modal-winner');

        // Regeln Modal Trigger
        document.querySelectorAll('.js-btn-rules').forEach(btn => {
            btn.addEventListener('click', () => {
                rulesModal.classList.remove('hidden');
            });
        });
        document.querySelectorAll('.js-close-rules').forEach(btn => {
            btn.addEventListener('click', () => {
                rulesModal.classList.add('hidden');
            });
        });

        // WhatsApp Share
        document.querySelectorAll('.js-share-whatsapp').forEach(btn => {
            btn.addEventListener('click', () => {
                const url = window.location.href;
                const text = `Komm an meinen Skip-Bo Tisch auf Quarxl! Raum-Code: ${state.roomCode}\n${url}`;
                window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
            });
        });

        // Name ändern
        document.querySelectorAll('.js-btn-edit-name').forEach(btn => {
            btn.addEventListener('click', async () => {
                const curName = localStorage.getItem('kai_gh_player_name') || 'Ich';
                const newName = prompt('Dein neuer Spielername:', curName);
                if (newName && newName.trim() && newName.trim() !== curName) {
                    const trimmed = newName.trim().substring(0, 25);
                    const res = await HubHttp.postJson('./api.php?action=update_name', {
                        room_code: state.roomCode,
                        player_name: trimmed,
                    });
                    if (res.ok && res.data && res.data.success) {
                        localStorage.setItem('kai_gh_player_name', trimmed);
                        document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = trimmed);
                        pollState();
                    }
                }
            });
        });

        // Hub Back Buttons
        document.querySelectorAll('.js-btn-back-hub').forEach(btn => {
            btn.addEventListener('click', () => {
                window.location.href = '../index.php';
            });
        });

        // Beitritts-Handler
        const btnConfirmJoin = document.querySelector('.js-btn-confirm-join');
        if (btnConfirmJoin) {
            btnConfirmJoin.addEventListener('click', async () => {
                const nameInput = document.getElementById('join-player-name');
                const errBox = document.getElementById('join-error');
                const name = nameInput?.value.trim() || 'Gast';

                const res = await HubHttp.postJson('./api.php?action=join', {
                    room_code: state.roomCode,
                    player_name: name,
                });

                if (res.ok && res.data && res.data.success) {
                    state.playerToken = res.data.player_token;
                    state.myPlayerId = res.data.player_id;
                    state.isHost = !!res.data.is_host;
                    localStorage.setItem('kai_gh_player_token', state.playerToken);
                    localStorage.setItem('kai_gh_player_name', name);
                    joinModal.classList.add('hidden');
                    startPolling();
                } else {
                    if (errBox) {
                        errBox.textContent = res.data?.message || 'Fehler beim Beitritt';
                        errBox.classList.remove('hidden');
                    }
                }
            });
        }

        // Host: Bot hinzufügen
        const btnAddBot = document.getElementById('btn-add-bot');
        if (btnAddBot) {
            btnAddBot.addEventListener('click', async () => {
                const res = await HubHttp.postJson('./api.php?action=add_bot', { room_code: state.roomCode });
                if (res.ok && res.data && res.data.success) {
                    pollState();
                } else {
                    alert(res.data?.message || 'Bot konnte nicht hinzugefügt werden');
                }
            });
        }

        // Host: Spiel starten
        const btnStart = document.getElementById('btn-start-game');
        if (btnStart) {
            btnStart.addEventListener('click', async () => {
                const res = await HubHttp.postJson('./api.php?action=start', { room_code: state.roomCode });
                if (res.ok && res.data && res.data.success) {
                    pollState();
                } else {
                    alert(res.data?.message || 'Spiel konnte nicht gestartet werden');
                }
            });
        }

        // Klick auf Baustapel (Target Drop-Zone für Spielzüge)
        document.querySelectorAll('.js-building-slot').forEach(slot => {
            slot.addEventListener('click', () => {
                const pileIdx = parseInt(slot.dataset.pile, 10);
                handleBuildingPileClick(pileIdx);
            });
        });

        // Klick auf eigene Hilfsstapel (Discard Drop-Zone zum Beenden des Zuges)
        document.querySelectorAll('.js-my-discard-slot').forEach(slot => {
            slot.addEventListener('click', () => {
                const discardIdx = parseInt(slot.dataset.discard, 10);
                handleDiscardPileClick(discardIdx);
            });
        });

        // Klick auf eigenen Zielstapel
        const myStockBox = document.getElementById('my-stockpile-box');
        if (myStockBox) {
            myStockBox.addEventListener('click', () => {
                handleStockpileClick();
            });
        }

        // Initialisierung starten
        initRoom();
    });

    async function initRoom() {
        if (!state.roomCode) {
            window.location.href = '../index.php';
            return;
        }

        document.getElementById('room-code-display').textContent = `RAUM: ${state.roomCode}`;

        // Gespeicherten Spielernamen anzeigen
        const savedName = localStorage.getItem('kai_gh_player_name');
        if (savedName) {
            document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = savedName);
        }

        if (!state.playerToken) {
            // Beitritts-Modal anzeigen
            const joinModal = document.getElementById('join-modal');
            const nameInput = document.getElementById('join-player-name');
            if (savedName && nameInput) nameInput.value = savedName;
            joinModal.classList.remove('hidden');
            return;
        }

        // Zustand abfragen
        const ok = await pollState();
        if (!ok) {
            // Token evtl. abgelaufen oder Raum nicht gefunden -> Beitritt anfordern
            const joinModal = document.getElementById('join-modal');
            joinModal.classList.remove('hidden');
        } else {
            startPolling();
        }
    }

    function startPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(pollState, 1500);
    }

    async function pollState() {
        if (state.isPolling || !state.roomCode || !state.playerToken) return false;
        state.isPolling = true;

        try {
            const res = await HubHttp.getJson(`./api.php?action=state&room=${encodeURIComponent(state.roomCode)}`);
            if (res.ok && res.data && res.data.success) {
                renderGame(res.data.data);
                return true;
            } else if (res.status === 401 || res.status === 404) {
                clearInterval(state.pollTimer);
                document.getElementById('join-modal')?.classList.remove('hidden');
                return false;
            }
        } catch (e) {
            console.error('Skip-Bo Poll Fehler:', e);
        } finally {
            state.isPolling = false;
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // RENDERING DES SPIELZUSTANDS
    // -------------------------------------------------------------------------
    function renderGame(data) {
        state.lastServerState = data;
        state.gameStatus = data.status;

        // Spielmodus-Badge
        const modeDesc = data.stock_size === 10 ? 'Schnell (10 Zielkarten)' : (data.stock_size === 30 ? 'Klassisch (30 Zielkarten)' : 'Standard (20 Zielkarten)');
        document.getElementById('game-mode-badge').textContent = `Skip-Bo (${data.stock_size} Karten)`;
        const lobbyDesc = document.getElementById('lobby-mode-desc');
        if (lobbyDesc) lobbyDesc.textContent = modeDesc;

        // Ticker & Events
        if (data.last_event && data.last_event.message) {
            document.getElementById('event-ticker').textContent = data.last_event.message;
        }

        // Eigene Spielerdaten finden
        const myPlayer = data.players.find(p => p.is_me);
        if (myPlayer) {
            state.myPlayerId = myPlayer.id;
            state.isHost = myPlayer.is_host;
            document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = myPlayer.player_name);
        }

        // LOBBY ODER SPIELTISCH ANZEIGEN
        const paneLobby = document.getElementById('pane-lobby');
        const paneGame = document.getElementById('pane-game');

        if (data.status === 'lobby') {
            paneLobby.classList.remove('hidden');
            paneGame.classList.add('hidden');
            renderLobby(data);
        } else {
            paneLobby.classList.add('hidden');
            paneGame.classList.remove('hidden');
            renderTable(data, myPlayer);
        }

        // Gewinner prüfen
        if (data.status === 'finished' && data.winner_name) {
            SoundFx.gameWon();
            const winModal = document.getElementById('modal-winner');
            const winMsg = document.getElementById('winner-message');
            if (winMsg) {
                winMsg.textContent = `${data.winner_name} hat alle Zielkarten abgebaut und gewinnt Skip-Bo! 🏆`;
            }
            winModal.classList.remove('hidden');
        }
    }

    function renderLobby(data) {
        const list = document.getElementById('lobby-players-list');
        list.innerHTML = '';

        data.players.forEach((p, idx) => {
            const row = document.createElement('div');
            row.className = 'lobby-player-row';
            const roleBadge = p.is_host ? '👑 Host' : (p.is_bot ? '🤖 Bot' : 'Spieler');
            row.innerHTML = `
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span style="font-weight: 700;">#${idx + 1}</span>
                    <span>${escapeHtml(p.player_name)} ${p.is_me ? '<strong>(Du)</strong>' : ''}</span>
                </div>
                <span class="badge ${p.is_host ? 'badge-primary' : ''}">${roleBadge}</span>
            `;
            list.appendChild(row);
        });

        // Host-Buttons steuern
        const btnBot = document.getElementById('btn-add-bot');
        const btnStart = document.getElementById('btn-start-game');
        const notice = document.getElementById('lobby-waiting-notice');

        if (state.isHost) {
            btnBot?.classList.remove('hidden');
            btnStart?.classList.remove('hidden');
            if (notice) notice.classList.add('hidden');
        } else {
            btnBot?.classList.add('hidden');
            btnStart?.classList.add('hidden');
            if (notice) notice.classList.remove('hidden');
        }
    }

    function renderTable(data, myPlayer) {
        const isMyTurn = !!data.is_my_turn;

        // 1. Zug-Leitfaden Text
        const guideText = document.getElementById('turn-guide-text');
        const guideBar = document.getElementById('turn-guide-bar');
        if (guideText && guideBar) {
            if (isMyTurn) {
                guideBar.style.borderColor = '#10b981';
                guideBar.style.color = '#34d399';
                if (!state.selectedCard) {
                    guideText.textContent = '🌟 Du bist am Zug! Wähle eine grün leuchtende Karte zum Legen, oder wähle eine Handkarte zum Abwerfen.';
                } else {
                    if (state.selectedCard.source === 'hand') {
                        guideText.textContent = '🎯 Lege die Karte auf einen passenden Baustapel – ODER wirf sie auf einen Hilfsstapel ab, um deinen Zug zu beenden.';
                    } else {
                        guideText.textContent = '🎯 Wähle einen leuchtenden Baustapel, um die Karte anzulegen.';
                    }
                }
            } else {
                guideBar.style.borderColor = 'rgba(255, 255, 255, 0.1)';
                guideBar.style.color = '#94a3b8';
                const activePlayer = data.players.find(p => p.is_active);
                guideText.textContent = `Warte auf ${activePlayer ? activePlayer.player_name : 'Gegner'}...`;
                state.selectedCard = null; // Auswahl zurücksetzen
            }
        }

        // 2. Gegner-Leiste (Opponents Bar)
        renderOpponents(data.players.filter(p => !p.is_me));

        // 3. Nachziehstapel-Zähler
        const drawCount = document.getElementById('draw-pile-count');
        if (drawCount) drawCount.textContent = data.draw_pile_count;

        // 4. Die 4 Baustapel (Tischmitte)
        renderBuildingPiles(data.building_piles, isMyTurn);

        // 5. Mein Zielstapel (Stockpile)
        if (myPlayer) {
            renderMyStockpile(myPlayer.stock_top_card, myPlayer.stock_count, isMyTurn);

            // 6. Meine 4 Hilfsstapel (Discard Piles)
            renderMyDiscardPiles(myPlayer.discard_piles, isMyTurn);

            // 7. Meine Handkarten
            renderMyHand(myPlayer.hand || [], isMyTurn);
        }
    }

    function renderOpponents(opponents) {
        const container = document.getElementById('opponents-container');
        container.innerHTML = '';

        opponents.forEach(opp => {
            const slot = document.createElement('div');
            slot.className = `opponent-slot ${opp.is_active ? 'active-turn' : ''}`;

            // Miniatur-Zielstapel
            let stockHtml = '';
            if (opp.stock_top_card) {
                stockHtml = renderCardHtml(opp.stock_top_card, { mini: true });
            } else {
                stockHtml = `<div class="skipbo-deck-back" style="width:46px;height:68px;border-width:2px;"><span style="font-size:0.6rem;font-weight:900;">0</span></div>`;
            }

            // Miniatur-Hilfsstapel
            let discardHtml = '';
            (opp.discard_piles || []).forEach(dp => {
                if (dp.top_card) {
                    discardHtml += `<div style="display:inline-block;margin:0 1px;">${renderCardHtml(dp.top_card, { mini: true })}</div>`;
                } else {
                    discardHtml += `<div style="display:inline-block;width:34px;height:50px;border:1px dashed rgba(255,255,255,0.2);border-radius:6px;margin:0 1px;"></div>`;
                }
            });

            slot.innerHTML = `
                <div class="opponent-info">
                    <span class="opponent-name">${opp.is_bot ? '🤖 ' : ''}${escapeHtml(opp.player_name)}</span>
                    <span class="opponent-hand-badge">🃏 Hand: ${opp.hand_count}</span>
                    <span style="font-size:0.75rem;color:#fbbf24;font-weight:800;margin-top:2px;">🎯 Ziel: ${opp.stock_count}</span>
                </div>
                <div class="opponent-mini-card-wrap">
                    ${stockHtml}
                </div>
                <div style="display:flex;align-items:center;">
                    ${discardHtml}
                </div>
            `;
            container.appendChild(slot);
        });
    }

    function renderBuildingPiles(piles, isMyTurn) {
        piles.forEach((pile, idx) => {
            const slot = document.getElementById(`building-pile-${idx}`);
            if (!slot) return;

            const neededNumSpan = slot.querySelector('.skipbo-slot-needed-num');
            const targetContainer = slot.querySelector('.js-slot-card-target');

            const needed = pile.needed_value;
            if (neededNumSpan) {
                neededNumSpan.textContent = needed > 12 ? '✓' : needed;
            }

            // Top-Karte anzeigen
            targetContainer.innerHTML = '';
            if (pile.top_card) {
                targetContainer.innerHTML = renderCardHtml(pile.top_card);
            }

            // Highlight für legale Zielstapel wenn Karte selektiert ist
            const isHighlight = isMyTurn && state.selectedCard && (state.selectedCard.playableTo || []).includes(idx);
            slot.classList.toggle('target-highlight', !!isHighlight);
        });
    }

    function renderMyStockpile(topCard, count, isMyTurn) {
        const slot = document.getElementById('my-stock-card-slot');
        const countBadge = document.getElementById('my-stock-count-badge');
        const box = document.getElementById('my-stockpile-box');

        if (countBadge) countBadge.textContent = `Ziel: Noch ${count}`;

        slot.innerHTML = '';
        if (topCard) {
            const isPlayable = isMyTurn && (topCard.can_play_to || []).length > 0;
            const isSelected = state.selectedCard && state.selectedCard.source === 'stock';

            slot.innerHTML = renderCardHtml(topCard, {
                playable: isPlayable,
                selected: isSelected,
            });
        }
    }

    function renderMyDiscardPiles(discardPiles, isMyTurn) {
        const isDiscardingActive = isMyTurn && state.selectedCard && state.selectedCard.source === 'hand';

        discardPiles.forEach((dp, idx) => {
            const slot = document.getElementById(`my-discard-slot-${idx}`);
            if (!slot) return;

            const target = slot.querySelector('.js-discard-card-target');
            const countBadge = slot.querySelector('.js-discard-count');

            if (countBadge) countBadge.textContent = dp.count;

            target.innerHTML = '';
            if (dp.top_card) {
                const isPlayable = isMyTurn && (dp.top_card.can_play_to || []).length > 0;
                const isSelected = state.selectedCard && state.selectedCard.source === 'discard' && state.selectedCard.sourceIndex === idx;

                target.innerHTML = renderCardHtml(dp.top_card, {
                    playable: isPlayable,
                    selected: isSelected,
                });
            }

            // Wenn eine Handkarte gewählt ist, leuchten alle 4 Hilfsstapel als Dropzone auf
            slot.classList.toggle('discard-target-active', !!isDiscardingActive);
        });
    }

    function renderMyHand(handCards, isMyTurn) {
        const rack = document.getElementById('my-hand-cards-rack');
        const countIndicator = document.getElementById('hand-count-indicator');
        if (countIndicator) countIndicator.textContent = `${handCards.length} / 5`;

        rack.innerHTML = '';

        handCards.forEach((card, idx) => {
            const isPlayable = isMyTurn && (card.can_play_to || []).length > 0;
            const isSelected = state.selectedCard && state.selectedCard.source === 'hand' && state.selectedCard.card.id === card.id;

            const cardEl = createCardElement(card, {
                playable: isPlayable,
                selected: isSelected,
            });

            cardEl.addEventListener('click', () => {
                handleHandCardClick(card, idx);
            });

            rack.appendChild(cardEl);
        });
    }

    // -------------------------------------------------------------------------
    // KARTEN HTML & ELEMENT GENERIERUNG
    // -------------------------------------------------------------------------
    function renderCardHtml(card, opts = {}) {
        const isMini = !!opts.mini;
        const isPlayable = !!opts.playable;
        const isSelected = !!opts.selected;

        const isSkipBo = card.type === 'skipbo';
        const colorClass = `card-color-${card.color || 'blue'}`;
        const scaleStyle = isMini ? 'width:46px;height:68px;border-width:2px;padding:0.15rem;' : '';

        if (isSkipBo) {
            const cornerSize = isMini ? 'font-size:0.5rem;' : '';
            return `
                <div class="skipbo-card ${colorClass} ${isPlayable ? 'is-playable' : ''} ${isSelected ? 'is-selected' : ''}" style="${scaleStyle}">
                    <div class="card-corner card-corner-top-left" style="${cornerSize}">
                        <span class="card-corner-wild-text">SKIP</span>
                    </div>
                    <div class="card-center-skipbo-banner" style="${isMini ? 'transform:translate(-50%,-50%) scale(0.65);' : ''}">
                        <span class="skipbo-logo-text">SKIP-BO</span>
                        <span class="skipbo-star-icon">★</span>
                    </div>
                    <div class="card-corner card-corner-bottom-right" style="${cornerSize}">
                        <span class="card-corner-wild-text">BO</span>
                    </div>
                </div>
            `;
        }

        // Zahlenkarte 1 bis 12
        const textColorClass = `text-color-${card.color || 'blue'}`;
        const val = card.played_as || card.value;
        const cornerSize = isMini ? 'font-size:0.65rem;' : '';
        const medSize = isMini ? 'transform:translate(-50%,-50%) scale(0.65);' : '';

        return `
            <div class="skipbo-card ${colorClass} ${isPlayable ? 'is-playable' : ''} ${isSelected ? 'is-selected' : ''}" style="${scaleStyle}">
                <div class="card-corner card-corner-top-left" style="${cornerSize}">${val}</div>
                <div class="card-center-medallion" style="${medSize}">
                    <span class="medallion-num ${textColorClass}">${val}</span>
                </div>
                <div class="card-corner card-corner-bottom-right" style="${cornerSize}">${val}</div>
            </div>
        `;
    }

    function createCardElement(card, opts = {}) {
        const wrap = document.createElement('div');
        wrap.innerHTML = renderCardHtml(card, opts).trim();
        return wrap.firstElementChild;
    }

    // -------------------------------------------------------------------------
    // INTERAKTIONS-LOGIK (KLICKS & ZÜGE)
    // -------------------------------------------------------------------------

    // 1. Handkarte angeklickt
    function handleHandCardClick(card, idx) {
        if (!state.lastServerState || !state.lastServerState.is_my_turn) return;

        // Falls dieselbe Karte nochmals angetippt wird -> deselektieren
        if (state.selectedCard && state.selectedCard.source === 'hand' && state.selectedCard.card.id === card.id) {
            state.selectedCard = null;
            renderGame(state.lastServerState);
            return;
        }

        state.selectedCard = {
            source: 'hand',
            sourceIndex: card.id,
            card: card,
            playableTo: card.can_play_to || [],
        };

        SoundFx.playCard();
        renderGame(state.lastServerState);
    }

    // 2. Zielstapel-Karte angeklickt
    function handleStockpileClick() {
        if (!state.lastServerState || !state.lastServerState.is_my_turn) return;

        const myPlayer = state.lastServerState.players.find(p => p.is_me);
        if (!myPlayer || !myPlayer.stock_top_card) return;

        const card = myPlayer.stock_top_card;
        if (!card.can_play_to || card.can_play_to.length === 0) {
            SoundFx.error();
            return; // Nicht spielbar
        }

        // Falls bereits gewählt -> deselektieren
        if (state.selectedCard && state.selectedCard.source === 'stock') {
            state.selectedCard = null;
            renderGame(state.lastServerState);
            return;
        }

        // Auto-Play Komfort: Wenn nur genau ein Baustapel in Frage kommt, direkt dorthin legen!
        if (card.can_play_to.length === 1) {
            executePlayCard('stock', 0, card.can_play_to[0]);
            return;
        }

        state.selectedCard = {
            source: 'stock',
            sourceIndex: 0,
            card: card,
            playableTo: card.can_play_to,
        };

        SoundFx.playCard();
        renderGame(state.lastServerState);
    }

    // 3. Eigener Hilfsstapel angeklickt
    function handleDiscardPileClick(discardIdx) {
        if (!state.lastServerState || !state.lastServerState.is_my_turn) return;

        // FALL A: Es ist eine HANDKARTE gewählt -> diese auf den Hilfsstapel abwerfen (ZUG BEENDEN)!
        if (state.selectedCard && state.selectedCard.source === 'hand') {
            executeDiscardCard(state.selectedCard.card.id, discardIdx);
            return;
        }

        // FALL B: Der Spieler möchte die oberste Karte DIESES Hilfsstapels auf einen Baustapel legen
        const myPlayer = state.lastServerState.players.find(p => p.is_me);
        if (!myPlayer) return;

        const dp = myPlayer.discard_piles[discardIdx];
        if (!dp || !dp.top_card) return;

        const card = dp.top_card;
        if (!card.can_play_to || card.can_play_to.length === 0) {
            SoundFx.error();
            return;
        }

        // Auto-Play Komfort: Wenn nur 1 Baustapel passt -> direkt legen
        if (card.can_play_to.length === 1) {
            executePlayCard('discard', discardIdx, card.can_play_to[0]);
            return;
        }

        state.selectedCard = {
            source: 'discard',
            sourceIndex: discardIdx,
            card: card,
            playableTo: card.can_play_to,
        };

        SoundFx.playCard();
        renderGame(state.lastServerState);
    }

    // 4. Klick auf Baustapel
    function handleBuildingPileClick(pileIdx) {
        if (!state.lastServerState || !state.lastServerState.is_my_turn) return;
        if (!state.selectedCard) return;

        // Prüfen, ob der gewählte Baustapel legal ist
        if (!(state.selectedCard.playableTo || []).includes(pileIdx)) {
            SoundFx.error();
            return;
        }

        executePlayCard(state.selectedCard.source, state.selectedCard.sourceIndex, pileIdx);
    }

    // -------------------------------------------------------------------------
    // API-AKTIONEN: ZÜGE AN DEN SERVER SENDEN
    // -------------------------------------------------------------------------
    async function executePlayCard(source, sourceIndex, targetPile) {
        state.selectedCard = null; // Selektion aufheben
        SoundFx.playCard();

        const res = await HubHttp.postJson('./api.php?action=play_card', {
            room_code: state.roomCode,
            source: source,
            sourceIndex: sourceIndex,
            target_pile: targetPile,
        });

        if (res.ok && res.data && res.data.success) {
            if (res.data.game_finished) {
                SoundFx.gameWon();
            }
            pollState();
        } else {
            SoundFx.error();
            alert(res.data?.message || 'Zug ungültig');
            pollState();
        }
    }

    async function executeDiscardCard(cardId, targetDiscardIndex) {
        state.selectedCard = null;
        SoundFx.playCard();

        const res = await HubHttp.postJson('./api.php?action=discard', {
            room_code: state.roomCode,
            card_id: cardId,
            target_discard_index: targetDiscardIndex,
        });

        if (res.ok && res.data && res.data.success) {
            pollState();
        } else {
            SoundFx.error();
            alert(res.data?.message || 'Abwerfen fehlgeschlagen');
            pollState();
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

})();

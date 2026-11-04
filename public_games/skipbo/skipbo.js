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
        noiseBuffer: null,

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
            if (!this.noiseBuffer && this.ctx) {
                // 1 Sekunde Rauschen als Synthese-Basis für authentische Papier- & Kartengeräusche
                const bufferSize = Math.floor(this.ctx.sampleRate * 1.0);
                const buffer = this.ctx.createBuffer(1, bufferSize, this.ctx.sampleRate);
                const data = buffer.getChannelData(0);
                for (let i = 0; i < bufferSize; i++) {
                    data[i] = Math.random() * 2 - 1;
                }
                this.noiseBuffer = buffer;
            }
        },

        /**
         * Echtes Karten-Klacken / Aufschlagen ("snap / tapp")
         * Ersetzt das synthetische "Plopp" durch ein authentisches Karton-Aufschlag-Geräusch.
         */
        playCard() {
            this.init();
            if (!this.ctx || !this.noiseBuffer) return;
            const now = this.ctx.currentTime;

            // 1. Karton-Klick (Bandpass-gefiltertes Rauschen mit steilem Abfall)
            const noise = this.ctx.createBufferSource();
            noise.buffer = this.noiseBuffer;

            const bandpass = this.ctx.createBiquadFilter();
            bandpass.type = 'bandpass';
            bandpass.frequency.setValueAtTime(2800, now);
            bandpass.Q.setValueAtTime(1.9, now);

            const noiseGain = this.ctx.createGain();
            noiseGain.gain.setValueAtTime(0.24, now);
            noiseGain.gain.exponentialRampToValueAtTime(0.001, now + 0.04);

            noise.connect(bandpass);
            bandpass.connect(noiseGain);
            noiseGain.connect(this.ctx.destination);

            noise.start(now);
            noise.stop(now + 0.045);

            // 2. Subtiler Filztisch-Body (sehr leiser, tiefer Impuls 130Hz -> 50Hz)
            const osc = this.ctx.createOscillator();
            const oscGain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(130, now);
            osc.frequency.exponentialRampToValueAtTime(50, now + 0.035);

            oscGain.gain.setValueAtTime(0.12, now);
            oscGain.gain.exponentialRampToValueAtTime(0.001, now + 0.035);

            osc.connect(oscGain);
            oscGain.connect(this.ctx.destination);

            osc.start(now);
            osc.stop(now + 0.04);
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
        },

        /**
         * Reales Kartenziehen & Gleiten ("fsscht / shhh-wip")
         * Papier-Reibung beim Gleiten vom Nachziehstapel.
         */
        cardWhoosh() {
            this.init();
            if (!this.ctx || !this.noiseBuffer) return;
            const now = this.ctx.currentTime;

            const noise = this.ctx.createBufferSource();
            noise.buffer = this.noiseBuffer;

            // Frequenzsweep für das charakteristische Reiben einer Spielkarte
            const filter = this.ctx.createBiquadFilter();
            filter.type = 'bandpass';
            filter.Q.setValueAtTime(2.2, now);
            filter.frequency.setValueAtTime(1700, now);
            filter.frequency.exponentialRampToValueAtTime(3200, now + 0.045);
            filter.frequency.exponentialRampToValueAtTime(1900, now + 0.12);

            const gain = this.ctx.createGain();
            gain.gain.setValueAtTime(0.001, now);
            gain.gain.linearRampToValueAtTime(0.22, now + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.13);

            noise.connect(filter);
            filter.connect(gain);
            gain.connect(this.ctx.destination);

            noise.start(now);
            noise.stop(now + 0.14);
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
        lastAnimatedEventId: null,
        isAnimatingFlight: false,
        renderedHandCardIds: null,
        lastStockTopCardId: null,
        lastOpponentHandCounts: {},
        lastLocalFlightCardId: null,
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

        // Button: Handkarte abwerfen & Zug beenden
        const btnEndTurn = document.getElementById('btn-end-turn');
        if (btnEndTurn) {
            btnEndTurn.addEventListener('click', () => {
                if (!state.lastServerState || !state.lastServerState.is_my_turn) return;
                const myPlayer = state.lastServerState.players.find(p => p.is_me);
                if (!myPlayer) return;
                const hand = myPlayer.hand || [];
                if (hand.length === 0) return;

                // Wenn bereits eine Handkarte gewählt ist: diese abwerfen (bevorzugt auf Hilfsstapel 0 oder gewählt)
                if (state.selectedCard && state.selectedCard.source === 'hand') {
                    executeDiscardCard(state.selectedCard.card.id, 0);
                    return;
                }

                // Automatisch eine günstige Handkarte wählen (möglichst keine Skip-Bo Wildcard)
                const nonWild = hand.filter(c => c.type !== 'skipbo');
                const cardToDiscard = nonWild.length > 0 ? nonWild[nonWild.length - 1] : hand[0];
                executeDiscardCard(cardToDiscard.id, 0);
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

    let currentPollIntervalMs = 1500;

    function startPolling(intervalMs = 1500) {
        if (state.pollTimer) clearInterval(state.pollTimer);
        currentPollIntervalMs = intervalMs;
        state.pollTimer = setInterval(pollState, intervalMs);
    }

    function adjustPollSpeed(faster = false) {
        const targetMs = faster ? 1000 : 1500;
        if (targetMs !== currentPollIntervalMs) {
            startPolling(targetMs);
        }
    }

    async function pollState() {
        if (state.isPolling || !state.roomCode || !state.playerToken || state.isAnimatingFlight) return false;
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

        // Wenn ein Bot am Zug ist, schneller pollen (1000ms), damit man seine Züge live mitverfolgt
        const isBotTurn = data.status === 'active' && !data.is_my_turn && (data.players || []).some(p => p.is_active && p.is_bot);
        adjustPollSpeed(isBotTurn);

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
            state.renderedHandCardIds = [];
            state.lastStockTopCardId = null;
            state.lastOpponentHandCounts = {};
            renderLobby(data);
            return;
        }

        paneLobby.classList.add('hidden');
        paneGame.classList.remove('hidden');

        // PRÜFEN, OB EINE KARTENFLUG-ANIMATION STATTFINDEN SOLL
        const evt = data.last_event;
        const canAnimate = evt && evt.event_id && evt.card && evt.event_id !== state.lastAnimatedEventId &&
            (evt.type === 'card_played' || evt.type === 'turn_ended' || evt.type === 'game_won');

        // Wenn wir diese Aktion gerade lokal animiert haben, nicht noch einmal abspielen
        const isMyRecentLocalFlight = (evt && evt.card && evt.player_id === state.myPlayerId && evt.card.id === state.lastLocalFlightCardId);

        if (canAnimate && !isMyRecentLocalFlight && !state.isAnimatingFlight) {
            state.lastAnimatedEventId = evt.event_id;
            triggerFlyingCardAnimation(evt, data, myPlayer);
        } else {
            if (evt && evt.event_id) {
                state.lastAnimatedEventId = evt.event_id;
            }
            state.lastLocalFlightCardId = null;
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

    /**
     * Führt eine flüssige Kartenflug-Animation von der Quelle zum Ziel durch.
     */
    function triggerFlyingCardAnimation(evt, data, myPlayer) {
        state.isAnimatingFlight = true;

        const isMe = (evt.player_id === state.myPlayerId);
        let startEl = null;
        let targetEl = null;

        // 1. Start-Element lokalisieren
        if (isMe) {
            if (evt.source === 'hand') {
                const specificCard = document.querySelector(`#my-hand-cards-rack [data-card-id="${evt.card.id}"]`);
                if (specificCard) {
                    startEl = specificCard;
                    specificCard.style.opacity = '0';
                } else {
                    startEl = document.getElementById('my-hand-cards-rack');
                }
            } else if (evt.source === 'stock') {
                startEl = document.getElementById('my-stock-card-slot') || document.getElementById('my-stockpile-box');
                const stockCard = startEl ? startEl.querySelector('.skipbo-card') : null;
                if (stockCard) stockCard.style.opacity = '0';
            } else if (evt.source === 'discard') {
                startEl = document.getElementById(`my-discard-slot-${evt.source_index}`);
                const discardCard = startEl ? startEl.querySelector('.skipbo-card') : null;
                if (discardCard) discardCard.style.opacity = '0';
            }
        } else {
            const oppSlot = document.getElementById(`opponent-slot-${evt.player_id}`);
            if (oppSlot) {
                if (evt.source === 'hand') {
                    startEl = oppSlot.querySelector('.js-opp-hand') || oppSlot;
                } else if (evt.source === 'stock') {
                    startEl = oppSlot.querySelector('.js-opp-stock') || oppSlot;
                } else if (evt.source === 'discard') {
                    startEl = oppSlot.querySelector(`.js-opp-discard[data-discard="${evt.source_index}"]`) || oppSlot;
                }
            }
        }

        // 2. Ziel-Element lokalisieren
        if (evt.target_type === 'building') {
            targetEl = document.getElementById(`building-pile-${evt.target_index}`);
        } else if (evt.target_type === 'discard') {
            if (isMe) {
                targetEl = document.getElementById(`my-discard-slot-${evt.target_index}`);
            } else {
                const oppSlot = document.getElementById(`opponent-slot-${evt.player_id}`);
                if (oppSlot) {
                    targetEl = oppSlot.querySelector(`.js-opp-discard[data-discard="${evt.target_index}"]`) || oppSlot;
                }
            }
        }

        if (!startEl || !targetEl) {
            state.isAnimatingFlight = false;
            renderTable(data, myPlayer);
            return;
        }

        // Ticker & Guide Bar sofort mit der Aktionstextmeldung aktualisieren
        const guideText = document.getElementById('turn-guide-text');
        if (guideText && evt.message) {
            guideText.textContent = evt.message;
        }

        SoundFx.cardWhoosh();

        const flightEl = document.createElement('div');
        flightEl.className = 'skipbo-flying-card';
        flightEl.innerHTML = renderCardHtml(evt.card);
        document.body.appendChild(flightEl);

        const startRect = startEl.getBoundingClientRect();
        const targetRect = targetEl.getBoundingClientRect();

        const cardW = targetRect.width > 30 ? targetRect.width : 78;
        const cardH = targetRect.height > 40 ? targetRect.height : 116;

        flightEl.style.width = `${cardW}px`;
        flightEl.style.height = `${cardH}px`;
        flightEl.style.left = `${startRect.left + (startRect.width - cardW) / 2}px`;
        flightEl.style.top = `${startRect.top + (startRect.height - cardH) / 2}px`;
        flightEl.style.transform = 'scale(0.85) rotate(-6deg)';
        flightEl.style.opacity = '0.92';

        // Reflow erzwingen
        void flightEl.offsetHeight;

        // Flug animieren
        requestAnimationFrame(() => {
            flightEl.style.left = `${targetRect.left + (targetRect.width - cardW) / 2}px`;
            flightEl.style.top = `${targetRect.top + (targetRect.height - cardH) / 2}px`;
            flightEl.style.transform = 'scale(1.04) rotate(0deg)';
            flightEl.style.opacity = '1';
        });

        setTimeout(() => {
            SoundFx.playCard();
            flightEl.remove();

            // Lande-Impuls auf Zielstapel
            targetEl.classList.remove('card-land-pulse');
            void targetEl.offsetWidth;
            targetEl.classList.add('card-land-pulse');

            // Sound & Sweep für vollendeten 12er-Stapel
            if (evt.target_type === 'building' && (evt.card.value === 12 || evt.card.played_as === 12)) {
                SoundFx.pileCompleted();
                targetEl.classList.add('pile-completed-sweep');
                setTimeout(() => {
                    targetEl.classList.remove('pile-completed-sweep');
                }, 720);
            }

            state.isAnimatingFlight = false;
            renderTable(data, myPlayer);
        }, 480);
    }

    /**
     * Zieht Handkarten flüssig aus dem Nachziehstapel in das Handkarten-Rack.
     */
    function animateDrawnCards(newItems) {
        if (!newItems || newItems.length === 0) return;

        const drawDeckSlot = document.getElementById('draw-deck-slot') || document.body;
        const drawRect = drawDeckSlot.getBoundingClientRect();

        // 1. Zunächst alle neuen Karten im Rack unsichtbar schalten
        newItems.forEach(item => {
            item.el.style.opacity = '0';
        });

        // Sicherheits-Timeout: Verhindert, dass Karten bei Tab-Wechsel oder Reflow unsichtbar bleiben
        setTimeout(() => {
            newItems.forEach(item => {
                if (item.el) item.el.style.opacity = '1';
            });
            state.isAnimatingFlight = false;
        }, 1200);

        state.isAnimatingFlight = true;

        newItems.forEach((item, i) => {
            const targetRect = item.el.getBoundingClientRect();
            const w = targetRect.width > 20 ? targetRect.width : 78;
            const h = targetRect.height > 20 ? targetRect.height : 116;

            const flightEl = document.createElement('div');
            flightEl.className = 'skipbo-flying-card';
            flightEl.innerHTML = `
                <div class="skipbo-deck-back" style="width:100%;height:100%;border-radius:var(--card-radius);box-sizing:border-box;">
                    <div class="skipbo-deck-logo">SKIP-BO</div>
                </div>
            `;
            document.body.appendChild(flightEl);

            flightEl.style.width = `${w}px`;
            flightEl.style.height = `${h}px`;
            flightEl.style.left = `${drawRect.left + (drawRect.width - w) / 2}px`;
            flightEl.style.top = `${drawRect.top + (drawRect.height - h) / 2}px`;
            flightEl.style.transform = 'scale(0.85) rotate(14deg)';
            flightEl.style.opacity = '0';

            void flightEl.offsetHeight;

            setTimeout(() => {
                SoundFx.cardWhoosh();
                flightEl.style.opacity = '1';

                requestAnimationFrame(() => {
                    flightEl.style.left = `${targetRect.left}px`;
                    flightEl.style.top = `${targetRect.top}px`;
                    flightEl.style.transform = 'scale(1.04) rotate(0deg)';
                });

                // Nach halber Flugzeit: Karte aufdecken
                setTimeout(() => {
                    flightEl.innerHTML = renderCardHtml(item.card);
                }, 210);

                // Landung
                setTimeout(() => {
                    SoundFx.playCard();
                    flightEl.remove();
                    item.el.style.opacity = '1';
                    item.el.classList.remove('card-land-pulse');
                    void item.el.offsetWidth;
                    item.el.classList.add('card-land-pulse');

                    if (i === newItems.length - 1) {
                        state.isAnimatingFlight = false;
                    }
                }, 440);
            }, i * 90);
        });
    }

    /**
     * Animiert das Nachziehen von Karten gegnerischer Spieler.
     */
    function animateOpponentDrawnCards(oppId, count) {
        const oppSlot = document.getElementById(`opponent-slot-${oppId}`);
        const drawDeckSlot = document.getElementById('draw-deck-slot');
        if (!oppSlot || !drawDeckSlot) return;

        const drawRect = drawDeckSlot.getBoundingClientRect();
        const targetRect = (oppSlot.querySelector('.js-opp-hand') || oppSlot).getBoundingClientRect();

        const numCards = Math.min(count, 5);
        for (let i = 0; i < numCards; i++) {
            const flightEl = document.createElement('div');
            flightEl.className = 'skipbo-flying-card';
            flightEl.innerHTML = `
                <div class="skipbo-deck-back" style="width:46px;height:68px;border-width:2px;box-sizing:border-box;">
                    <span style="font-size:0.6rem;font-weight:900;">SKIP-BO</span>
                </div>
            `;
            document.body.appendChild(flightEl);

            flightEl.style.width = '46px';
            flightEl.style.height = '68px';
            flightEl.style.left = `${drawRect.left + (drawRect.width - 46) / 2}px`;
            flightEl.style.top = `${drawRect.top + (drawRect.height - 68) / 2}px`;
            flightEl.style.transform = 'scale(0.8) rotate(12deg)';
            flightEl.style.opacity = '0';

            void flightEl.offsetHeight;

            setTimeout(() => {
                SoundFx.cardWhoosh();
                flightEl.style.opacity = '1';

                requestAnimationFrame(() => {
                    flightEl.style.left = `${targetRect.left + (targetRect.width - 46) / 2}px`;
                    flightEl.style.top = `${targetRect.top + (targetRect.height - 68) / 2}px`;
                    flightEl.style.transform = 'scale(0.9) rotate(0deg)';
                });

                setTimeout(() => {
                    flightEl.remove();
                    oppSlot.classList.remove('card-land-pulse');
                    void oppSlot.offsetWidth;
                    oppSlot.classList.add('card-land-pulse');
                }, 420);
            }, i * 80);
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

        // 1. Zug-Leitfaden & Status Badge
        const guideText = document.getElementById('turn-guide-text');
        const guideBar = document.getElementById('turn-guide-bar');
        const statusBadge = document.getElementById('turn-status-badge');
        const actionsRow = document.getElementById('turn-actions-row');
        const btnEndTurn = document.getElementById('btn-end-turn');

        // Prüfen, ob wir mindestens eine spielbare Karte haben
        const hasPlayableCards = myPlayer && (
            (myPlayer.hand || []).some(c => (c.can_play_to || []).length > 0) ||
            (myPlayer.stock_top_card && (myPlayer.stock_top_card.can_play_to || []).length > 0) ||
            (myPlayer.discard_piles || []).some(dp => dp.top_card && (dp.top_card.can_play_to || []).length > 0)
        );

        if (isMyTurn) {
            if (guideBar) {
                guideBar.style.borderColor = '#10b981';
                guideBar.style.color = '#34d399';
            }
            if (statusBadge) {
                statusBadge.className = 'skipbo-turn-badge is-my-turn';
                statusBadge.textContent = '🟢 DU BIST AM ZUG';
            }
            if (actionsRow) actionsRow.classList.remove('hidden');

            if (btnEndTurn) {
                if (state.selectedCard && state.selectedCard.source === 'hand') {
                    btnEndTurn.textContent = '⬇️ Gewählte Handkarte abwerfen (Zug beenden)';
                } else {
                    btnEndTurn.textContent = '⏭️ Handkarte abwerfen & Zug beenden';
                }
            }

            if (guideText) {
                if (!state.selectedCard) {
                    if (hasPlayableCards) {
                        guideText.textContent = 'Tippe auf eine grün leuchtende Karte zum Legen, oder beende deinen Zug mit Abwerfen.';
                    } else {
                        guideText.textContent = 'Keine Karte passt auf die Baustapel. Wirf eine Handkarte ab (Button unten), um an deinen Gegner zu übergeben.';
                    }
                } else {
                    if (state.selectedCard.source === 'hand') {
                        guideText.textContent = '🎯 Lege die Karte auf einen Baustapel – ODER klicke "Zug beenden" / tippe auf einen Hilfsstapel.';
                    } else {
                        guideText.textContent = '🎯 Wähle einen leuchtenden Baustapel, um die Karte anzulegen.';
                    }
                }
            }
        } else {
            const activePlayer = data.players.find(p => p.is_active);
            const isBot = activePlayer ? activePlayer.is_bot : false;

            if (guideBar) {
                guideBar.style.borderColor = 'rgba(255, 255, 255, 0.1)';
                guideBar.style.color = '#94a3b8';
            }
            if (statusBadge) {
                statusBadge.className = 'skipbo-turn-badge is-bot-turn';
                statusBadge.textContent = isBot ? '🤖 BOT AM ZUG' : '⏳ GEGNER AM ZUG';
            }
            if (actionsRow) actionsRow.classList.add('hidden');

            if (guideText) {
                const name = activePlayer ? activePlayer.player_name : 'Gegner';
                guideText.textContent = `${name} überlegt und führt den Zug aus...`;
            }
            state.selectedCard = null; // Auswahl zurücksetzen
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
            const prevCount = state.lastOpponentHandCounts[opp.id];
            const currentCount = opp.hand_count;
            state.lastOpponentHandCounts[opp.id] = currentCount;

            if (typeof prevCount === 'number' && currentCount > prevCount && state.gameStatus === 'active') {
                animateOpponentDrawnCards(opp.id, currentCount - prevCount);
            }

            const slot = document.createElement('div');
            slot.className = `opponent-slot ${opp.is_active ? 'active-turn' : ''}`;
            slot.id = `opponent-slot-${opp.id}`;
            slot.dataset.playerId = opp.id;

            // Miniatur-Zielstapel
            let stockHtml = '';
            if (opp.stock_top_card) {
                stockHtml = renderCardHtml(opp.stock_top_card, { mini: true });
            } else {
                stockHtml = `<div class="skipbo-deck-back" style="width:46px;height:68px;border-width:2px;"><span style="font-size:0.6rem;font-weight:900;">0</span></div>`;
            }

            // Miniatur-Hilfsstapel
            let discardHtml = '';
            (opp.discard_piles || []).forEach((dp, dIdx) => {
                if (dp.top_card) {
                    discardHtml += `<div class="js-opp-discard" data-discard="${dIdx}" style="display:inline-block;margin:0 1px;">${renderCardHtml(dp.top_card, { mini: true })}</div>`;
                } else {
                    discardHtml += `<div class="js-opp-discard" data-discard="${dIdx}" style="display:inline-block;width:34px;height:50px;border:1px dashed rgba(255,255,255,0.2);border-radius:6px;margin:0 1px;"></div>`;
                }
            });

            slot.innerHTML = `
                <div class="opponent-info js-opp-hand">
                    <span class="opponent-name">${opp.is_bot ? '🤖 ' : ''}${escapeHtml(opp.player_name)}</span>
                    <span class="opponent-hand-badge">🃏 Hand: ${opp.hand_count}</span>
                    <span style="font-size:0.75rem;color:#fbbf24;font-weight:800;margin-top:2px;">🎯 Ziel: ${opp.stock_count}</span>
                </div>
                <div class="opponent-mini-card-wrap js-opp-stock">
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
            slot.style.opacity = '1';

            const neededNumSpan = slot.querySelector('.skipbo-slot-needed-num');
            const targetContainer = slot.querySelector('.js-slot-card-target');
            const labelEl = slot.querySelector('.skipbo-building-label');
            if (targetContainer) targetContainer.style.opacity = '1';

            const needed = pile.needed_value;
            if (neededNumSpan) {
                neededNumSpan.textContent = needed > 12 ? '✓' : needed;
            }

            if (labelEl) {
                if (pile.top_value > 0) {
                    const displayVal = needed > 12 ? '✓' : needed;
                    labelEl.innerHTML = `
                        <div class="skipbo-next-pill">
                            <span class="next-pill-label">Nächste:</span>
                            <span class="next-pill-val">${displayVal}</span>
                        </div>
                    `;
                } else {
                    labelEl.innerHTML = `
                        <div class="skipbo-next-pill is-start">
                            <span class="next-pill-label">Start:</span>
                            <span class="next-pill-val">1</span>
                        </div>
                    `;
                }
            }

            // Top-Karte anzeigen
            targetContainer.innerHTML = '';
            if (pile.top_card) {
                targetContainer.innerHTML = renderCardHtml(pile.top_card, {
                    playedAs: pile.top_value || pile.top_card.played_as,
                });
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

        if (slot) slot.style.opacity = '1';
        if (box) box.style.opacity = '1';

        if (countBadge) countBadge.textContent = `Ziel: Noch ${count}`;

        const prevTopId = state.lastStockTopCardId;
        const currentTopId = topCard ? topCard.id : null;
        const isNewTop = currentTopId && currentTopId !== prevTopId;
        state.lastStockTopCardId = currentTopId;

        if (slot) {
            slot.innerHTML = '';
            if (topCard) {
                const isPlayable = isMyTurn && (topCard.can_play_to || []).length > 0;
                const isSelected = state.selectedCard && state.selectedCard.source === 'stock';

                slot.innerHTML = renderCardHtml(topCard, {
                    playable: isPlayable,
                    selected: isSelected,
                });

                if (isNewTop && prevTopId !== null) {
                    const cardEl = slot.querySelector('.skipbo-card');
                    if (cardEl) {
                        cardEl.classList.add('card-flip-reveal');
                        setTimeout(() => {
                            if (cardEl) cardEl.classList.remove('card-flip-reveal');
                        }, 450);
                    }
                }
            }
        }
    }

    function renderMyDiscardPiles(discardPiles, isMyTurn) {
        const isDiscardingActive = isMyTurn && state.selectedCard && state.selectedCard.source === 'hand';

        discardPiles.forEach((dp, idx) => {
            const slot = document.getElementById(`my-discard-slot-${idx}`);
            if (!slot) return;
            slot.style.opacity = '1';

            const target = slot.querySelector('.js-discard-card-target');
            const countBadge = slot.querySelector('.js-discard-count');
            if (target) target.style.opacity = '1';

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
        if (rack) rack.style.opacity = '1';
        const countIndicator = document.getElementById('hand-count-indicator');
        if (countIndicator) countIndicator.textContent = `${handCards.length} / 5`;

        const prevIds = state.renderedHandCardIds;
        const currentIds = handCards.map(c => c.id);

        rack.innerHTML = '';

        const cardItems = [];
        handCards.forEach((card, idx) => {
            const isPlayable = isMyTurn && (card.can_play_to || []).length > 0;
            const isSelected = state.selectedCard && state.selectedCard.source === 'hand' && state.selectedCard.card.id === card.id;

            const cardEl = createCardElement(card, {
                playable: isPlayable,
                selected: isSelected,
            });
            cardEl.dataset.cardId = card.id;

            cardEl.addEventListener('click', () => {
                handleHandCardClick(card, idx);
            });

            rack.appendChild(cardEl);
            cardItems.push({ card, el: cardEl, isNew: prevIds !== null && !prevIds.includes(card.id) });
        });

        const shouldAnimate = prevIds !== null && cardItems.some(i => i.isNew) && state.gameStatus === 'active';
        state.renderedHandCardIds = currentIds;

        if (shouldAnimate) {
            animateDrawnCards(cardItems.filter(i => i.isNew));
        }
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
            const playedAs = opts.playedAs || card.played_as || null;
            const cornerSize = isMini ? 'font-size:0.5rem;' : '';
            return `
                <div class="skipbo-card ${colorClass} ${isPlayable ? 'is-playable' : ''} ${isSelected ? 'is-selected' : ''}" style="${scaleStyle}">
                    <div class="card-corner card-corner-top-left" style="${cornerSize}">
                        ${playedAs ? `<span class="card-corner-wild-val">${playedAs}</span>` : `<span class="card-corner-wild-text">SKIP</span>`}
                    </div>
                    <div class="card-center-skipbo-banner ${playedAs ? 'has-played-as' : ''}" style="${isMini ? 'transform:translate(-50%,-50%) scale(0.65);' : ''}">
                        <span class="skipbo-logo-text">SKIP-BO</span>
                        <span class="skipbo-star-icon">★</span>
                        ${playedAs ? `<div class="skipbo-played-as-pill"><span class="pill-label">GILT ALS</span><span class="pill-val">${playedAs}</span></div>` : ''}
                    </div>
                    <div class="card-corner card-corner-bottom-right" style="${cornerSize}">
                        ${playedAs ? `<span class="card-corner-wild-val">${playedAs}</span>` : `<span class="card-corner-wild-text">BO</span>`}
                    </div>
                </div>
            `;
        }

        // Zahlenkarte 1 bis 12
        const textColorClass = `text-color-${card.color || 'blue'}`;
        const val = opts.playedAs || card.played_as || card.value;
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

        // Auto-Play Komfort: Wenn nur genau ein Baustapel in Frage kommt, direkt dorthin legen!
        const canPlayTo = card.can_play_to || [];
        if (canPlayTo.length === 1) {
            executePlayCard('hand', card.id, canPlayTo[0]);
            return;
        }

        state.selectedCard = {
            source: 'hand',
            sourceIndex: card.id,
            card: card,
            playableTo: canPlayTo,
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

    /**
     * Führt eine sofortige lokale Fluganimation durch (z.B. Hand -> Hilfsstapel oder Hand/Stock/Discard -> Baustapel).
     */
    function animateLocalCardFlight(card, startEl, targetEl, onComplete) {
        if (!startEl || !targetEl) {
            if (onComplete) onComplete();
            return;
        }

        state.isAnimatingFlight = true;
        state.lastLocalFlightCardId = card.id;
        SoundFx.cardWhoosh();

        const startRect = startEl.getBoundingClientRect();
        const targetRect = targetEl.getBoundingClientRect();

        // Nur die eigentliche Karte (nie den Container-Slot!) optisch ausblenden
        const cardToHide = startEl.classList.contains('skipbo-card')
            ? startEl
            : startEl.querySelector('.skipbo-card');
        if (cardToHide) {
            cardToHide.style.opacity = '0';
        }

        const cardW = targetRect.width > 30 ? targetRect.width : 78;
        const cardH = targetRect.height > 40 ? targetRect.height : 116;

        const flightEl = document.createElement('div');
        flightEl.className = 'skipbo-flying-card';
        flightEl.innerHTML = renderCardHtml(card);
        document.body.appendChild(flightEl);

        flightEl.style.width = `${cardW}px`;
        flightEl.style.height = `${cardH}px`;
        flightEl.style.left = `${startRect.left + (startRect.width - cardW) / 2}px`;
        flightEl.style.top = `${startRect.top + (startRect.height - cardH) / 2}px`;
        flightEl.style.transform = 'scale(0.88) rotate(-6deg)';
        flightEl.style.opacity = '0.95';

        void flightEl.offsetHeight;

        requestAnimationFrame(() => {
            flightEl.style.left = `${targetRect.left + (targetRect.width - cardW) / 2}px`;
            flightEl.style.top = `${targetRect.top + (targetRect.height - cardH) / 2}px`;
            flightEl.style.transform = 'scale(1.03) rotate(0deg)';
            flightEl.style.opacity = '1';
        });

        setTimeout(() => {
            if (startEl) startEl.style.opacity = '1';
            if (cardToHide) cardToHide.style.opacity = '1';
            SoundFx.playCard();
            flightEl.remove();

            targetEl.classList.remove('card-land-pulse');
            void targetEl.offsetWidth;
            targetEl.classList.add('card-land-pulse');

            state.isAnimatingFlight = false;
            if (onComplete) onComplete();
        }, 440);
    }

    // -------------------------------------------------------------------------
    // API-AKTIONEN: ZÜGE AN DEN SERVER SENDEN
    // -------------------------------------------------------------------------
    async function executePlayCard(source, sourceIndex, targetPile) {
        state.selectedCard = null; // Selektion aufheben

        const myPlayer = state.lastServerState ? state.lastServerState.players.find(p => p.is_me) : null;
        let card = null;
        let startEl = null;

        if (myPlayer) {
            if (source === 'hand') {
                card = (myPlayer.hand || []).find(c => c.id === sourceIndex);
                startEl = document.querySelector(`#my-hand-cards-rack [data-card-id="${sourceIndex}"]`);
            } else if (source === 'stock') {
                card = myPlayer.stock_top_card;
                startEl = document.getElementById('my-stock-card-slot') || document.getElementById('my-stockpile-box');
            } else if (source === 'discard') {
                const dp = (myPlayer.discard_piles || [])[sourceIndex];
                card = dp ? dp.top_card : null;
                startEl = document.getElementById(`my-discard-slot-${sourceIndex}`);
            }
        }

        const targetSlot = document.getElementById(`building-pile-${targetPile}`);

        let flightFinished = false;
        let responseReceived = false;

        const checkFinish = () => {
            if (flightFinished && responseReceived) {
                pollState();
            }
        };

        if (card && targetSlot) {
            animateLocalCardFlight(card, startEl, targetSlot, () => {
                flightFinished = true;
                checkFinish();
            });
        } else {
            flightFinished = true;
        }

        const res = await HubHttp.postJson('./api.php?action=play_card', {
            room_code: state.roomCode,
            source: source,
            source_index: sourceIndex,
            sourceIndex: sourceIndex,
            target_pile: targetPile,
        });

        if (res.ok && res.data && res.data.success) {
            if (res.data.game_finished) {
                SoundFx.gameWon();
            }
            responseReceived = true;
            checkFinish();
        } else {
            SoundFx.error();
            alert(res.data?.message || 'Zug ungültig');
            pollState();
        }
    }

    async function executeDiscardCard(cardId, targetDiscardIndex) {
        state.selectedCard = null;

        const myPlayer = state.lastServerState ? state.lastServerState.players.find(p => p.is_me) : null;
        const card = myPlayer && myPlayer.hand ? myPlayer.hand.find(c => c.id === cardId) : null;
        const cardEl = document.querySelector(`#my-hand-cards-rack [data-card-id="${cardId}"]`);
        const targetSlot = document.getElementById(`my-discard-slot-${targetDiscardIndex}`);

        let flightFinished = false;
        let responseReceived = false;

        const checkFinish = () => {
            if (flightFinished && responseReceived) {
                pollState();
            }
        };

        if (card && targetSlot) {
            animateLocalCardFlight(card, cardEl || document.getElementById('my-hand-cards-rack'), targetSlot, () => {
                flightFinished = true;
                checkFinish();
            });
        } else {
            flightFinished = true;
        }

        const res = await HubHttp.postJson('./api.php?action=discard', {
            room_code: state.roomCode,
            card_id: cardId,
            target_discard_index: targetDiscardIndex,
        });

        if (res.ok && res.data && res.data.success) {
            responseReceived = true;
            checkFinish();
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

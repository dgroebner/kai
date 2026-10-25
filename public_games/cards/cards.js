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
    let currentRoomData = null;
    let unoCountdownTimer = null;
    let unoAnimFrame = null;
    let lastRenderedTopCardId = null;
    let lastProcessedDrawEventTime = 0;
    let lastProcessedUnoEventKey = '';

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
        playDrawSound() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(220, now);
            osc.frequency.exponentialRampToValueAtTime(460, now + 0.08);
            gain.gain.setValueAtTime(0.22, now);
            gain.gain.linearRampToValueAtTime(0.01, now + 0.08);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.09);
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
                const text = `Komm an den Tisch für Mau-Mau / Uno in Quarxl! 🃏 Raumcode: ${roomCode}\n${url}`;
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

        // Uno/Mau-Ruf oder "Erwischt!" Button
        const unoBtn = document.getElementById('btn-uno-shout');
        if (unoBtn) {
            unoBtn.addEventListener('click', async () => {
                if (unoBtn.classList.contains('btn-catch-uno')) {
                    // Einen Mitspieler ohne Mau/Uno erwischen!
                    const res = await HubHttp.postJson(`./api.php?action=catch_uno&room=${encodeURIComponent(roomCode)}`);
                    if (res.ok && res.data && res.data.success) {
                        AudioEngine.playLauncherSound(2);
                        fetchState(true);
                    } else {
                        HubDialog.alert('Hinweis', res.data?.message || 'Aktion nicht möglich.', '⚠️');
                    }
                } else {
                    // Selbst MAU/UNO ansagen
                    unoCalledLocally = true;
                    unoBtn.classList.add('uno-active');
                    AudioEngine.playUnoSound();
                    await HubHttp.postJson(`./api.php?action=call_uno&room=${encodeURIComponent(roomCode)}`);
                }
            });
        }

        // Revanche (Host only)
        const rematchBtn = document.getElementById('btn-rematch');
        if (rematchBtn) {
            rematchBtn.addEventListener('click', async () => {
                const res = await HubHttp.postJson(`./api.php?action=rematch&room=${encodeURIComponent(roomCode)}`);
                if (res.ok && res.data && res.data.success) {
                    fetchState(true);
                } else {
                    HubDialog.alert('Hinweis', res.data?.message || 'Konnte Revanche nicht starten.', '⚠️');
                }
            });
        }
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

        if (window.QuarxlReactions && data.recent_reactions) {
            window.QuarxlReactions.handleIncoming(data.recent_reactions);
        }

        // Versions-Prüfung für effizientes Re-Rendering
        if (!force && data.state_version === lastStateVersion) {
            return;
        }

        lastStateVersion = data.state_version;
        renderState(data);
    }

    function renderState(data) {
        currentRoomData = data;
        // Modus-Badge
        const modeBadge = document.getElementById('game-mode-badge');
        if (modeBadge) {
            const modeNames = { 'uno': 'Uno Klassik', 'uno_extreme': 'Uno Extreme', 'mau_mau': 'Mau-Mau' };
            modeBadge.textContent = modeNames[data.game_mode] || 'Mau-Mau / Uno';
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
            renderFinished(data, myPlayer);
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
                const hasOneCardClass = (op.card_count === 1) ? 'has-one-card' : '';
                const callWord = (data.game_mode === 'mau_mau') ? 'MAU' : 'UNO';
                let unoBubbleHtml = '';
                if (op.card_count === 1) {
                    if (op.has_called_uno) {
                        unoBubbleHtml = `<div class="opponent-uno-bubble bubble-called" title="${HubHtml.escape(op.name)} hat rechtzeitig ${callWord}! gerufen"><span class="bubble-icon">📢</span> ${callWord}!</div>`;
                    } else {
                        unoBubbleHtml = `<div class="opponent-uno-bubble bubble-missed" title="${HubHtml.escape(op.name)} hat NICHT ${callWord}! gerufen - Erwischen möglich!"><span class="bubble-icon">🚨</span> 1 KARTE!</div>`;
                    }
                }
                const avatar = op.is_bot ? '🤖' : '👤';
                oppHtml += `
                    <div class="opponent-slot ${turnClass} ${hasOneCardClass}">
                        ${unoBubbleHtml}
                        <div class="opponent-avatar">${avatar}</div>
                        <div class="opponent-name" title="${HubHtml.escape(op.name)}">${HubHtml.escape(op.name)}</div>
                        <div class="opponent-cards-badge">🃏 ${op.card_count}</div>
                    </div>
                `;
            });
            oppBar.innerHTML = oppHtml;
        }

        // Tischmitte: Ablagestapel (mit physisch versetzten letzten bis zu 3 Karten)
        const discardCont = document.getElementById('discard-pile-container');
        if (discardCont && data.top_card) {
            renderDiscardPileStack(discardCont, data);

            // Gegner-Kartenwurf animieren, falls ein Mitspieler gelegt hat
            if (lastRenderedTopCardId && data.top_card.id !== lastRenderedTopCardId) {
                const lastEvent = data.last_event;
                const myName = myPlayer ? myPlayer.name : '';
                const wasMe = lastEvent && lastEvent.message && lastEvent.message.startsWith(myName);

                if (!wasMe) {
                    let sourceSlot = null;
                    const oppSlots = document.querySelectorAll('.opponent-slot');
                    oppSlots.forEach(slot => {
                        const nameEl = slot.querySelector('.opponent-name');
                        if (nameEl && lastEvent && lastEvent.message && lastEvent.message.includes(nameEl.textContent.trim())) {
                            sourceSlot = slot;
                        }
                    });
                    if (!sourceSlot && oppSlots.length > 0) {
                        sourceSlot = oppSlots[0];
                    }
                    if (sourceSlot) {
                        animateOpponentCardFly(sourceSlot, discardCont, data.top_card);
                    }
                }
            }
            lastRenderedTopCardId = data.top_card.id;
        }

        // Gegner zieht Karte(n) -> Fluganimation vom Ziehstapel / Launcher zum Gegner
        if (data.last_event?.type === 'cards_drawn' && data.last_event.time && data.last_event.time !== lastProcessedDrawEventTime) {
            lastProcessedDrawEventTime = data.last_event.time;
            const myName = myPlayer ? myPlayer.name : '';
            const wasMe = data.last_event.message && data.last_event.message.includes(myName);

            if (!wasMe) {
                let oppSlot = null;
                document.querySelectorAll('.opponent-slot').forEach(slot => {
                    const n = slot.querySelector('.opponent-name')?.textContent?.trim();
                    if (n && data.last_event.message.includes(n)) {
                        oppSlot = slot;
                    }
                });

                if (oppSlot) {
                    const m = data.last_event.message.match(/(\d+)\s*(Karten|Strafkarten)/i);
                    const drawCount = m ? parseInt(m[1], 10) : (data.last_event.message.includes('1 Karte') ? 1 : 0);
                    const sourceEl = document.getElementById('extreme-launcher-btn') || document.getElementById('deck-draw-btn');
                    if (drawCount > 0 && sourceEl) {
                        if (data.game_mode === 'uno_extreme') {
                            AudioEngine.playLauncherSound(drawCount);
                        } else {
                            AudioEngine.playDrawSound();
                        }
                        animateDrawFlight(drawCount, sourceEl, oppSlot, data.game_mode);
                    }
                }
            }
        }

        // Uno/Mau-Ruf Event überwachen -> Akustischer Ruf & auffälliges Tisch-Overlay
        const lastEvt = data.last_event;
        if (lastEvt) {
            const isUnoCall = (lastEvt.type === 'uno_called') || (lastEvt.type === 'card_played' && (lastEvt.message?.includes('und ruft UNO') || lastEvt.message?.includes('und ruft MAU')));
            const evtKey = `${lastEvt.time || 0}_${lastEvt.type}_${lastEvt.message || ''}`;
            if (isUnoCall && evtKey !== lastProcessedUnoEventKey) {
                lastProcessedUnoEventKey = evtKey;
                const myName = myPlayer ? myPlayer.name : '';
                const wasMe = Boolean(lastEvt.message && lastEvt.message.startsWith(myName));
                const callWord = (data.game_mode === 'mau_mau') ? 'MAU' : 'UNO';

                if (!wasMe) {
                    AudioEngine.playUnoSound();
                }

                let callerName = '';
                if (lastEvt.type === 'uno_called') {
                    const m = lastEvt.message.match(/📢\s*(.*?)\s*ruft/);
                    callerName = m ? m[1].trim() : '';
                } else if (lastEvt.type === 'card_played') {
                    const m = lastEvt.message.match(/^(.*?)\s*legte/);
                    callerName = m ? m[1].trim() : '';
                }
                if (!callerName && wasMe) callerName = myName;

                triggerUnoShoutOverlay(callerName || 'Spieler', callWord, wasMe);
            }
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

        // Uno / Mau / Erwischt Button-Status
        const unoBtn = document.getElementById('btn-uno-shout');
        if (unoBtn && myPlayer) {
            const handCount = myPlayer.card_count || 0;
            const callWord = (data.game_mode === 'mau_mau') ? 'MAU!' : 'UNO!';

            // Prüfen, ob ein Mitspieler genau 1 Karte hat und NICHT gerufen hat
            const missedOpponent = data.players.find(p => !p.is_me && p.card_count === 1 && !p.has_called_uno);

            // Prüfen, ob durch eine spielbare Discard-All-Karte genau 1 Karte übrig bleiben würde
            const hasPlayableDiscardAllToUno = Array.isArray(myPlayer.cards) && myPlayer.cards.some(c => {
                if (c.type !== 'discard_all' || !c.can_play) return false;
                const rem = myPlayer.cards.filter(other => other.id !== c.id && other.color !== c.color);
                return rem.length === 1;
            });

            if (missedOpponent) {
                // Ein Mitspieler hat es vergessen -> Erwischt-Modus!
                unoBtn.textContent = '🚨 ERWISCHT!';
                unoBtn.classList.add('btn-catch-uno');
                unoBtn.classList.remove('uno-active');
                unoBtn.title = `${missedOpponent.name} hat vergessen ${callWord} zu rufen! Klicke um 2 Strafkarten zu verhängen!`;
                unoBtn.style.animation = 'bounce 0.6s infinite';
            } else if (handCount <= 2 || hasPlayableDiscardAllToUno) {
                // Eigener Ruf-Bereich
                unoBtn.textContent = callWord;
                unoBtn.classList.remove('btn-catch-uno');
                unoBtn.title = `Rufe ${callWord} vor deiner letzten Karte!`;

                if (myPlayer.has_called_uno || unoCalledLocally) {
                    unoBtn.classList.add('uno-active');
                    unoBtn.style.animation = 'none';
                } else {
                    unoBtn.classList.remove('uno-active');
                    unoBtn.style.animation = 'bounce 0.8s infinite';
                }
            } else {
                // Normaler Zustand (> 2 Karten und kein Gegner säumig)
                unoBtn.textContent = callWord;
                unoBtn.classList.remove('btn-catch-uno', 'uno-active');
                unoBtn.style.animation = 'none';
                unoBtn.title = `${callWord} kann gerufen werden, wenn du nur noch 1-2 Karten hast.`;
                unoCalledLocally = false;
            }
        }

        // Eigene Handkarten rendern (mit automatischer Farbsortierung & 2-Reihen-System)
        const fannedCont = document.getElementById('player-hand-fanned');
        if (fannedCont && myPlayer && Array.isArray(myPlayer.cards)) {
            const sortedCards = sortHandCards(myPlayer.cards, data.game_mode);
            const count = sortedCards.length;

            if (count >= 8) {
                // 2-Reihen-Layout bei vielen Handkarten (Mobile & Desktop)
                fannedCont.classList.add('two-rows');
                const mid = Math.ceil(count / 2);
                const topCards = sortedCards.slice(0, mid);
                const bottomCards = sortedCards.slice(mid);

                const rowOverlap = getOverlapForCount(mid);
                fannedCont.style.setProperty('--card-overlap', `${rowOverlap}px`);

                let topHtml = '';
                topCards.forEach((c, idx) => {
                    topHtml += renderCardHtml(c, c.can_play, false, idx + 1);
                });

                let bottomHtml = '';
                bottomCards.forEach((c, idx) => {
                    bottomHtml += renderCardHtml(c, c.can_play, false, mid + idx + 1);
                });

                fannedCont.innerHTML = `
                    <div class="hand-row hand-row-top">${topHtml}</div>
                    <div class="hand-row hand-row-bottom">${bottomHtml}</div>
                `;
            } else {
                // 1-reihig bei 1 bis 7 Karten
                fannedCont.classList.remove('two-rows');
                const rowOverlap = getOverlapForCount(count);
                fannedCont.style.setProperty('--card-overlap', `${rowOverlap}px`);

                let cardsHtml = '';
                sortedCards.forEach((c, idx) => {
                    cardsHtml += renderCardHtml(c, c.can_play, false, idx + 1);
                });
                fannedCont.innerHTML = `<div class="hand-row single-row">${cardsHtml}</div>`;
            }

            // Klick-Events an spielbare Karten binden
            fannedCont.querySelectorAll('.game-card.can-play').forEach(cardEl => {
                cardEl.addEventListener('click', () => {
                    const cardId = cardEl.dataset.cardId;
                    const card = sortedCards.find(x => x.id === cardId);
                    if (card) {
                        onCardClicked(card, data);
                    }
                });
            });
        }
    }

    function getOverlapForCount(n) {
        if (n <= 3) return 8;
        if (n <= 5) return -12;
        if (n <= 7) return -26;
        if (n <= 9) return -36;
        if (n <= 12) return -44;
        return -50;
    }

    function sortHandCards(cards, gameMode) {
        if (!Array.isArray(cards)) return [];
        const copy = [...cards];
        if (gameMode === 'mau_mau') {
            const suitOrder = { 'hearts': 1, 'diamonds': 2, 'spades': 3, 'clubs': 4 };
            copy.sort((a, b) => {
                const sa = suitOrder[a.color] || 99;
                const sb = suitOrder[b.color] || 99;
                if (sa !== sb) return sa - sb;
                const va = Number(a.value) || 0;
                const vb = Number(b.value) || 0;
                return va - vb;
            });
        } else {
            const colorOrder = { 'red': 1, 'yellow': 2, 'green': 3, 'blue': 4, 'wild': 5 };
            const typeOrder = {
                'number': 1,
                'draw2': 2,
                'reverse': 3,
                'skip': 4,
                'hit2': 5,
                'discard_all': 6,
                'wild': 7,
                'wild_draw4': 8,
                'extreme_hit': 9
            };
            copy.sort((a, b) => {
                const ca = colorOrder[a.color] || 99;
                const cb = colorOrder[b.color] || 99;
                if (ca !== cb) return ca - cb;
                const va = (a.value !== null && a.value !== undefined) ? Number(a.value) : 99;
                const vb = (b.value !== null && b.value !== undefined) ? Number(b.value) : 99;
                if (va !== vb) return va - vb;
                const ta = typeOrder[a.type] || 99;
                const tb = typeOrder[b.type] || 99;
                return ta - tb;
            });
        }
        return copy;
    }

    function getCardRotation(cardId, seedOffset) {
        let hash = 0;
        const str = String(cardId || '') + seedOffset;
        for (let i = 0; i < str.length; i++) {
            hash = (hash * 31 + str.charCodeAt(i)) & 0xff;
        }
        const angle = ((hash % 15) - 7); // -7° bis +7°
        return angle === 0 ? (seedOffset < 0 ? -4 : 3) : angle;
    }

    function renderDiscardPileStack(container, data) {
        const recent = (Array.isArray(data.recent_discard) && data.recent_discard.length > 0)
            ? data.recent_discard
            : (data.top_card ? [data.top_card] : []);

        if (recent.length === 0) {
            container.innerHTML = '';
            return;
        }

        let stackHtml = '<div class="discard-pile-stack">';
        const total = recent.length;

        recent.forEach((card, idx) => {
            const isTop = (idx === total - 1);
            let stackClass = 'discard-stack-top';
            let rotVar = `--rot-top: ${getCardRotation(card.id, 0)}deg`;

            if (total === 3) {
                if (idx === 0) {
                    stackClass = 'discard-stack-deep';
                    rotVar = `--rot-deep: ${getCardRotation(card.id, -2)}deg`;
                } else if (idx === 1) {
                    stackClass = 'discard-stack-mid';
                    rotVar = `--rot-mid: ${getCardRotation(card.id, -1)}deg`;
                }
            } else if (total === 2 && idx === 0) {
                stackClass = 'discard-stack-mid';
                rotVar = `--rot-mid: ${getCardRotation(card.id, -1)}deg`;
            }

            stackHtml += renderCardHtml(card, false, isTop, idx + 1, stackClass, rotVar);
        });

        stackHtml += '</div>';
        container.innerHTML = stackHtml;
    }

    function renderCardHtml(card, canPlay, isTopDiscard, zIndex = 1, extraClass = '', extraStyle = '') {
        const canPlayClass = canPlay ? 'can-play' : '';
        const topClass = isTopDiscard ? 'top-discard-card' : '';
        const colorClass = `card-${card.color}`;
        const finalClass = [canPlayClass, topClass, extraClass].filter(Boolean).join(' ');

        const styleParts = [];
        if (!isTopDiscard) styleParts.push(`z-index: ${zIndex}`);
        if (extraStyle) styleParts.push(extraStyle);
        const styleAttr = styleParts.length > 0 ? `style="${styleParts.join('; ')}"` : '';

        const isFrench = ['hearts', 'diamonds', 'spades', 'clubs'].includes(card.color);

        if (isFrench) {
            const suitSymbols = { 'hearts': '♥', 'diamonds': '♦', 'spades': '♠', 'clubs': '♣' };
            const suit = card.suit || suitSymbols[card.color] || '♠';
            const rank = card.rank || (card.value ? String(card.value) : '7');

            // Regelhinweis-Badge (dezent in der Ecke): 7 = +2, Bube = Farbwahl ★, Ass = Aussetzen ⊘
            let badge = '';
            if (card.value === 7) badge = '<span class="card-rule-badge">+2</span>';
            else if (card.type === 'jack') badge = '<span class="card-rule-badge">★</span>';
            else if (card.value === 14 || card.type === 'skip') badge = '<span class="card-rule-badge">⊘</span>';

            let centerContent = '';
            if (['K', 'D', 'B', 'A'].includes(rank)) {
                centerContent = `
                    <div class="french-face-card">
                        <span class="french-face-rank">${rank}</span>
                        <span class="french-face-suit">${suit}</span>
                    </div>
                `;
            } else {
                centerContent = `
                    <div class="french-number-card">
                        <span class="french-number-val">${rank}</span>
                        <span class="french-number-suit">${suit}</span>
                    </div>
                `;
            }

            return `
                <div class="game-card card-french ${colorClass} ${finalClass}" 
                     ${styleAttr}
                     data-card-id="${card.id}" 
                     data-card-type="${card.type}"
                     data-card-color="${card.color}">
                    <div class="card-corner-top">
                        <span class="corner-rank">${rank}</span>
                        <span class="corner-suit">${suit}</span>
                        ${badge}
                    </div>
                    <div class="card-center-symbol">${centerContent}</div>
                    <div class="card-corner-bottom">
                        <span class="corner-rank">${rank}</span>
                        <span class="corner-suit">${suit}</span>
                    </div>
                </div>
            `;
        }

        // Uno / Uno Extreme
        const isWild = (card.color === 'wild');
        let centerInnerHtml = '';

        if (isWild) {
            let wildSymbol = '★';
            if (card.type === 'wild_draw4') {
                wildSymbol = '<span class="uno-wild-plus4">+4</span>';
            } else if (card.type === 'extreme_hit') {
                wildSymbol = '⚡★';
            }
            centerInnerHtml = `
                <div class="uno-wild-oval">
                    <div class="uno-center-text">${wildSymbol}</div>
                </div>
            `;
        } else {
            let symbolHtml = HubHtml.escape(card.label || card.rank || '');
            if (card.type === 'reverse') {
                symbolHtml = '<span class="uno-reverse-arrows">⇄</span>';
            } else if (card.type === 'skip') {
                symbolHtml = '<span class="uno-skip-symbol">⊘</span>';
            } else if (card.type === 'draw2') {
                symbolHtml = '<span class="uno-draw2-symbol">+2</span>';
            }
            centerInnerHtml = `
                <div class="uno-center-oval">
                    <div class="uno-center-text uno-text-${card.color}">${symbolHtml}</div>
                </div>
            `;
        }

        const cornerDisplay = HubHtml.escape(card.label || card.rank || '');
        return `
            <div class="game-card card-uno ${colorClass} ${finalClass}" 
                 ${styleAttr}
                 data-card-id="${card.id}" 
                 data-card-type="${card.type}"
                 data-card-color="${card.color}">
                <div class="card-corner-top">
                    <span class="corner-uno-val">${cornerDisplay}</span>
                </div>
                <div class="card-center-uno">${centerInnerHtml}</div>
                <div class="card-corner-bottom">
                    <span class="corner-uno-val">${cornerDisplay}</span>
                </div>
            </div>
        `;
    }

    function animateCardFly(sourceEl, targetEl, onArrival) {
        if (!sourceEl || !targetEl) {
            if (onArrival) onArrival();
            return;
        }

        const fromRect = sourceEl.getBoundingClientRect();
        const toRect = targetEl.getBoundingClientRect();

        const clone = sourceEl.cloneNode(true);
        clone.classList.remove('can-play', 'top-discard-card', 'discard-stack-top', 'discard-stack-mid', 'discard-stack-deep');
        clone.classList.add('flying-card-clone');

        clone.style.top = `${fromRect.top}px`;
        clone.style.left = `${fromRect.left}px`;
        clone.style.width = `${fromRect.width}px`;
        clone.style.height = `${fromRect.height}px`;
        clone.style.transform = 'translate3d(0, 0, 0) scale(1) rotate(0deg)';
        document.body.appendChild(clone);

        sourceEl.style.opacity = '0';

        const cardId = sourceEl.dataset.cardId || '';
        const targetRot = getCardRotation(cardId, 0);

        const deltaX = (toRect.left + toRect.width / 2) - (fromRect.left + fromRect.width / 2);
        const deltaY = (toRect.top + toRect.height / 2) - (fromRect.top + fromRect.height / 2);

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                clone.style.transform = `translate3d(${deltaX}px, ${deltaY}px, 0) scale(1.04) rotate(${targetRot}deg)`;
            });
        });

        setTimeout(() => {
            AudioEngine.playCardSound();
            clone.remove();
            if (onArrival) onArrival();
        }, 360);
    }

    function animateOpponentCardFly(opponentSlot, targetEl, playedCard) {
        if (!opponentSlot || !targetEl || !playedCard) return;

        const fromRect = opponentSlot.getBoundingClientRect();
        const toRect = targetEl.getBoundingClientRect();

        const tempCont = document.createElement('div');
        tempCont.innerHTML = renderCardHtml(playedCard, false, false);
        const cardNode = tempCont.firstElementChild;
        if (!cardNode) return;

        cardNode.classList.remove('can-play', 'top-discard-card');
        cardNode.classList.add('flying-card-clone');

        cardNode.style.top = `${fromRect.top + fromRect.height / 4}px`;
        cardNode.style.left = `${fromRect.left + fromRect.width / 4}px`;
        cardNode.style.transform = 'translate3d(0, 0, 0) scale(0.35) rotate(0deg)';
        cardNode.style.opacity = '0.9';
        document.body.appendChild(cardNode);

        const targetRot = getCardRotation(playedCard.id, 0);
        const deltaX = (toRect.left + toRect.width / 2) - (fromRect.left + fromRect.width / 4 + 30);
        const deltaY = (toRect.top + toRect.height / 2) - (fromRect.top + fromRect.height / 4 + 40);

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                cardNode.style.transform = `translate3d(${deltaX}px, ${deltaY}px, 0) scale(1.04) rotate(${targetRot}deg)`;
                cardNode.style.opacity = '1';
            });
        });

        setTimeout(() => {
            AudioEngine.playCardSound();
            cardNode.remove();
        }, 360);
    }

    function animateDrawFlight(count, sourceEl, targetEl, gameMode, onComplete) {
        if (!sourceEl || !targetEl || count <= 0) {
            if (onComplete) onComplete();
            return;
        }

        const fromRect = sourceEl.getBoundingClientRect();
        const toRect = targetEl.getBoundingClientRect();
        const isFrench = (gameMode === 'mau_mau');
        const cardBackContent = isFrench ? '🂠' : 'UNO';
        const cardBackClass = isFrench ? 'card-back-french' : 'card-back-uno';

        // Bis zu 8 Karten animieren
        const animCount = Math.min(count, 8);
        const staggerDelay = (gameMode === 'uno_extreme') ? 85 : 110;

        let completed = 0;

        for (let i = 0; i < animCount; i++) {
            setTimeout(() => {
                const flyCard = document.createElement('div');
                flyCard.className = `flying-draw-card ${cardBackClass}`;
                flyCard.innerHTML = `<span class="draw-fly-logo">${cardBackContent}</span>`;

                const startLeft = fromRect.left + (fromRect.width / 2) - 36;
                const startTop = fromRect.top + (fromRect.height / 2) - 53;
                flyCard.style.left = `${startLeft}px`;
                flyCard.style.top = `${startTop}px`;
                flyCard.style.transform = 'translate3d(0, 0, 0) scale(0.6) rotate(0deg)';
                flyCard.style.opacity = '0.9';
                document.body.appendChild(flyCard);

                const spreadOffset = (animCount > 1) ? ((i - (animCount - 1) / 2) * 16) : 0;
                const destX = (toRect.left + toRect.width / 2 + spreadOffset) - (startLeft + 36);
                const destY = (toRect.top + toRect.height / 2) - (startTop + 53);
                const destRot = (i % 2 === 0 ? 1 : -1) * (4 + (i * 2));

                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        flyCard.style.transform = `translate3d(${destX}px, ${destY}px, 0) scale(1) rotate(${destRot}deg)`;
                        flyCard.style.opacity = '1';
                    });
                });

                setTimeout(() => {
                    flyCard.style.opacity = '0';
                    flyCard.style.transform += ' scale(0.85)';
                    setTimeout(() => flyCard.remove(), 150);

                    completed++;
                    if (completed >= animCount && onComplete) {
                        onComplete();
                    }
                }, 380);
            }, i * staggerDelay);
        }
    }

    function triggerUnoShoutOverlay(callerName, callWord, wasMe) {
        let overlay = document.getElementById('uno-shout-banner-popup');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'uno-shout-banner-popup';
            overlay.className = 'uno-shout-banner-popup';
            const arena = document.querySelector('.cards-table-arena') || document.body;
            arena.appendChild(overlay);
        }

        const titleText = wasMe ? 'Du rufst' : `${HubHtml.escape(callerName)} ruft`;
        overlay.innerHTML = `
            <div class="uno-shout-banner-box ${wasMe ? 'shout-box-me' : 'shout-box-other'}">
                <div class="uno-shout-banner-caller">📢 ${titleText}</div>
                <div class="uno-shout-banner-word">${callWord}!</div>
            </div>
        `;

        // Animation neu starten
        overlay.classList.remove('show');
        void overlay.offsetWidth; // Reflow erzwingen
        overlay.classList.add('show');

        if (overlay._hideTimeout) clearTimeout(overlay._hideTimeout);
        overlay._hideTimeout = setTimeout(() => {
            overlay.classList.remove('show');
        }, 1800);
    }

    function onCardClicked(card, data) {
        currentRoomData = data;
        const needsColor = (card.type === 'wild' || card.type === 'wild_draw4' || card.type === 'extreme_hit' || card.type === 'jack');

        if (needsColor) {
            pendingCardPlay = card;
            openColorChoiceModal(data.game_mode);
        } else {
            handleCardPlayWithUnoCheck(card.id, null, data, card);
        }
    }

    function handleCardPlayWithUnoCheck(cardId, chosenColor, data, cardObj = null) {
        const myPlayer = data?.players?.find(p => p.is_me);
        const hand = myPlayer?.cards || [];
        const playedCard = cardObj || hand.find(c => c.id === cardId);

        let remainingCount = 0;
        if (playedCard && playedCard.type === 'discard_all') {
            // Discard All wirft diese Karte sowie alle weiteren Handkarten derselben Farbe ab
            const remainingCards = hand.filter(c => c.id !== cardId && c.color !== playedCard.color);
            remainingCount = remainingCards.length;
        } else {
            remainingCount = Math.max(0, hand.length - 1);
        }

        // Wenn nach dem Legen genau 1 Karte übrig bleibt und vor dem Klick noch nicht gerufen wurde:
        if (remainingCount === 1 && !unoCalledLocally && !(myPlayer?.has_called_uno)) {
            promptUnoCountdown(cardId, chosenColor, data?.game_mode || 'mau_mau');
        } else {
            executePlayCard(cardId, chosenColor);
        }
    }

    function promptUnoCountdown(cardId, chosenColor, gameMode) {
        const modal = document.getElementById('modal-uno-countdown');
        const btn = document.getElementById('btn-trigger-uno-countdown');
        const timerBar = document.getElementById('uno-timer-bar');
        const titleEl = document.getElementById('uno-countdown-title');
        const callWord = (gameMode === 'mau_mau') ? 'MAU!' : 'UNO!';

        if (!modal || !btn) {
            executePlayCard(cardId, chosenColor);
            return;
        }

        if (titleEl) titleEl.textContent = 'Vorletzte Karte!';
        btn.textContent = `📢 ${callWord} RUFEN!`;
        modal.classList.remove('hidden');

        const startTime = Date.now();
        const duration = 2000;

        if (timerBar) {
            timerBar.style.width = '100%';
        }

        const updateBar = () => {
            const elapsed = Date.now() - startTime;
            const remaining = Math.max(0, 1 - (elapsed / duration));
            if (timerBar) {
                timerBar.style.width = `${remaining * 100}%`;
            }
            if (elapsed < duration) {
                unoAnimFrame = requestAnimationFrame(updateBar);
            }
        };
        unoAnimFrame = requestAnimationFrame(updateBar);

        let resolved = false;
        const cleanup = () => {
            if (unoCountdownTimer) clearTimeout(unoCountdownTimer);
            if (unoAnimFrame) cancelAnimationFrame(unoAnimFrame);
            modal.classList.add('hidden');
            btn.onclick = null;
        };

        // Klick auf den großen Button: Spieler ruft rechtzeitig!
        btn.onclick = () => {
            if (resolved) return;
            resolved = true;
            cleanup();
            unoCalledLocally = true;
            AudioEngine.playUnoSound();
            executePlayCard(cardId, chosenColor);
        };

        // Nach 2 Sekunden: Verpasst! Spieler hat vergessen zu rufen!
        unoCountdownTimer = setTimeout(() => {
            if (resolved) return;
            resolved = true;
            cleanup();
            unoCalledLocally = false; // Nicht gerufen
            executePlayCard(cardId, chosenColor);
        }, duration);
    }

    function openColorChoiceModal(gameMode) {
        const modal = document.getElementById('modal-color-choice');
        const grid = document.getElementById('color-choice-container');
        const iconEl = document.getElementById('color-choice-modal-icon');
        if (!modal || !grid) return;

        if (gameMode === 'mau_mau') {
            if (iconEl) iconEl.textContent = '♠ ♥ ♦ ♣';

            const frenchColors = [
                { id: 'hearts', label: 'Herz', symbol: '♥', colorClass: 'french-red' },
                { id: 'diamonds', label: 'Karo', symbol: '♦', colorClass: 'french-red' },
                { id: 'spades', label: 'Pik', symbol: '♠', colorClass: 'french-black' },
                { id: 'clubs', label: 'Kreuz', symbol: '♣', colorClass: 'french-black' },
            ];

            let html = '';
            frenchColors.forEach(c => {
                html += `
                    <button type="button" class="color-choice-french-btn ${c.colorClass}" data-color="${c.id}" title="${c.label}">
                        <span class="french-btn-symbol">${c.symbol}</span>
                        <span class="french-btn-label">${c.label}</span>
                    </button>
                `;
            });
            grid.innerHTML = html;
        } else {
            if (iconEl) iconEl.textContent = '🌈';

            const unoColors = [
                { id: 'red', label: 'Rot', bg: '#ef4444' },
                { id: 'yellow', label: 'Gelb', bg: '#ca8a04' },
                { id: 'green', label: 'Grün', bg: '#22c55e' },
                { id: 'blue', label: 'Blau', bg: '#3b82f6' },
            ];

            let html = '';
            unoColors.forEach(c => {
                html += `
                    <button type="button" class="color-choice-btn" data-color="${c.id}" style="background: ${c.bg};">
                        ${c.label}
                    </button>
                `;
            });
            grid.innerHTML = html;
        }

        grid.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                const chosen = btn.dataset.color;
                modal.classList.add('hidden');
                if (pendingCardPlay) {
                    const card = pendingCardPlay;
                    pendingCardPlay = null;
                    handleCardPlayWithUnoCheck(card.id, chosen, currentRoomData, card);
                }
            });
        });

        modal.classList.remove('hidden');
    }

    async function executePlayCard(cardId, chosenColor) {
        if (isSubmitting) return;
        isSubmitting = true;

        // Fluganimation der Karte von der Hand auf den Ablagestapel
        const cardEl = document.querySelector(`.game-card[data-card-id="${cardId}"]`);
        const targetEl = document.getElementById('discard-pile-container');
        if (cardEl && targetEl) {
            animateCardFly(cardEl, targetEl);
        } else {
            AudioEngine.playCardSound();
        }

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
            const isExtreme = (currentRoomData?.game_mode === 'uno_extreme');

            if (isExtreme) {
                AudioEngine.playLauncherSound(count);
            } else {
                AudioEngine.playDrawSound();
            }

            const sourceEl = document.getElementById('extreme-launcher-btn') || document.getElementById('deck-draw-btn');
            const targetEl = document.getElementById('player-hand-fanned');

            if (count > 0 && sourceEl && targetEl) {
                animateDrawFlight(count, sourceEl, targetEl, currentRoomData?.game_mode, () => {
                    unoCalledLocally = false;
                    fetchState(true);
                });
            } else {
                unoCalledLocally = false;
                fetchState(true);
            }
        } else {
            HubDialog.alert('Hinweis', res.data?.message || 'Aktion nicht möglich.', '⚠️');
        }
    }

    function renderFinished(data, myPlayer) {
        const winner = data.players.find(p => p.id === data.winner_id);
        const nameEl = document.getElementById('winner-name-display');
        const descEl = document.getElementById('winner-desc');

        if (nameEl) {
            nameEl.textContent = winner ? `${winner.name} hat gewonnen!` : 'Spiel beendet!';
        }
        if (descEl) {
            descEl.textContent = 'Herzlichen Glückwunsch! Alle Karten wurden meisterhaft abgelegt.';
        }

        const isHost = myPlayer ? myPlayer.is_host : false;
        const rematchBtn = document.getElementById('btn-rematch');
        if (rematchBtn) {
            if (isHost) {
                rematchBtn.classList.remove('hidden');
            } else {
                rematchBtn.classList.add('hidden');
            }
        }

        AudioEngine.playVictorySound();
    }
})();

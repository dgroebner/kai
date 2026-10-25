/**
 * LOCH 6 - Client Controller (Quarxl Games Hub)
 * Push-Your-Luck Würfelspiel mit Mineral-Steinen
 */
(() => {
    'use strict';

    const roomCode = (document.body.dataset.room || '').trim().toUpperCase();
    const API_URL = `./api.php`;

    // State Caches
    let myPlayerId = null;
    let selectedStoneId = null;
    let isRollingAnimation = false;
    let isAnimatingMove = false;
    let lastKnownRollSeq = 0;
    let pollTimer = null;
    let currentGameState = null;

    // Web Audio Engine für Haptik & Sound-Effekte
    const AudioEngine = {
        ctx: null,
        init() {
            if (!this.ctx) {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (AudioCtx) this.ctx = new AudioCtx();
            }
            if (this.ctx && this.ctx.state === 'suspended') {
                this.ctx.resume();
            }
        },
        playDiceRoll() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            for (let i = 0; i < 5; i++) {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                const f = 180 + Math.random() * 140;
                osc.frequency.setValueAtTime(f, now + i * 0.04);
                gain.gain.setValueAtTime(0.2, now + i * 0.04);
                gain.gain.linearRampToValueAtTime(0.01, now + (i + 1) * 0.045);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + i * 0.04);
                osc.stop(now + (i + 1) * 0.05);
            }
            // Lande-Aufschlag
            const landOsc = this.ctx.createOscillator();
            const landGain = this.ctx.createGain();
            landOsc.type = 'sine';
            landOsc.frequency.setValueAtTime(140, now + 0.22);
            landOsc.frequency.exponentialRampToValueAtTime(45, now + 0.3);
            landGain.gain.setValueAtTime(0.35, now + 0.22);
            landGain.gain.linearRampToValueAtTime(0.01, now + 0.31);
            landOsc.connect(landGain);
            landGain.connect(this.ctx.destination);
            landOsc.start(now + 0.22);
            landOsc.stop(now + 0.32);
        },
        playStonePlaced() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            // Kristallines Klingen (hoher Ping + hölzerner Mulden-Tap)
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(980, now);
            osc.frequency.exponentialRampToValueAtTime(520, now + 0.12);
            gain.gain.setValueAtTime(0.3, now);
            gain.gain.exponentialRampToValueAtTime(0.005, now + 0.15);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.16);

            const wood = this.ctx.createOscillator();
            const woodGain = this.ctx.createGain();
            wood.type = 'triangle';
            wood.frequency.setValueAtTime(220, now);
            wood.frequency.exponentialRampToValueAtTime(70, now + 0.08);
            woodGain.gain.setValueAtTime(0.25, now);
            woodGain.gain.linearRampToValueAtTime(0.01, now + 0.09);
            wood.connect(woodGain);
            woodGain.connect(this.ctx.destination);
            wood.start(now);
            wood.stop(now + 0.1);
        },
        playHole6Abyss() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            // Tiefer, fallender Bass-Schlund-Effekt
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(320, now);
            osc.frequency.exponentialRampToValueAtTime(40, now + 0.45);
            gain.gain.setValueAtTime(0.4, now);
            gain.gain.exponentialRampToValueAtTime(0.01, now + 0.5);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.52);
        },
        playBust() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            // Kleben geblieben: Dissonanter Zweiklang + Rutschgeräusch
            [260, 245].forEach((freq) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(freq, now);
                osc.frequency.exponentialRampToValueAtTime(110, now + 0.28);
                gain.gain.setValueAtTime(0.2, now);
                gain.gain.linearRampToValueAtTime(0.01, now + 0.3);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now);
                osc.stop(now + 0.32);
            });
        },
        playVictory() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            [523.25, 659.25, 783.99, 1046.50].forEach((freq, i) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, now + i * 0.12);
                gain.gain.setValueAtTime(0.3, now + i * 0.12);
                gain.gain.linearRampToValueAtTime(0.01, now + (i + 1) * 0.16);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + i * 0.12);
                osc.stop(now + (i + 1) * 0.17);
            });
        }
    };

    const PIPS_MAP = {
        1: ['pip-center'],
        2: ['pip-top-left', 'pip-bottom-right'],
        3: ['pip-top-left', 'pip-center', 'pip-bottom-right'],
        4: ['pip-top-left', 'pip-top-right', 'pip-bottom-left', 'pip-bottom-right'],
        5: ['pip-top-left', 'pip-top-right', 'pip-center', 'pip-bottom-left', 'pip-bottom-right'],
        6: ['pip-top-left', 'pip-top-right', 'pip-mid-left', 'pip-mid-right', 'pip-bottom-left', 'pip-bottom-right']
    };

    function renderDie(dieId, val) {
        const el = document.getElementById(dieId);
        if (!el) return;
        el.dataset.val = val;

        const pips = PIPS_MAP[val] || ['pip-center'];
        let pipsHtml = '';
        pips.forEach(pos => {
            pipsHtml += `<span class="die-pip ${pos}"></span>`;
        });
        el.innerHTML = pipsHtml;
    }

    function playDiceRollAnimation(targetVal, onComplete) {
        if (isRollingAnimation) return;
        isRollingAnimation = true;

        const diceContainer = document.getElementById('dice-container');
        const die1 = document.getElementById('die-1');
        const statusLine = document.getElementById('dice-status-line');

        if (diceContainer) diceContainer.classList.add('is-rolling');
        if (die1) die1.classList.remove('landing');

        if (statusLine) {
            statusLine.innerHTML = 'Augen: <strong>🎲 ...</strong>';
        }

        AudioEngine.playDiceRoll();

        if ('vibrate' in navigator) {
            try { navigator.vibrate([25, 35, 25]); } catch (_) {}
        }

        let ticks = 0;
        const maxTicks = 7;
        const interval = setInterval(() => {
            ticks++;
            const rnd = Math.floor(Math.random() * 6) + 1;
            renderDie('die-1', rnd);

            if (ticks >= maxTicks) {
                clearInterval(interval);
                if (diceContainer) diceContainer.classList.remove('is-rolling');

                const finalVal = parseInt(targetVal, 10) || 1;
                renderDie('die-1', finalVal);

                if (die1) die1.classList.add('landing');
                setTimeout(() => {
                    if (die1) die1.classList.remove('landing');
                }, 400);

                isRollingAnimation = false;
                if (typeof onComplete === 'function') {
                    onComplete();
                }
            }
        }, 65);
    }

    // Hilfsfunktion: Mineral-Kristall HTML erzeugen (Echte Trommelsteine / polierte Cabochons)
    function createCrystalElement(stone, inTray = false) {
        const type = stone.type || 'amethyst';
        const seed = parseInt(stone.seed || 100, 10);
        // Kleine Asymmetrie und Streuung basierend auf Seed
        const rot = (seed % 28) - 14;
        const scale = 0.94 + ((seed % 14) / 100);

        // Organische ovale Trommelstein-Kontur berechnen
        const r1 = 46 + (seed % 9);
        const r2 = 100 - r1;
        const r3 = 48 + ((seed * 3) % 8);
        const r4 = 100 - r3;
        const r5 = 54 + ((seed * 7) % 8);
        const r6 = 100 - r5;
        const r7 = 48 + ((seed * 5) % 8);
        const r8 = 100 - r7;
        const borderRadius = `${r1}% ${r2}% ${r3}% ${r4}% / ${r5}% ${r6}% ${r7}% ${r8}%`;

        const el = document.createElement('div');
        el.className = `crystal-gem crystal-${type}`;
        el.style.transform = `scale(${scale}) rotate(${rot}deg)`;
        el.style.borderRadius = borderRadius;
        el.title = `${type.toUpperCase()} (#${seed})`;
        if (inTray) {
            el.dataset.stoneId = stone.id || '';
        }
        return el;
    }

    // Flug-Animation eines Steins aus der Hand in die Mulde
    function playStoneFlightToSlot(targetSlotEl, stone, onComplete) {
        if (!targetSlotEl) {
            AudioEngine.playStonePlaced();
            if (typeof onComplete === 'function') onComplete();
            return;
        }

        // Sicherstellen, dass die Zielmulde vor und während des Flugs absolut leer ist!
        targetSlotEl.innerHTML = '';
        delete targetSlotEl.dataset.currentStoneId;

        const grid = document.getElementById('my-crystals-grid');
        const sourceStoneEl = grid ? (grid.querySelector(`.crystal-gem[data-stone-id="${stone?.id}"]`) || grid.querySelector('.crystal-gem')) : null;

        if (sourceStoneEl) {
            sourceStoneEl.style.opacity = '0';
        }

        const toRect = targetSlotEl.getBoundingClientRect();
        const fromRect = sourceStoneEl ? sourceStoneEl.getBoundingClientRect() : {
            left: window.innerWidth / 2 - 20,
            top: window.innerHeight - 80,
            width: 40,
            height: 33
        };

        const clone = createCrystalElement(stone || { type: 'amethyst', seed: 100 }, false);
        clone.className = `crystal-gem crystal-${stone?.type || 'amethyst'} flying-stone-clone`;
        clone.style.left = `${fromRect.left}px`;
        clone.style.top = `${fromRect.top}px`;
        clone.style.width = `${fromRect.width}px`;
        clone.style.height = `${fromRect.height}px`;
        document.body.appendChild(clone);

        requestAnimationFrame(() => {
            const dx = (toRect.left + toRect.width / 2) - (fromRect.left + fromRect.width / 2);
            const dy = (toRect.top + toRect.height / 2) - (fromRect.top + fromRect.height / 2);
            const scale = (toRect.width * 0.85) / fromRect.width;
            clone.style.transform = `translate3d(${dx}px, ${dy}px, 0) scale(${scale})`;

            setTimeout(() => {
                if (clone.parentNode) clone.parentNode.removeChild(clone);
                AudioEngine.playStonePlaced();

                // ERST JETZT wird der echte Stein in die Mulde gelegt!
                if (stone) {
                    targetSlotEl.innerHTML = '';
                    const gemEl = createCrystalElement(stone, false);
                    gemEl.classList.add('just-placed');
                    targetSlotEl.appendChild(gemEl);
                    targetSlotEl.dataset.currentStoneId = stone.id || `${stone.type}-${stone.seed}`;
                }

                if (typeof onComplete === 'function') onComplete();
            }, 390);
        });
    }

    // Bust-Animation: Alle Steine vom Brett fliegen nach unten in die Hand
    function playBustFlyAnimation(onComplete) {
        const boardStones = document.querySelectorAll('.pit-crystal-slot .crystal-gem');
        if (boardStones.length === 0) {
            AudioEngine.playBust();
            if (typeof onComplete === 'function') onComplete();
            return;
        }

        AudioEngine.playBust();
        boardStones.forEach((stoneEl, idx) => {
            stoneEl.style.animationDelay = `${idx * 0.04}s`;
            stoneEl.classList.add('stone-bust-flying');
        });

        setTimeout(() => {
            boardStones.forEach(el => {
                if (el.parentNode) el.parentNode.removeChild(el);
            });
            if (typeof onComplete === 'function') onComplete();
        }, 480);
    }

    // Initialisierung beim Laden
    document.addEventListener('DOMContentLoaded', () => {
        initEvents();
        checkJoinAndStart();
    });

    function initEvents() {
        // Name ändern
        document.querySelectorAll('.js-btn-edit-name').forEach(btn => {
            btn.addEventListener('click', async () => {
                const curName = localStorage.getItem('kai_gh_player_name') || 'Ich';
                const newName = window.prompt('Dein neuer Spielername:', curName);
                if (newName && newName.trim() && newName.trim() !== curName) {
                    const clean = newName.trim().substring(0, 25);
                    localStorage.setItem('kai_gh_player_name', clean);
                    document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = clean);
                }
            });
        });

        // WhatsApp Share
        document.querySelectorAll('.js-share-whatsapp').forEach(btn => {
            btn.addEventListener('click', () => {
                const url = window.location.href;
                const text = `Komm an meinen Spieltisch für Loch 6 auf Quarxl! Raum-Code: ${roomCode}\n${url}`;
                window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
            });
        });

        // Bot hinzufügen
        const addBotBtn = document.getElementById('btn-add-bot');
        if (addBotBtn) {
            addBotBtn.addEventListener('click', async () => {
                addBotBtn.disabled = true;
                try {
                    const res = await HubHttp.postJson(`${API_URL}?action=add_bot`, {
                        room: roomCode
                    });
                    if (res.ok && res.data && res.data.success) {
                        fetchState();
                    } else {
                        await HubDialog.alert('Fehler', res.data?.message || 'Konnte Bot nicht hinzufügen.', '⚠️');
                    }
                } catch (e) {
                    console.error(e);
                } finally {
                    addBotBtn.disabled = false;
                }
            });
        }

        // Spiel starten
        const startBtn = document.getElementById('btn-start-game');
        if (startBtn) {
            startBtn.addEventListener('click', async () => {
                startBtn.disabled = true;
                try {
                    const res = await HubHttp.postJson(`${API_URL}?action=start`, {
                        room: roomCode
                    });
                    if (res.ok && res.data && res.data.success) {
                        fetchState();
                    } else {
                        await HubDialog.alert('Fehler', res.data?.message || 'Konnte Spiel nicht starten.', '⚠️');
                    }
                } catch (e) {
                    console.error(e);
                } finally {
                    startBtn.disabled = false;
                }
            });
        }

        // Würfeln (Aktion)
        const rollBtn = document.getElementById('btn-roll-dice');
        if (rollBtn) {
            rollBtn.addEventListener('click', handleRollClick);
        }

        // Sichern & Weitergeben (Aktion)
        const passBtn = document.getElementById('btn-pass-turn');
        if (passBtn) {
            passBtn.addEventListener('click', handlePassClick);
        }

        // Revanche
        const rematchBtn = document.getElementById('btn-rematch');
        if (rematchBtn) {
            rematchBtn.addEventListener('click', async () => {
                rematchBtn.disabled = true;
                try {
                    const res = await HubHttp.postJson(`${API_URL}?action=rematch`, {
                        room: roomCode
                    });
                    if (res.ok && res.data && res.data.success) {
                        fetchState();
                    } else {
                        await HubDialog.alert('Hinweis', res.data?.message || 'Revanche nicht möglich.', 'ℹ️');
                    }
                } catch (e) {
                    console.error(e);
                } finally {
                    rematchBtn.disabled = false;
                }
            });
        }
    }

    async function checkJoinAndStart() {
        if (!roomCode) {
            await HubDialog.alert('Hinweis', 'Kein Raum-Code angegeben.', '⚠️');
            window.location.href = '../index.php';
            return;
        }

        const token = localStorage.getItem('kai_gh_player_token');
        if (!token) {
            openJoinModal();
            return;
        }

        const ok = await fetchState(true);
        if (!ok) {
            openJoinModal();
        } else {
            startPolling();
        }
    }

    function openJoinModal() {
        const modal = document.getElementById('join-modal');
        const nameInput = document.getElementById('join-player-name');
        const confirmBtn = modal?.querySelector('.js-btn-confirm-join');
        const errBox = document.getElementById('join-error');

        if (!modal) return;
        modal.classList.remove('hidden');

        const savedName = localStorage.getItem('kai_gh_player_name') || '';
        if (nameInput) {
            if (savedName) nameInput.value = savedName;
            nameInput.focus();
            nameInput.onkeydown = (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    confirmBtn?.click();
                }
            };
        }

        if (confirmBtn) {
            confirmBtn.onclick = async () => {
                const name = nameInput?.value.trim() || 'Gast';
                if (errBox) errBox.classList.add('hidden');
                confirmBtn.disabled = true;

                try {
                    const res = await HubHttp.postJson(`${API_URL}?action=join`, {
                        room: roomCode,
                        player_name: name
                    });

                    if (res.ok && res.data && res.data.success) {
                        if (res.data.player_token) {
                            localStorage.setItem('kai_gh_player_token', res.data.player_token);
                        }
                        localStorage.setItem('kai_gh_player_name', name);
                        modal.classList.add('hidden');
                        document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = name);
                        await fetchState(true);
                        startPolling();
                    } else {
                        if (errBox) {
                            errBox.textContent = res.data?.message || 'Beitritt fehlgeschlagen.';
                            errBox.classList.remove('hidden');
                        }
                    }
                } catch (e) {
                    if (errBox) {
                        errBox.textContent = 'Netzwerkfehler beim Beitritt.';
                        errBox.classList.remove('hidden');
                    }
                } finally {
                    confirmBtn.disabled = false;
                }
            };
        }
    }

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(() => {
            fetchState(false);
        }, 1500);
    }

    async function fetchState(initial = false) {
        if (isAnimatingMove && !initial) {
            // Während der Würfel- und Fluganimation nicht vorzeitig den Brett-Zustand überschreiben!
            return true;
        }

        try {
            const res = await HubHttp.getJson(`${API_URL}?action=state&room=${encodeURIComponent(roomCode)}`);
            if (!res.ok || !res.data || !res.data.success) {
                if (initial) return false;
                return;
            }

            renderAll(res.data.data);
            return true;
        } catch (e) {
            console.warn('Loch6 state poll error:', e);
            return false;
        }
    }

    async function handleRollClick() {
        if (isAnimatingMove) return;
        const rollBtn = document.getElementById('btn-roll-dice');
        if (rollBtn) rollBtn.disabled = true;

        const sentStoneId = selectedStoneId;
        isAnimatingMove = true; // Sperrt Polling-Updates auf dem Brett während Wurf & Flug!

        try {
            const res = await HubHttp.postJson(`${API_URL}?action=roll`, {
                room: roomCode,
                stone_id: sentStoneId
            });

            if (!res.ok || !res.data || !res.data.success) {
                isAnimatingMove = false;
                await HubDialog.alert('Hinweis', res.data?.message || 'Wurf nicht möglich.', 'ℹ️');
                return;
            }

            selectedStoneId = null; // Nach dem Wurf Auswahl zurücksetzen

            const outcomeData = res.data;
            const dieVal = outcomeData.die || 1;
            lastKnownRollSeq = ((currentGameState?.roll_seq) || 0) + 1;

            playDiceRollAnimation(dieVal, () => {
                if (outcomeData.outcome === 'hole6' || outcomeData.outcome === 'hole6_win') {
                    AudioEngine.playHole6Abyss();
                    if (outcomeData.game_over) AudioEngine.playVictory();
                    isAnimatingMove = false;
                    fetchState();
                } else if (outcomeData.outcome === 'bust') {
                    playBustFlyAnimation(() => {
                        isAnimatingMove = false;
                        fetchState();
                    });
                } else {
                    // Stein erfolgreich in Mulde abgelegt -> Fluganimation
                    const targetSlot = document.getElementById(`slot-${outcomeData.die}-${outcomeData.slot ?? 0}`);
                    playStoneFlightToSlot(targetSlot, outcomeData.placed_stone || null, () => {
                        if (outcomeData.game_over) AudioEngine.playVictory();
                        isAnimatingMove = false;
                        fetchState();
                    });
                }
            });
        } catch (e) {
            console.error('Loch6 roll error:', e);
            isAnimatingMove = false;
        } finally {
            if (rollBtn) rollBtn.disabled = false;
        }
    }

    async function handlePassClick() {
        const passBtn = document.getElementById('btn-pass-turn');
        if (passBtn) passBtn.disabled = true;

        try {
            const res = await HubHttp.postJson(`${API_URL}?action=pass`, {
                room: roomCode
            });

            if (!res.ok || !res.data || !res.data.success) {
                await HubDialog.alert('Hinweis', res.data?.message || 'Weitergeben nicht möglich.', 'ℹ️');
                return;
            }

            fetchState();
        } catch (e) {
            console.error('Loch6 pass error:', e);
        } finally {
            if (passBtn) passBtn.disabled = false;
        }
    }

    function renderAll(data) {
        currentGameState = data;
        const myPlayer = (data.players || []).find(p => p.is_me);
        if (myPlayer) {
            myPlayerId = myPlayer.id;
            document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = myPlayer.name);
        }

        // Panels umschalten
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
            renderGame(data, myPlayer);
        } else if (data.status === 'finished') {
            if (paneLobby) paneLobby.classList.add('hidden');
            if (paneGame) paneGame.classList.add('hidden');
            if (paneFinished) paneFinished.classList.remove('hidden');
            renderFinished(data, myPlayer);
        }
    }

    function renderLobby(data, myPlayer) {
        const listEl = document.getElementById('lobby-players-list');
        if (listEl) {
            let html = '';
            (data.players || []).forEach(p => {
                const hostBadge = p.is_host ? '<span style="color: #fbbf24; font-size: 0.75rem; font-weight: 700;">(Host)</span>' : '';
                const botBadge = p.is_bot ? '<span style="color: #38bdf8; font-size: 0.75rem;">🤖 KI</span>' : '';
                html += `
                    <div class="lobby-player-row">
                        <div>
                            <strong>${HubHtml.escape(p.name)}</strong> ${hostBadge} ${botBadge}
                        </div>
                        <span style="color: #22c55e; font-size: 0.8rem;">● Bereit (${p.stone_count} 💎)</span>
                    </div>
                `;
            });
            listEl.innerHTML = html;
        }

        const isHost = myPlayer ? myPlayer.is_host : false;
        const addBotBtn = document.getElementById('btn-add-bot');
        const startBtn = document.getElementById('btn-start-game');
        const waitNotice = document.getElementById('lobby-waiting-notice');

        if (isHost) {
            if (addBotBtn) {
                addBotBtn.classList.remove('hidden');
                addBotBtn.disabled = (data.players.length >= 6);
            }
            if (startBtn) startBtn.classList.remove('hidden');
            if (waitNotice) waitNotice.classList.add('hidden');
        } else {
            if (addBotBtn) addBotBtn.classList.add('hidden');
            if (startBtn) startBtn.classList.add('hidden');
            if (waitNotice) waitNotice.classList.remove('hidden');
        }
    }

    function renderBoardSlots(board) {
        for (let pit = 1; pit <= 5; pit++) {
            const slots = (board && board[pit]) ? board[pit] : [];
            for (let idx = 0; idx < pit; idx++) {
                const slotEl = document.getElementById(`slot-${pit}-${idx}`);
                if (!slotEl) continue;

                const stone = slots[idx] || null;
                if (!stone) {
                    if (slotEl.hasChildNodes()) {
                        slotEl.innerHTML = '';
                        delete slotEl.dataset.currentStoneId;
                    }
                } else {
                    const stoneId = stone.id || `${stone.type}-${stone.seed}`;
                    // Nur neu einsetzen wenn sich der Stein geändert hat (verhindert jegliches Blinken!)
                    if (slotEl.dataset.currentStoneId !== stoneId) {
                        slotEl.innerHTML = '';
                        const gemEl = createCrystalElement(stone, false);
                        slotEl.appendChild(gemEl);
                        slotEl.dataset.currentStoneId = stoneId;
                    }
                }
            }
        }
    }

    function renderGame(data, myPlayer) {
        // Runden-Pill
        const roundBadge = document.getElementById('round-indicator-badge');
        if (roundBadge) {
            if (data.round_number === 1) {
                roundBadge.textContent = 'Runde 1 (1 Pflichtwurf)';
            } else if (data.round_number === 2) {
                roundBadge.textContent = 'Runde 2 (2 Pflichtwürfe)';
            } else {
                roundBadge.textContent = `Runde ${data.round_number} (Zockerphase)`;
            }
        }

        // Event-Ticker
        const ticker = document.getElementById('event-ticker-text');
        if (ticker && data.last_event) {
            ticker.textContent = data.last_event.message || 'Spiel läuft...';
            ticker.classList.remove('highlight-bust', 'highlight-hole6');
            if (data.last_event.type === 'bust') {
                ticker.classList.add('highlight-bust');
            } else if (data.last_event.type === 'hole6_placed' || data.last_event.type === 'hole6_win') {
                ticker.classList.add('highlight-hole6');
            }
        }

        // Mitspieler-Bar rendern
        const oppBar = document.getElementById('opponents-bar');
        if (oppBar) {
            let oppHtml = '';
            (data.players || []).forEach(p => {
                const isMe = p.is_me;
                const activeClass = p.is_active ? 'is-active' : '';
                const activeTag = p.is_active ? '<span style="color: #38bdf8;">Am Zug</span>' : '';
                const botTag = p.is_bot ? '🤖 ' : '';

                let miniStonesHtml = '';
                (p.stones || []).forEach(s => {
                    const st = s.type || 'amethyst';
                    miniStonesHtml += `<span class="mini-crystal-icon crystal-${st}" title="${st}"></span>`;
                });

                oppHtml += `
                    <div class="loch6-opponent-chip ${activeClass}">
                        <div class="loch6-opponent-header">
                            <span class="loch6-opponent-name">${botTag}${HubHtml.escape(p.name)}${isMe ? ' (Du)' : ''}</span>
                            ${activeTag}
                        </div>
                        <div class="loch6-opponent-stones-badge">
                            <span>${p.stone_count} Steine</span>
                        </div>
                        <div class="loch6-opponent-mini-stones" style="display: flex; gap: 2px; flex-wrap: wrap; margin-top: 2px;">
                            ${miniStonesHtml}
                        </div>
                    </div>
                `;
            });
            oppBar.innerHTML = oppHtml;
        }

        // Board: Die 15 Mulden stabil und ohne Blinken rendern
        renderBoardSlots(data.board);

        // Board: Loch 6 / Schlund Zähler
        const abyssCounter = document.getElementById('abyss-counter');
        if (abyssCounter) {
            abyssCounter.textContent = `${data.hole6_count || 0} 💎`;
        }

        // Würfel & Animation synchronisieren (z. B. wenn Bot oder anderer Spieler gewürfelt hat)
        const currentRollSeq = Number(data.roll_seq || 0);
        if (currentRollSeq > 0 && lastKnownRollSeq > 0 && currentRollSeq > lastKnownRollSeq && !isRollingAnimation && !isAnimatingMove) {
            lastKnownRollSeq = currentRollSeq;
            isAnimatingMove = true;
            const targetVal = (data.dice && data.dice[0]) ? data.dice[0] : 1;
            playDiceRollAnimation(targetVal, () => {
                isAnimatingMove = false;
                renderGame(data, myPlayer);
            });
            return;
        }
        lastKnownRollSeq = Math.max(lastKnownRollSeq, currentRollSeq);

        const currentDieVal = (data.dice && data.dice[0]) ? data.dice[0] : 1;
        if (!isRollingAnimation) {
            renderDie('die-1', currentDieVal);

            const statusLine = document.getElementById('dice-status-line');
            if (statusLine) {
                statusLine.innerHTML = data.has_rolled
                    ? `Augen: <strong>${currentDieVal}</strong>`
                    : 'Augen: <strong>-</strong>';
            }
        }

        // Aktions-Buttons & Vorbereitung der manuellen Steinauswahl
        const isMyTurn = myPlayer ? myPlayer.is_active : false;
        const rollBtn = document.getElementById('btn-roll-dice');
        const passBtn = document.getElementById('btn-pass-turn');
        const turnPrompt = document.getElementById('turn-prompt-text');

        let selectedStoneName = '';
        if (selectedStoneId && myPlayer) {
            const found = (myPlayer.stones || []).find(s => s.id === selectedStoneId);
            if (found) {
                selectedStoneName = ` (${found.type.toUpperCase()})`;
            }
        }

        if (rollBtn) {
            if (isMyTurn) {
                rollBtn.disabled = isRollingAnimation;
                if (data.round_number === 1) {
                    rollBtn.innerHTML = `🎲 Pflichtwurf (1/1)${selectedStoneName}`;
                } else if (data.round_number === 2) {
                    const rolls = Number(data.rolls_in_turn || 0);
                    if (rolls === 0) {
                        rollBtn.innerHTML = `🎲 Pflichtwurf 1 von 2${selectedStoneName}`;
                    } else if (data.can_pass) {
                        rollBtn.innerHTML = `🎲 Nochmal Würfeln${selectedStoneName}`;
                    } else {
                        rollBtn.innerHTML = `🎲 Pflichtwurf 2 von 2${selectedStoneName}`;
                    }
                } else if (data.can_pass) {
                    rollBtn.innerHTML = `🎲 Weiterzocken${selectedStoneName}`;
                } else {
                    rollBtn.innerHTML = `🎲 WÜRFELN${selectedStoneName}`;
                }
            } else {
                rollBtn.disabled = true;
                const activeP = (data.players || []).find(p => p.is_active);
                const activeName = activeP ? activeP.name : 'Mitspieler';
                rollBtn.innerHTML = `⏳ ${HubHtml.escape(activeName)} ist am Zug...`;
            }
        }

        if (passBtn) {
            if (isMyTurn && data.can_pass) {
                passBtn.classList.remove('hidden');
                passBtn.disabled = isRollingAnimation;
            } else {
                passBtn.classList.add('hidden');
            }
        }

        if (turnPrompt) {
            if (isMyTurn) {
                if (data.round_number === 1) {
                    turnPrompt.textContent = selectedStoneId ? 'Wunsch-Stein gewählt! Jetzt Pflichtwurf ausführen.' : 'Runde 1: Genau 1 Pflichtwurf (Vorbelegung des Bretts)';
                } else if (data.round_number === 2) {
                    const rolls = Number(data.rolls_in_turn || 0);
                    if (rolls === 0) {
                        turnPrompt.textContent = selectedStoneId ? 'Wunsch-Stein gewählt! Jetzt Pflichtwurf 1 von 2 ausführen.' : 'Runde 2: 2 Pflichtwürfe! Jetzt Wurf 1 ausführen.';
                    } else if (data.can_pass) {
                        turnPrompt.textContent = '2 Pflichtwürfe geschafft! Weiterzocken oder Sichern & Weitergeben?';
                    } else {
                        turnPrompt.textContent = selectedStoneId ? 'Wunsch-Stein gewählt! Jetzt Pflichtwurf 2 von 2 ausführen.' : 'Runde 2: Pflichtwurf 1 geschafft! Jetzt Wurf 2 ausführen.';
                    }
                } else if (data.can_pass) {
                    turnPrompt.textContent = 'Guter Wurf! Weiterzocken oder Sichern & Weitergeben?';
                } else {
                    turnPrompt.textContent = selectedStoneId ? 'Wunsch-Stein gewählt! Jetzt würfeln.' : 'Du bist am Zug! Tippe optional einen Wunsch-Stein an.';
                }
            } else {
                const activeP = (data.players || []).find(p => p.is_active);
                turnPrompt.textContent = activeP ? `Warte auf Spielzug von ${activeP.name}...` : 'Warte auf Mitspieler...';
            }
        }

        // Eigene Mineralien-Ablageschale
        if (myPlayer) {
            const countBadge = document.getElementById('my-stones-count-badge');
            if (countBadge) {
                countBadge.textContent = `${myPlayer.stone_count} Steine`;
            }

            const grid = document.getElementById('my-crystals-grid');
            if (grid) {
                grid.innerHTML = '';
                const myStones = myPlayer.stones || [];
                if (myStones.length === 0) {
                    grid.innerHTML = '<span class="text-muted" style="font-size: 0.85rem; padding: 0.5rem;">Keine Steine mehr – Du gewinnst! 🎉</span>';
                } else {
                    myStones.forEach(stone => {
                        const gemEl = createCrystalElement(stone, true);
                        if (stone.id === selectedStoneId) {
                            gemEl.classList.add('is-selected');
                        }

                        // Klick zum Auswählen / Abwählen
                        gemEl.addEventListener('click', () => {
                            if (!isMyTurn) return;
                            if (selectedStoneId === stone.id) {
                                selectedStoneId = null;
                            } else {
                                selectedStoneId = stone.id;
                            }
                            renderGame(currentGameState, myPlayer);
                        });

                        grid.appendChild(gemEl);
                    });
                }
            }
        }
    }

    function renderFinished(data, myPlayer) {
        const winnerDisplay = document.getElementById('winner-name-display');
        const winnerDesc = document.getElementById('winner-desc');
        const statsList = document.getElementById('finished-stats-list');
        const rematchBtn = document.getElementById('btn-rematch');

        if (winnerDisplay) {
            winnerDisplay.textContent = `${data.winner_name || 'Jemand'} hat gewonnen!`;
        }

        if (winnerDesc) {
            if (myPlayer && myPlayer.id === data.winner_id) {
                winnerDesc.textContent = 'Fantastisch! Du hast alle deine Mineral-Steine als Erster abgebaut!';
            } else {
                winnerDesc.textContent = `${data.winner_name || 'Der Gewinner'} hat alle Steine abgebaut!`;
            }
        }

        if (statsList) {
            let html = '';
            (data.players || []).forEach(p => {
                const isWinner = (p.id === data.winner_id);
                html += `
                    <div class="finished-stat-card ${isWinner ? 'winner-card' : ''}">
                        <div>
                            <strong>${p.is_bot ? '🤖 ' : ''}${HubHtml.escape(p.name)}</strong>
                            ${isWinner ? ' 🏆 (Sieger)' : ''}
                        </div>
                        <div style="font-size: 0.85rem; color: #fbbf24;">
                            ${p.stone_count} Rest-Steine
                        </div>
                    </div>
                `;
            });
            statsList.innerHTML = html;
        }

        if (rematchBtn) {
            const isHost = myPlayer ? myPlayer.is_host : false;
            rematchBtn.classList.toggle('hidden', !isHost);
        }
    }

})();

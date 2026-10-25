/**
 * shut_the_box.js - Quarxl 4-Spieler Shut the Box
 */
(function () {
    'use strict';

    const roomCode = document.body.dataset.room || '';
    let pollTimer = null;
    let isSubmitting = false;
    let selectedFlaps = new Set();
    let currentRoomData = null;
    let lastEventTime = 0;
    let isRollingAnimation = false;
    let lastKnownRollSeq = 0;
    let lastShownSummaryRound = 0;
    let roundSummaryTimer = null;

    // Web Audio Synthesizer (Holzbrett & Würfel Soundeffekte)
    const AudioEngine = {
        ctx: null,
        init() {
            if (!this.ctx && (window.AudioContext || window.webkitAudioContext)) {
                this.ctx = new (window.AudioContext || window.webkitAudioContext)();
            }
        },
        playDiceRoll() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            // Würfelklappern (4 schnelle Impulse)
            for (let i = 0; i < 4; i++) {
                const t = now + (i * 0.06);
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(320 + (i * 40), t);
                osc.frequency.exponentialRampToValueAtTime(120, t + 0.05);
                gain.gain.setValueAtTime(0.2, t);
                gain.gain.linearRampToValueAtTime(0.01, t + 0.05);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(t);
                osc.stop(t + 0.06);
            }
        },
        playDiceLand() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            // Gedämpftes Aufschlagen im Samt-Teller
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(140, now);
            osc.frequency.exponentialRampToValueAtTime(45, now + 0.08);
            gain.gain.setValueAtTime(0.4, now);
            gain.gain.linearRampToValueAtTime(0.01, now + 0.09);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.1);
        },
        playFlapClick() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            // Kräftiges hölzernes "Klack"
            const osc = this.ctx.createOscillator();
            const gain = this.ctx.createGain();
            osc.type = 'square';
            osc.frequency.setValueAtTime(280, now);
            osc.frequency.exponentialRampToValueAtTime(80, now + 0.06);
            gain.gain.setValueAtTime(0.35, now);
            gain.gain.linearRampToValueAtTime(0.01, now + 0.07);
            osc.connect(gain);
            gain.connect(this.ctx.destination);
            osc.start(now);
            osc.stop(now + 0.08);
        },
        playShutTheBox() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            [523.25, 659.25, 783.99, 1046.50].forEach((freq, i) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(freq, now + i * 0.1);
                gain.gain.setValueAtTime(0.3, now + i * 0.1);
                gain.gain.linearRampToValueAtTime(0.01, now + (i + 1) * 0.14);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + i * 0.1);
                osc.stop(now + (i + 1) * 0.15);
            });
        },
        playVictory() {
            this.init();
            if (!this.ctx) return;
            const now = this.ctx.currentTime;
            [440, 554.37, 659.25, 880].forEach((freq, i) => {
                const osc = this.ctx.createOscillator();
                const gain = this.ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(freq, now + i * 0.14);
                gain.gain.setValueAtTime(0.3, now + i * 0.14);
                gain.gain.linearRampToValueAtTime(0.01, now + (i + 1) * 0.18);
                osc.connect(gain);
                gain.connect(this.ctx.destination);
                osc.start(now + i * 0.14);
                osc.stop(now + (i + 1) * 0.19);
            });
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        setupEventHandlers();
        checkJoinAndStart();
    });

    function setupEventHandlers() {
        // Würfeln Button
        const rollBtn = document.getElementById('btn-roll-dice');
        if (rollBtn) {
            rollBtn.addEventListener('click', handleRollDice);
        }

        // Klappen umlegen bestätigen
        const flipBtn = document.getElementById('btn-confirm-flip');
        if (flipBtn) {
            flipBtn.addEventListener('click', handleConfirmFlip);
        }

        // Spielblatt-Modal toggeln
        const scoreBtn = document.getElementById('btn-toggle-scorecard');
        const scoreModal = document.getElementById('modal-scorecard');
        if (scoreBtn && scoreModal) {
            scoreBtn.addEventListener('click', () => {
                scoreModal.classList.remove('hidden');
                if (currentRoomData) renderScoreTable(currentRoomData, 'stb-score-table');
            });
        }
        document.querySelectorAll('.js-close-scorecard').forEach(btn => {
            btn.addEventListener('click', () => {
                if (scoreModal) scoreModal.classList.add('hidden');
            });
        });

        // Runden-Zusammenfassung schließen
        document.querySelectorAll('.js-close-round-summary').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = document.getElementById('modal-round-summary');
                if (modal) modal.classList.add('hidden');
                if (roundSummaryTimer) clearTimeout(roundSummaryTimer);
            });
        });

        // Vorzeitiger Abbruch Button
        const abortBtn = document.getElementById('btn-request-abort');
        if (abortBtn) {
            abortBtn.addEventListener('click', handleRequestAbort);
        }

        // Abbruch-Abstimmung Buttons
        const voteYesBtn = document.getElementById('btn-vote-abort-yes');
        const voteNoBtn = document.getElementById('btn-vote-abort-no');
        if (voteYesBtn) voteYesBtn.addEventListener('click', () => handleVoteAbort(true));
        if (voteNoBtn) voteNoBtn.addEventListener('click', () => handleVoteAbort(false));

        // Start Game
        const startBtn = document.getElementById('btn-start-game');
        if (startBtn) startBtn.addEventListener('click', handleStartGame);

        // Add Bot
        const addBotBtn = document.getElementById('btn-add-bot');
        if (addBotBtn) addBotBtn.addEventListener('click', handleAddBot);

        // Revanche
        const rematchBtn = document.getElementById('btn-rematch');
        if (rematchBtn) rematchBtn.addEventListener('click', handleRematch);

        // Name ändern
        document.querySelectorAll('.js-btn-edit-name').forEach(btn => {
            btn.addEventListener('click', async () => {
                const cur = localStorage.getItem('kai_gh_player_name') || 'Spieler';
                const next = await HubDialog.prompt('Spielername anpassen', 'Wie möchtest du am Klappbrett heißen?', cur);
                if (next && next.trim() !== '') {
                    localStorage.setItem('kai_gh_player_name', next.trim());
                    updateMyNameDisplays(next.trim());
                }
            });
        });

        // WhatsApp Share
        document.querySelectorAll('.js-share-whatsapp').forEach(btn => {
            btn.addEventListener('click', () => {
                const url = window.location.href;
                const text = `Komm an mein 4-Spieler Shut the Box Klappbrett auf Quarxl! Raum-Code: ${roomCode}\n${url}`;
                window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
            });
        });
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

        // State abrufen
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

                const res = await HubHttp.postJson('./api.php?action=join', {
                    room_code: roomCode,
                    player_name: name
                });

                if (res.ok && res.data && res.data.player_token) {
                    localStorage.setItem('kai_gh_player_token', res.data.player_token);
                    localStorage.setItem('kai_gh_player_name', name);
                    updateMyNameDisplays(name);
                    modal.classList.add('hidden');
                    fetchState(true);
                    startPolling();
                } else {
                    if (errBox) {
                        errBox.textContent = res.data?.message || 'Beitritt fehlgeschlagen.';
                        errBox.classList.remove('hidden');
                    }
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

    async function fetchState(isInitial = false) {
        const res = await HubHttp.getJson(`./api.php?action=state&room=${encodeURIComponent(roomCode)}`);
        if (res.status === 401) {
            openJoinModal();
            return false;
        }
        if (!res.ok || !res.data || !res.data.success) {
            if (isInitial) return false;
            return false;
        }

        const data = res.data.data;
        currentRoomData = data;
        renderGameState(data);
        return true;
    }

    function renderGameState(data) {
        const myPlayer = data.players.find(p => p.is_me);
        if (myPlayer) {
            updateMyNameDisplays(myPlayer.name);
        }

        // Ticker & Audio bei neuen Events
        if (data.last_event && data.last_event.time && data.last_event.time !== lastEventTime) {
            lastEventTime = data.last_event.time;
            const tickerEl = document.getElementById('event-ticker-text');
            if (tickerEl) {
                tickerEl.textContent = data.last_event.message || '';
            }

            if (data.last_event.type === 'shut_the_box') {
                AudioEngine.playShutTheBox();
            } else if (data.last_event.type === 'game_finished') {
                AudioEngine.playVictory();
            }
        }

        // Abbruch-Abstimmung prüfen
        renderAbortModal(data, myPlayer);

        // Runden-Zusammenfassung bei Rundenübergang anzeigen
        renderRoundSummaryModal(data);

        const paneLobby = document.getElementById('pane-lobby');
        const paneGame = document.getElementById('pane-game');
        const paneFinished = document.getElementById('pane-finished');
        const abortBtn = document.getElementById('btn-request-abort');

        if (data.status === 'lobby') {
            if (paneLobby) paneLobby.classList.remove('hidden');
            if (paneGame) paneGame.classList.add('hidden');
            if (paneFinished) paneFinished.classList.add('hidden');
            if (abortBtn) abortBtn.classList.add('hidden');
            renderLobby(data, myPlayer);
        } else if (data.status === 'active') {
            if (paneLobby) paneLobby.classList.add('hidden');
            if (paneGame) paneGame.classList.remove('hidden');
            if (paneFinished) paneFinished.classList.add('hidden');
            if (abortBtn) abortBtn.classList.remove('hidden');
            renderGame(data, myPlayer);
        } else if (data.status === 'finished') {
            if (paneLobby) paneLobby.classList.add('hidden');
            if (paneGame) paneGame.classList.add('hidden');
            if (paneFinished) paneFinished.classList.remove('hidden');
            if (abortBtn) abortBtn.classList.add('hidden');
            renderFinished(data, myPlayer);
        }
    }

    function renderLobby(data, myPlayer) {
        const listEl = document.getElementById('lobby-players-list');
        if (listEl) {
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
        }

        const isHost = myPlayer ? myPlayer.is_host : false;
        const addBotBtn = document.getElementById('btn-add-bot');
        const startBtn = document.getElementById('btn-start-game');
        const waitNotice = document.getElementById('lobby-waiting-notice');

        if (isHost) {
            if (addBotBtn) {
                addBotBtn.classList.remove('hidden');
                addBotBtn.disabled = (data.players.length >= 4);
            }
            if (startBtn) startBtn.classList.remove('hidden');
            if (waitNotice) waitNotice.classList.add('hidden');
        } else {
            if (addBotBtn) addBotBtn.classList.add('hidden');
            if (startBtn) startBtn.classList.add('hidden');
            if (waitNotice) waitNotice.classList.remove('hidden');
        }
    }

    function renderGame(data, myPlayer) {
        // Runden-Indikator
        const roundBadge = document.getElementById('round-indicator-badge');
        if (roundBadge) {
            roundBadge.textContent = `Runde ${data.round_number} / ${data.max_rounds}`;
        }

        // Mitspieler-Klappen rendern
        const oppCont = document.getElementById('opponents-flaps-container');
        if (oppCont) {
            const opponents = data.players.filter(p => !p.is_me);
            let oppHtml = '';
            opponents.forEach(op => {
                const turnClass = op.is_active ? 'active-turn' : '';
                let statusTag = '';
                if (op.is_locked_out) {
                    const penalty = calculateFlapsSum(op.flaps);
                    statusTag = `<span class="opponent-status-tag locked-out">${penalty === 0 ? '🏆 SHUT!' : `+${penalty} P.`}</span>`;
                } else if (op.is_active) {
                    statusTag = '<span class="opponent-status-tag active">Am Zug</span>';
                }

                let miniFlapsHtml = '';
                for (let i = 1; i <= 10; i++) {
                    const isOpen = !empty(op.flaps[i]);
                    miniFlapsHtml += `<div class="mini-flap ${isOpen ? '' : 'closed'}">${i}</div>`;
                }

                oppHtml += `
                    <div class="opponent-box-side ${turnClass}">
                        <div class="opponent-box-header">
                            <span class="opponent-box-name">${op.is_bot ? '🤖 ' : ''}${HubHtml.escape(op.name)}</span>
                            ${statusTag}
                        </div>
                        <div class="opponent-mini-flaps">${miniFlapsHtml}</div>
                    </div>
                `;
            });
            oppCont.innerHTML = oppHtml;
        }

        // Neuer Wurf (z. B. durch Mitspieler oder Bot) über Polling eingetroffen?
        const currentRollSeq = Number(data.roll_seq || 0);
        if (currentRollSeq > 0 && lastKnownRollSeq > 0 && currentRollSeq > lastKnownRollSeq && !isRollingAnimation) {
            lastKnownRollSeq = currentRollSeq;
            playDiceRollAnimation(data.dice || [1, 1], () => {
                renderGame(data, myPlayer);
            });
            return;
        }
        lastKnownRollSeq = Math.max(lastKnownRollSeq, currentRollSeq);

        const [d1, d2] = data.dice || [1, 1];
        if (!isRollingAnimation) {
            renderDie('die-1', d1);
            renderDie('die-2', d2);

            const sumDisplay = document.getElementById('dice-sum-display');
            if (sumDisplay) {
                sumDisplay.innerHTML = data.has_rolled 
                    ? `Augen: <strong>${d1}</strong> &amp; <strong>${d2}</strong>`
                    : 'Augen: <strong>-</strong>';
            }
        }

        // Zug-Status & Würfeln-Button
        const isMyTurn = myPlayer ? myPlayer.is_active : false;
        const isLockedOut = myPlayer ? myPlayer.is_locked_out : false;
        const rollBtn = document.getElementById('btn-roll-dice');
        const turnStatus = document.getElementById('turn-status-text');

        if (rollBtn && turnStatus) {
            if (isLockedOut) {
                rollBtn.disabled = true;
                const myPenalty = myPlayer ? calculateFlapsSum(myPlayer.flaps) : 0;
                turnStatus.innerHTML = `<span style="color: #f87171;">❌ Ausgeschieden für diese Runde (${myPenalty} P.). Warte auf andere...</span>`;
            } else if (isMyTurn) {
                if (data.has_rolled) {
                    rollBtn.disabled = true;
                    turnStatus.innerHTML = `<span style="color: #facc15;">👉 Wähle: Addition, Subtraktion oder einzeln!</span>`;
                } else {
                    rollBtn.disabled = isRollingAnimation;
                    turnStatus.innerHTML = '<span style="color: #22c55e;">👉 Du bist am Zug! Würfle jetzt.</span>';
                }
            } else {
                rollBtn.disabled = true;
                const curPlayer = data.players.find(p => p.is_active);
                const curName = curPlayer ? curPlayer.name : 'jemand anderes';
                turnStatus.innerHTML = `Warte auf: <strong>${HubHtml.escape(curName)}</strong>`;
            }
        }

        // Mögliche Kombinationen anzeigen (Schnellauswahl)
        const combosShelf = document.getElementById('valid-combos-shelf');
        const combosCont = document.getElementById('combo-pills-container');
        if (combosShelf && combosCont) {
            if (isMyTurn && data.has_rolled && Array.isArray(data.valid_combos) && data.valid_combos.length > 0) {
                combosShelf.classList.remove('hidden');
                let pillsHtml = '';
                data.valid_combos.forEach(comboObj => {
                    const label = comboObj.label || comboObj.flaps.join(' + ');
                    const flapsStr = comboObj.flaps.join(',');
                    pillsHtml += `<button type="button" class="combo-pill-btn js-btn-combo" data-combo="${flapsStr}">👉 ${HubHtml.escape(label)}</button>`;
                });
                combosCont.innerHTML = pillsHtml;

                combosCont.querySelectorAll('.js-btn-combo').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const combo = btn.dataset.combo.split(',').map(Number);
                        selectedFlaps = new Set(combo);
                        renderMyFlaps(myPlayer, data.has_rolled, data.valid_combos);
                        handleConfirmFlip();
                    });
                });
            } else {
                combosShelf.classList.add('hidden');
            }
        }

        // Eigene Klappen rendern
        if (myPlayer) {
            renderMyFlaps(myPlayer, data.has_rolled, data.valid_combos || []);
        }
    }

    function renderMyFlaps(myPlayer, hasRolled, validCombos = []) {
        const flapsRow = document.getElementById('my-flaps-row');
        const penaltyBadge = document.getElementById('my-open-penalty-badge');
        const confirmBtn = document.getElementById('btn-confirm-flip');
        if (!flapsRow || !myPlayer) return;

        const myFlaps = myPlayer.flaps || {};
        const openSum = calculateFlapsSum(myFlaps);
        if (penaltyBadge) {
            penaltyBadge.textContent = myPlayer.is_locked_out ? `Runden-Punkte: ${openSum}` : `Offen: ${openSum}`;
        }

        let flapsHtml = '';
        for (let i = 1; i <= 10; i++) {
            const isOpen = !empty(myFlaps[i]);
            const isSelected = selectedFlaps.has(i);
            let stateClass = 'flap-open';
            if (!isOpen) {
                stateClass = 'flap-closed';
            } else if (isSelected) {
                stateClass = 'flap-selected';
            }

            flapsHtml += `
                <div class="flap-box ${stateClass}" data-flap="${i}">
                    <div class="flap-hinge"></div>
                    <span>${i}</span>
                </div>
            `;
        }
        flapsRow.innerHTML = flapsHtml;

        // Klick auf Klappen zur manuellen Auswahl
        flapsRow.querySelectorAll('.flap-box.flap-open, .flap-box.flap-selected').forEach(el => {
            el.addEventListener('click', () => {
                if (!myPlayer.is_active || !hasRolled) return;
                const flapNum = parseInt(el.dataset.flap, 10);
                if (selectedFlaps.has(flapNum)) {
                    selectedFlaps.delete(flapNum);
                } else {
                    selectedFlaps.add(flapNum);
                }
                AudioEngine.playFlapClick();
                renderMyFlaps(myPlayer, hasRolled, validCombos);
            });
        });

        // "Umlegen"-Button prüfen (Auswahl muss exakt einer der gültigen Züge entsprechen)
        if (confirmBtn) {
            const selectedArr = Array.from(selectedFlaps).sort((a, b) => a - b);
            const matchesAnyCombo = (validCombos || []).some(comboObj => {
                const cf = (comboObj.flaps || []).slice().sort((a, b) => a - b);
                return cf.length === selectedArr.length && cf.every((val, idx) => val === selectedArr[idx]);
            });
            confirmBtn.disabled = !(myPlayer.is_active && hasRolled && matchesAnyCombo);
        }
    }

    function renderDie(dieId, val) {
        const el = document.getElementById(dieId);
        if (!el) return;
        el.dataset.val = val;

        // Positionierung der Augen im 3x3 Raster
        const dotPositions = {
            1: ['dot-center'],
            2: ['dot-top-left', 'dot-bottom-right'],
            3: ['dot-top-left', 'dot-center', 'dot-bottom-right'],
            4: ['dot-top-left', 'dot-top-right', 'dot-bottom-left', 'dot-bottom-right'],
            5: ['dot-top-left', 'dot-top-right', 'dot-center', 'dot-bottom-left', 'dot-bottom-right'],
            6: ['dot-top-left', 'dot-top-right', 'dot-mid-left', 'dot-mid-right', 'dot-bottom-left', 'dot-bottom-right']
        };

        const dots = dotPositions[val] || ['dot-center'];
        let dotsHtml = '';
        dots.forEach(pos => {
            dotsHtml += `<span class="die-dot ${pos}"></span>`;
        });
        el.innerHTML = dotsHtml;
    }

    function playDiceRollAnimation(targetDice, onComplete) {
        if (isRollingAnimation) return;
        isRollingAnimation = true;

        const die1 = document.getElementById('die-1');
        const die2 = document.getElementById('die-2');
        const sumDisplay = document.getElementById('dice-sum-display');

        if (die1) {
            die1.classList.remove('landing');
            die1.classList.add('rolling');
        }
        if (die2) {
            die2.classList.remove('landing');
            die2.classList.add('rolling');
        }

        if (sumDisplay) {
            sumDisplay.innerHTML = 'Augen: <strong>🎲 ...</strong>';
        }

        AudioEngine.playDiceRoll();

        if ('vibrate' in navigator) {
            try { navigator.vibrate([25, 35, 25]); } catch (_) {}
        }

        let ticks = 0;
        const maxTicks = 7;
        const interval = setInterval(() => {
            ticks++;
            const rnd1 = Math.floor(Math.random() * 6) + 1;
            const rnd2 = Math.floor(Math.random() * 6) + 1;
            renderDie('die-1', rnd1);
            renderDie('die-2', rnd2);

            if (ticks >= maxTicks) {
                clearInterval(interval);
                if (die1) die1.classList.remove('rolling');
                if (die2) die2.classList.remove('rolling');

                const [finalD1, finalD2] = targetDice || [1, 1];
                renderDie('die-1', finalD1);
                renderDie('die-2', finalD2);

                if (die1) die1.classList.add('landing');
                if (die2) die2.classList.add('landing');
                setTimeout(() => {
                    if (die1) die1.classList.remove('landing');
                    if (die2) die2.classList.remove('landing');
                }, 400);

                if (sumDisplay) {
                    sumDisplay.innerHTML = `Augen: <strong>${finalD1}</strong> &amp; <strong>${finalD2}</strong>`;
                }

                AudioEngine.playDiceLand();

                if ('vibrate' in navigator) {
                    try { navigator.vibrate([40]); } catch (_) {}
                }

                isRollingAnimation = false;
                if (typeof onComplete === 'function') {
                    onComplete();
                }
            }
        }, 75);
    }

    function renderRoundSummaryModal(data) {
        const modal = document.getElementById('modal-round-summary');
        const titleEl = document.getElementById('round-summary-title');
        const listEl = document.getElementById('round-summary-scores');
        const contBtn = document.getElementById('btn-next-round-continue');
        if (!modal || !titleEl || !listEl || !data.round_summary) return;

        const summary = data.round_summary;
        if (summary.round_number === lastShownSummaryRound) {
            return;
        }

        lastShownSummaryRound = summary.round_number;

        titleEl.textContent = `🔔 Runde ${summary.round_number} beendet!`;
        if (contBtn) {
            contBtn.textContent = `Weiter zu Runde ${summary.next_round} →`;
        }

        let scoresHtml = '';
        (summary.scores || []).forEach(sc => {
            const isShut = (sc.round_score === 0);
            const badgeClass = isShut ? 'shut' : 'penalty';
            const badgeText = isShut ? '0 🏆 SHUT!' : `+${sc.round_score} P.`;
            scoresHtml += `
                <div class="round-summary-row">
                    <div class="round-summary-player">
                        <span>${sc.is_bot ? '🤖 ' : '👤 '}${HubHtml.escape(sc.player_name)}</span>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <span class="round-summary-badge ${badgeClass}">${badgeText}</span>
                        <span style="color: #94a3b8; font-size: 0.8rem;">(Gesamt: ${sc.total_score} P.)</span>
                    </div>
                </div>
            `;
        });
        listEl.innerHTML = scoresHtml;
        modal.classList.remove('hidden');

        // Automatisches Ausblenden nach 6 Sekunden oder bei Klick auf "Weiter"
        if (roundSummaryTimer) clearTimeout(roundSummaryTimer);
        roundSummaryTimer = setTimeout(() => {
            modal.classList.add('hidden');
        }, 6000);
    }

    async function handleRollDice() {
        if (isSubmitting || isRollingAnimation) return;
        isSubmitting = true;

        const rollBtn = document.getElementById('btn-roll-dice');
        if (rollBtn) rollBtn.disabled = true;

        const res = await HubHttp.postJson(`./api.php?action=roll&room=${encodeURIComponent(roomCode)}`);
        isSubmitting = false;

        if (res.ok && res.data && res.data.success) {
            selectedFlaps.clear();
            const targetDice = res.data.dice || [1, 1];
            playDiceRollAnimation(targetDice, () => {
                fetchState(true);
            });
        } else {
            HubDialog.alert('Würfeln', res.data?.message || 'Fehler beim Würfeln.', '🎲');
            if (rollBtn) rollBtn.disabled = false;
        }
    }

    async function handleConfirmFlip() {
        if (isSubmitting || selectedFlaps.size === 0) return;
        isSubmitting = true;

        AudioEngine.playFlapClick();

        const flaps = Array.from(selectedFlaps);
        const res = await HubHttp.postJson(`./api.php?action=flip&room=${encodeURIComponent(roomCode)}`, {
            flaps: flaps
        });
        isSubmitting = false;

        if (res.ok && res.data && res.data.success) {
            selectedFlaps.clear();
            fetchState(true);
        } else {
            HubDialog.alert('Klappen umlegen', res.data?.message || 'Ungültige Klappen.', '⚠️');
        }
    }

    async function handleRequestAbort() {
        const confirmed = await HubDialog.confirm(
            'Spiel vorzeitig beenden?',
            'Möchtest du einen vorzeitigen Spielabbruch beantragen? Alle Mitspieler müssen zustimmen. Das Spiel wird nach den bisherigen Runden gewertet.',
            { icon: '⏹️', confirmText: 'Abbruch beantragen', confirmClass: 'btn-danger' }
        );
        if (!confirmed) {
            return;
        }

        const res = await HubHttp.postJson(`./api.php?action=request_abort&room=${encodeURIComponent(roomCode)}`);
        if (res.ok && res.data && res.data.success) {
            fetchState(true);
        } else {
            HubDialog.alert('Spielabbruch', res.data?.message || 'Konnte Abbruch nicht beantragen.', '⚠️');
        }
    }

    async function handleVoteAbort(agrees) {
        const modal = document.getElementById('modal-abort-vote');
        if (modal) modal.classList.add('hidden');

        const res = await HubHttp.postJson(`./api.php?action=vote_abort&room=${encodeURIComponent(roomCode)}`, {
            vote: agrees
        });
        if (res.ok && res.data && res.data.success) {
            fetchState(true);
        }
    }

    function renderAbortModal(data, myPlayer) {
        const modal = document.getElementById('modal-abort-vote');
        const descEl = document.getElementById('abort-vote-desc');
        if (!modal || !descEl) return;

        if (data.abort_vote && myPlayer) {
            const hasVoted = Boolean(data.abort_vote.votes && data.abort_vote.votes[myPlayer.id]);
            if (!hasVoted) {
                descEl.textContent = `${data.abort_vote.initiator_name} schlägt vor, das Spiel vorzeitig abzubrechen und nach Runde ${data.round_number - 1} auszuwerten.`;
                modal.classList.remove('hidden');
            } else {
                modal.classList.add('hidden');
            }
        } else {
            modal.classList.add('hidden');
        }
    }

    function renderScoreTable(data, tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;

        let headHtml = '<thead><tr><th>Runde</th>';
        data.players.forEach(p => {
            headHtml += `<th>${HubHtml.escape(p.name)}</th>`;
        });
        headHtml += '</tr></thead>';

        let bodyHtml = '<tbody>';
        for (let r = 0; r < data.max_rounds; r++) {
            const roundNum = r + 1;
            const isCur = (roundNum === data.round_number && data.status === 'active');
            bodyHtml += `<tr class="${isCur ? 'active-round-row' : ''}"><td>Runde ${roundNum}</td>`;

            data.players.forEach(p => {
                const score = p.scores ? p.scores[r] : null;
                let valStr = '-';
                if (score !== null && score !== '') {
                    valStr = (score === 0) ? '0 🏆' : String(score);
                }
                bodyHtml += `<td>${valStr}</td>`;
            });
            bodyHtml += '</tr>';
        }

        // Summen-Zeile
        bodyHtml += '<tr class="total-score-row"><td>GESAMT</td>';
        data.players.forEach(p => {
            bodyHtml += `<td>${p.total_score} P.</td>`;
        });
        bodyHtml += '</tr></tbody>';

        table.innerHTML = headHtml + bodyHtml;
    }

    function renderFinished(data, myPlayer) {
        const nameEl = document.getElementById('winner-name-display');
        const descEl = document.getElementById('winner-desc');

        if (nameEl) {
            nameEl.textContent = data.winner_name ? `${data.winner_name} gewinnt!` : 'Spiel beendet!';
        }
        if (descEl) {
            descEl.textContent = `Herzlichen Glückwunsch! Mit nur ${data.winner_score} Strafpunkten über alle Runden.`;
        }

        renderScoreTable(data, 'finished-score-table');

        const isHost = myPlayer ? myPlayer.is_host : false;
        const rematchBtn = document.getElementById('btn-rematch');
        if (rematchBtn) {
            if (isHost) rematchBtn.classList.remove('hidden');
            else rematchBtn.classList.add('hidden');
        }
    }

    async function handleStartGame() {
        const res = await HubHttp.postJson(`./api.php?action=start&room=${encodeURIComponent(roomCode)}`);
        if (res.ok && res.data && res.data.success) {
            fetchState(true);
        } else {
            HubDialog.alert('Spiel starten', res.data?.message || 'Spiel konnte nicht gestartet werden.', '⚠️');
        }
    }

    async function handleAddBot() {
        const res = await HubHttp.postJson(`./api.php?action=add_bot&room=${encodeURIComponent(roomCode)}`);
        if (res.ok && res.data && res.data.success) {
            fetchState(true);
        } else {
            HubDialog.alert('Bot hinzufügen', res.data?.message || 'Konnte Bot nicht hinzufügen.', '🤖');
        }
    }

    async function handleRematch() {
        const res = await HubHttp.postJson(`./api.php?action=rematch&room=${encodeURIComponent(roomCode)}`);
        if (res.ok && res.data && res.data.success) {
            fetchState(true);
        } else {
            HubDialog.alert('Revanche', res.data?.message || 'Konnte Revanche nicht starten.', '🔄');
        }
    }

    function calculateFlapsSum(flaps) {
        if (!flaps) return 0;
        let sum = 0;
        for (let i = 1; i <= 10; i++) {
            if (!empty(flaps[i])) sum += i;
        }
        return sum;
    }

    function empty(val) {
        return !val || val === 0 || val === '0';
    }

    function updateMyNameDisplays(name) {
        document.querySelectorAll('.js-my-name-display').forEach(el => {
            el.textContent = name;
        });
        const shelfName = document.getElementById('my-shelf-name');
        if (shelfName) {
            shelfName.textContent = name;
        }
    }

})();

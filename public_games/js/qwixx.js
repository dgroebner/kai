/**
 * qwixx.js - Spielzettel, Regelprüfung & Live-Multiplayer für Qwixx
 */
(function () {
    'use strict';

    const SCORE_TABLE = [0, 1, 3, 6, 10, 15, 21, 28, 36, 45, 55, 66, 78];

    const PIPS_MAP = {
        1: ['pip-center'],
        2: ['pip-top-left', 'pip-bottom-right'],
        3: ['pip-top-left', 'pip-center', 'pip-bottom-right'],
        4: ['pip-top-left', 'pip-top-right', 'pip-bottom-left', 'pip-bottom-right'],
        5: ['pip-top-left', 'pip-top-right', 'pip-center', 'pip-bottom-left', 'pip-bottom-right'],
        6: ['pip-top-left', 'pip-top-right', 'pip-mid-left', 'pip-mid-right', 'pip-bottom-left', 'pip-bottom-right']
    };

    const QwixxApp = {
        roomCode: '',
        isHost: false,
        myPlayerId: null,
        gameState: null,
        mySheet: null,
        players: [],
        pollTimer: null,
        isPolling: false,
        isRollingAnimation: false,
        lastKnownRollTime: null,

        init() {
            this.roomCode = document.body.dataset.room || '';
            if (!this.roomCode) {
                window.location.href = '../index.php';
                return;
            }

            this.bindEvents();
            this.checkSessionAndJoin();
        },

        bindEvents() {
            // WhatsApp Share
            document.querySelectorAll('.js-share-whatsapp').forEach(btn => {
                btn.addEventListener('click', () => this.shareWhatsApp());
            });

            // Beitritt bestätigen
            const btnJoin = document.querySelector('.js-btn-confirm-join');
            if (btnJoin) {
                btnJoin.addEventListener('click', () => this.performJoin());
            }

            // Spiel starten (Host)
            const btnStart = document.querySelector('.js-btn-start-game');
            if (btnStart) {
                btnStart.addEventListener('click', () => this.startGame());
            }

            // Würfeln
            const btnRoll = document.getElementById('btn-roll-dice');
            if (btnRoll) {
                btnRoll.addEventListener('click', () => this.rollDice());
            }

            // Nächster Zug
            const btnNext = document.getElementById('btn-next-turn');
            if (btnNext) {
                btnNext.addEventListener('click', () => this.nextTurn());
            }

            // Revanche (Host)
            const btnRematch = document.getElementById('btn-rematch');
            if (btnRematch) {
                btnRematch.addEventListener('click', () => this.rematch());
            }

            // Spielzettel: Klick auf Zahl
            const sheet = document.getElementById('qwixx-sheet');
            if (sheet) {
                sheet.addEventListener('click', (e) => {
                    const cell = e.target.closest('.js-cell');
                    if (cell && !cell.classList.contains('is-disabled') && !cell.classList.contains('is-crossed')) {
                        const color = cell.dataset.color;
                        const num = Number(cell.dataset.num);
                        if (color && num) {
                            this.crossNumber(color, num);
                        }
                    }
                });
            }

            // Fehlwurf anklicken
            document.querySelectorAll('.js-missed-box').forEach(box => {
                box.addEventListener('click', () => {
                    const idx = Number(box.dataset.index);
                    const currentMissed = Number(this.mySheet?.missed || 0);
                    if (idx === currentMissed + 1) {
                        this.recordMissed();
                    }
                });
            });
        },

        async checkSessionAndJoin() {
            const savedName = localStorage.getItem('kai_gh_player_name');
            const token = localStorage.getItem('kai_gh_player_token');

            if (!savedName || !token) {
                // Name erfragen
                const modal = document.getElementById('join-modal');
                if (modal) {
                    modal.classList.remove('hidden');
                    const inp = document.getElementById('join-player-name');
                    if (inp && savedName) inp.value = savedName;
                }
            } else {
                // Direkt beitreten
                await this.performJoin(savedName);
            }
        },

        async performJoin(nameOverride = null) {
            const name = nameOverride || document.getElementById('join-player-name')?.value.trim() || 'Gast';
            localStorage.setItem('kai_gh_player_name', name);

            const res = await HubHttp.postJson('./api.php?action=join', {
                room_code: this.roomCode,
                player_name: name
            });

            if (res.ok && res.data && res.data.success) {
                if (res.data.player_token) {
                    localStorage.setItem('kai_gh_player_token', res.data.player_token);
                }
                this.isHost = Boolean(res.data.is_host);
                this.myPlayerId = res.data.player_id;

                const modal = document.getElementById('join-modal');
                if (modal) modal.classList.add('hidden');

                this.startPolling();
            } else {
                const errBox = document.getElementById('join-error');
                if (errBox) {
                    errBox.textContent = res.data?.message || 'Beitritt fehlgeschlagen.';
                    errBox.classList.remove('hidden');
                }
            }
        },

        shareWhatsApp() {
            const url = window.location.href;
            const text = `Komm zu meiner Qwixx-Runde! 🎲\nTippe einfach auf den Link zum Mitspielen:\n${url}`;
            if (navigator.share) {
                navigator.share({ title: 'Qwixx Spielrunde', text: text, url: url }).catch(() => {});
            } else {
                window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
            }
        },

        startPolling() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.fetchState();
            this.pollTimer = setInterval(() => this.fetchState(), 1600);
        },

        async fetchState() {
            if (this.isPolling) return;
            this.isPolling = true;

            const res = await HubHttp.getJson(`./api.php?action=state&room=${encodeURIComponent(this.roomCode)}`);
            this.isPolling = false;

            if (res.ok && res.data && res.data.success) {
                this.handleStateUpdate(res.data);
            }
        },

        handleStateUpdate(data) {
            this.gameState = data.room.state;
            this.players = data.players || [];
            this.mySheet = data.me.sheet;
            this.isHost = Boolean(data.me.is_host);
            this.myPlayerId = data.me.id;

            const status = data.room.status;
            const mode = data.room.mode;

            const lobbyPane = document.getElementById('pane-lobby');
            const gamePane = document.getElementById('pane-game');
            const diceStage = document.getElementById('dice-stage');
            const gameOverModal = document.getElementById('game-over-modal');

            if (status === 'lobby') {
                lobbyPane.classList.remove('hidden');
                gamePane.classList.add('hidden');
                gameOverModal.classList.add('hidden');
                this.renderLobby();
                return;
            }

            lobbyPane.classList.add('hidden');
            gamePane.classList.remove('hidden');

            if (mode === 'digital_dice') {
                diceStage.classList.remove('hidden');
                this.renderDiceStage();
            } else {
                diceStage.classList.add('hidden');
            }

            this.renderPlayersBar();
            this.renderSheet();
            this.renderTurnInstructions();

            if (status === 'finished') {
                this.renderGameOver();
            } else {
                gameOverModal.classList.add('hidden');
            }
        },

        renderLobby() {
            const list = document.getElementById('lobby-players-list');
            if (list) {
                list.innerHTML = this.players.map(p => `
                    <div style="background: rgba(255,255,255,0.06); padding: 0.4rem 0.8rem; border-radius: 999px; font-weight: 600; font-size: 0.88rem;">
                        ${HubHtml.escape(p.name)} ${p.is_host ? '👑' : ''}
                    </div>
                `).join('');
            }

            const hostControls = document.getElementById('lobby-host-controls');
            const guestHint = document.getElementById('lobby-guest-hint');
            if (this.isHost) {
                hostControls?.classList.remove('hidden');
                guestHint?.classList.add('hidden');
            } else {
                hostControls?.classList.add('hidden');
                guestHint?.classList.remove('hidden');
            }
        },

        renderPlayersBar() {
            const bar = document.getElementById('players-bar');
            if (!bar) return;

            const activeIdx = Number(this.gameState?.active_player_index || 0);

            bar.innerHTML = this.players.map((p, idx) => {
                const isActive = (idx === activeIdx);
                const isMe = p.is_me;
                const r = p.rows_count || { red: 0, green: 0, yellow: 0, blue: 0 };
                return `
                    <div class="qwixx-player-card ${isActive ? 'is-active-turn' : ''} ${isMe ? 'is-me' : ''}">
                        <span class="qwixx-player-name">${HubHtml.escape(p.name)} ${isActive ? '🎲' : ''}</span>
                        <div class="qwixx-player-kpis">
                            <span style="color: #f87171;">🔴${r.red}</span>
                            <span style="color: #4ade80;">🟢${r.green}</span>
                            <span style="color: #fde047;">🟡${r.yellow}</span>
                            <span style="color: #60a5fa;">🔵${r.blue}</span>
                            <span style="color: #ef4444;">⚡${p.missed}</span>
                        </div>
                    </div>
                `;
            }).join('');
        },

        renderDiceStage() {
            if (this.isRollingAnimation) return;

            const dice = this.gameState?.dice || {};
            const closedRows = this.gameState?.closed_rows || [];
            const diceRow = document.getElementById('dice-row');
            if (!diceRow) return;

            // Prüfen, ob ein neuer Wurf von einem Mitspieler über das Polling eingetroffen ist
            const rollTime = this.gameState?.last_roll_time;
            if (rollTime && this.lastKnownRollTime && rollTime > this.lastKnownRollTime) {
                this.lastKnownRollTime = rollTime;
                this.playDiceRollAnimation(dice, closedRows);
                return;
            }
            this.lastKnownRollTime = rollTime || this.lastKnownRollTime;

            const diceList = [
                { id: 'w1', color: 'white', val: dice.white1 || 1 },
                { id: 'w2', color: 'white', val: dice.white2 || 1 },
                { id: 'r', color: 'red', val: dice.red || 1, closed: closedRows.includes('red') },
                { id: 'g', color: 'green', val: dice.green || 1, closed: closedRows.includes('green') },
                { id: 'y', color: 'yellow', val: dice.yellow || 1, closed: closedRows.includes('yellow') },
                { id: 'b', color: 'blue', val: dice.blue || 1, closed: closedRows.includes('blue') },
            ];

            diceRow.innerHTML = '';
            diceList.forEach(d => {
                const dieEl = document.createElement('div');
                dieEl.className = `qwixx-die die-${d.color} ${d.closed ? 'is-closed' : ''}`;
                if (!d.closed) {
                    const pips = PIPS_MAP[d.val] || [];
                    pips.forEach(pos => {
                        const pip = document.createElement('span');
                        pip.className = `die-pip ${pos}`;
                        dieEl.appendChild(pip);
                    });
                }
                diceRow.appendChild(dieEl);
            });

            const whiteSumVal = document.getElementById('white-sum-val');
            if (whiteSumVal) {
                whiteSumVal.textContent = String((dice.white1 || 1) + (dice.white2 || 1));
            }

            // Button-Steuerung für den aktiven Spieler
            const activeIdx = Number(this.gameState?.active_player_index || 0);
            const activePlayer = this.players[activeIdx];
            const isMyTurn = activePlayer && activePlayer.is_me;
            const phase = this.gameState?.turn_phase || 'roll';

            const btnRoll = document.getElementById('btn-roll-dice');
            const btnNext = document.getElementById('btn-next-turn');

            if (isMyTurn && phase === 'roll') {
                btnRoll?.classList.remove('hidden');
                btnNext?.classList.add('hidden');
            } else if (isMyTurn && phase === 'action') {
                btnRoll?.classList.add('hidden');
                btnNext?.classList.remove('hidden');
            } else {
                btnRoll?.classList.add('hidden');
                btnNext?.classList.add('hidden');
            }
        },

        playDiceRollAnimation(targetDice, closedRows) {
            const diceRow = document.getElementById('dice-row');
            if (!diceRow) return;

            this.isRollingAnimation = true;
            diceRow.classList.add('is-rolling');

            // Haptik-Impuls
            if ('vibrate' in navigator) {
                try { navigator.vibrate([30, 40, 30]); } catch (_) {}
            }

            let ticks = 0;
            const maxTicks = 6;
            const interval = setInterval(() => {
                ticks++;
                diceRow.innerHTML = '';
                const colors = [
                    { c: 'white', closed: false },
                    { c: 'white', closed: false },
                    { c: 'red', closed: closedRows.includes('red') },
                    { c: 'green', closed: closedRows.includes('green') },
                    { c: 'yellow', closed: closedRows.includes('yellow') },
                    { c: 'blue', closed: closedRows.includes('blue') },
                ];

                colors.forEach(col => {
                    const rnd = Math.floor(Math.random() * 6) + 1;
                    const dieEl = document.createElement('div');
                    dieEl.className = `qwixx-die die-${col.c} ${col.closed ? 'is-closed' : ''}`;
                    if (!col.closed) {
                        const pips = PIPS_MAP[rnd] || [];
                        pips.forEach(pos => {
                            const pip = document.createElement('span');
                            pip.className = `die-pip ${pos}`;
                            dieEl.appendChild(pip);
                        });
                    }
                    diceRow.appendChild(dieEl);
                });

                if (ticks >= maxTicks) {
                    clearInterval(interval);
                    diceRow.classList.remove('is-rolling');
                    this.isRollingAnimation = false;
                    this.renderDiceStage();

                    if ('vibrate' in navigator) {
                        try { navigator.vibrate([50]); } catch (_) {}
                    }
                }
            }, 80);
        },

        renderTurnInstructions() {
            const bar = document.getElementById('turn-instruction-bar');
            if (!bar) return;

            const activeIdx = Number(this.gameState?.active_player_index || 0);
            const activePlayer = this.players[activeIdx];
            const isMyTurn = activePlayer && activePlayer.is_me;
            const phase = this.gameState?.turn_phase || 'roll';

            if (phase === 'roll') {
                bar.textContent = isMyTurn ? 'Du bist am Zug! Bitte würfeln.' : `${activePlayer?.name || 'Der aktive Spieler'} würfelt gleich...`;
            } else {
                bar.textContent = isMyTurn
                    ? '1. Weiße Summe wählen / auslassen & 2. Farbwürfel kombinieren. Dann Zug abschließen.'
                    : `Alle Spieler dürfen die weiße Summe (${(this.gameState?.dice?.white1 || 1) + (this.gameState?.dice?.white2 || 1)}) ankreuzen.`;
            }
        },

        renderSheet() {
            if (!this.mySheet) return;

            const rows = this.mySheet.rows || {};
            const closedGlobal = this.gameState?.closed_rows || [];

            ['red', 'green', 'yellow', 'blue'].forEach(color => {
                const rowState = rows[color] || { crossed: [], locked: false };
                const crossed = rowState.crossed || [];
                const isAscending = (color === 'red' || color === 'green');
                const isLocked = Boolean(rowState.locked);
                const isClosedGlobal = closedGlobal.includes(color);

                // Schloss-Icon
                const lockEl = document.getElementById(`lock-${color}`);
                if (lockEl) {
                    lockEl.classList.toggle('is-crossed', isLocked);
                    lockEl.textContent = isLocked ? '✕' : '🔒';
                }

                // Höchstes / niedrigstes gesetztes Kreuz
                let rightmost = null;
                if (crossed.length > 0) {
                    rightmost = isAscending ? Math.max(...crossed) : Math.min(...crossed);
                }

                document.querySelectorAll(`.js-cell[data-color="${color}"]`).forEach(cell => {
                    const num = Number(cell.dataset.num);
                    const isCellCrossed = crossed.includes(num);

                    cell.classList.toggle('is-crossed', isCellCrossed);

                    // Regelprüfung: darf angekreuzt werden?
                    let disabled = false;
                    if (isLocked || isClosedGlobal) {
                        disabled = true;
                    } else if (rightmost !== null) {
                        if (isAscending && num <= rightmost) disabled = true;
                        if (!isAscending && num >= rightmost) disabled = true;
                    }

                    // Letzte Zahl (12 bzw. 2) braucht 5 Kreuze
                    const isLastNum = (isAscending && num === 12) || (!isAscending && num === 2);
                    if (isLastNum && crossed.length < 5) {
                        disabled = true;
                    }

                    cell.classList.toggle('is-disabled', disabled && !isCellCrossed);
                });
            });

            // Fehlwürfe
            const missedCount = Number(this.mySheet.missed || 0);
            document.querySelectorAll('.js-missed-box').forEach(box => {
                const idx = Number(box.dataset.index);
                box.classList.toggle('is-checked', idx <= missedCount);
            });

            // Punkte-Vorschau aktualisieren
            this.updateScores();
        },

        updateScores() {
            if (!this.mySheet) return;

            let total = 0;
            ['red', 'green', 'yellow', 'blue'].forEach(color => {
                const crossed = this.mySheet.rows?.[color]?.crossed || [];
                let count = crossed.length;
                if (this.mySheet.rows?.[color]?.locked) count++;
                const countClamped = Math.min(12, Math.max(0, count));
                const score = SCORE_TABLE[countClamped] || 0;
                total += score;

                const scoreEl = document.getElementById(`score-${color}`);
                if (scoreEl) scoreEl.textContent = String(score);
            });

            const missedPenalty = Math.min(4, Math.max(0, Number(this.mySheet.missed || 0))) * 5;
            total -= missedPenalty;

            const scoreMissed = document.getElementById(`score-missed`);
            if (scoreMissed) scoreMissed.textContent = `-${missedPenalty}`;

            const scoreTotal = document.getElementById(`score-total`);
            if (scoreTotal) scoreTotal.textContent = String(total);
        },

        async crossNumber(color, number) {
            const res = await HubHttp.postJson('./api.php?action=cross', {
                room_code: this.roomCode,
                color: color,
                number: number
            });

            if (res.ok && res.data && res.data.success) {
                this.mySheet = res.data.sheet;
                this.renderSheet();
                this.fetchState();
            } else {
                alert(res.data?.message || 'Zahl darf nicht angekreuzt werden.');
            }
        },

        async recordMissed() {
            if (!confirm('Fehlwurf eintragen (-5 Punkte)?')) return;

            const res = await HubHttp.postJson('./api.php?action=missed', {
                room_code: this.roomCode
            });

            if (res.ok && res.data && res.data.success) {
                this.mySheet = res.data.sheet;
                this.renderSheet();
                this.fetchState();
            }
        },

        async startGame() {
            const res = await HubHttp.postJson('./api.php?action=start', {
                room_code: this.roomCode
            });
            if (res.ok) this.fetchState();
        },

        async rollDice() {
            const btnRoll = document.getElementById('btn-roll-dice');
            if (btnRoll) btnRoll.disabled = true;

            const res = await HubHttp.postJson('./api.php?action=roll', {
                room_code: this.roomCode
            });
            if (btnRoll) btnRoll.disabled = false;

            if (res.ok && res.data && res.data.success) {
                const closedRows = this.gameState?.closed_rows || [];
                this.playDiceRollAnimation(res.data.dice, closedRows);
                this.fetchState();
            }
        },

        async nextTurn() {
            const res = await HubHttp.postJson('./api.php?action=next_turn', {
                room_code: this.roomCode
            });
            if (res.ok) this.fetchState();
        },

        async rematch() {
            const res = await HubHttp.postJson('./api.php?action=rematch', {
                room_code: this.roomCode
            });
            if (res.ok) this.fetchState();
        },

        renderGameOver() {
            const modal = document.getElementById('game-over-modal');
            const list = document.getElementById('game-over-leaderboard');
            const reason = document.getElementById('game-over-reason');
            const btnRematch = document.getElementById('btn-rematch');
            if (!modal || !list) return;

            modal.classList.remove('hidden');

            const endReason = this.gameState?.game_end_reason;
            if (reason) {
                reason.textContent = (endReason === 'two_rows_closed')
                    ? 'Zwei Farbreihen wurden abgeschlossen!'
                    : 'Ein Spieler hat 4 Fehlwürfe erreicht!';
            }

            // Rangliste nach Gesamtpunkten sortieren
            const sorted = [...this.players].sort((a, b) => (b.total_score || 0) - (a.total_score || 0));

            list.innerHTML = sorted.map((p, idx) => `
                <div style="display: flex; justify-content: space-between; padding: 0.6rem 0.8rem; background: rgba(255,255,255,0.05); border-radius: 8px; margin-bottom: 0.4rem; font-weight: 700;">
                    <span>${idx === 0 ? '👑' : `#${idx + 1}`} ${HubHtml.escape(p.name)}</span>
                    <span style="color: var(--accent); font-size: 1.15rem;">${p.total_score} Pkt.</span>
                </div>
            `).join('');

            if (this.isHost && btnRematch) {
                btnRematch.classList.remove('hidden');
            } else if (btnRematch) {
                btnRematch.classList.add('hidden');
            }
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        QwixxApp.init();
    });
})();

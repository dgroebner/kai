/**
 * kniffel.js - Kniffel / Yahtzee Multiplayer mit Live-Ausfüllhilfe für Quarxl
 */
(function () {
    'use strict';

    const PIPS_MAP = {
        1: ['pip-center'],
        2: ['pip-top-left', 'pip-bottom-right'],
        3: ['pip-top-left', 'pip-center', 'pip-bottom-right'],
        4: ['pip-top-left', 'pip-top-right', 'pip-bottom-left', 'pip-bottom-right'],
        5: ['pip-top-left', 'pip-top-right', 'pip-center', 'pip-bottom-left', 'pip-bottom-right'],
        6: ['pip-top-left', 'pip-top-right', 'pip-mid-left', 'pip-mid-right', 'pip-bottom-left', 'pip-bottom-right']
    };

    const KniffelApp = {
        roomCode: '',
        gameMode: 'digital_dice',
        isHost: false,
        myPlayerId: null,
        gameState: null,
        mySheet: null,
        players: [],
        previews: {},
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
                btnJoin.addEventListener('click', () => {
                    const input = document.getElementById('join-player-name');
                    const name = input ? input.value.trim() : '';
                    if (!name) {
                        const err = document.getElementById('join-error');
                        if (err) {
                            err.textContent = 'Bitte gib deinen Namen ein.';
                            err.classList.remove('hidden');
                        }
                        return;
                    }
                    this.performJoin(name);
                });
            }

            // Enter-Taste im Namensfeld
            const joinInput = document.getElementById('join-player-name');
            if (joinInput) {
                joinInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        btnJoin?.click();
                    }
                });
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

            // Revanche
            const btnRematch = document.getElementById('btn-rematch');
            if (btnRematch) {
                btnRematch.addEventListener('click', () => this.rematch());
            }

            // Name ändern (Klick auf Button im Header oder eigene Spielerkarte/Lobby-Pille)
            document.addEventListener('click', (e) => {
                const btnEdit = e.target.closest('.js-btn-edit-name') || e.target.closest('.js-edit-my-name');
                if (btnEdit) {
                    e.preventDefault();
                    this.editPlayerName();
                }
            });

            // Klick auf eine Kategorie im Spielblatt (Event Delegation)
            document.querySelectorAll('.js-category-row').forEach(row => {
                row.addEventListener('click', () => {
                    const cat = row.dataset.cat;
                    if (cat) this.chooseCategory(cat);
                });
            });
        },

        async checkSessionAndJoin() {
            const savedName = localStorage.getItem('kai_gh_player_name');
            const token = localStorage.getItem('kai_gh_player_token');

            if (!savedName || !token) {
                const modal = document.getElementById('join-modal');
                if (modal) modal.classList.remove('hidden');
            } else {
                await this.performJoin(savedName);
            }
        },

        async performJoin(playerName) {
            const res = await HubHttp.postJson('./api.php?action=join', {
                room_code: this.roomCode,
                player_name: playerName
            });

            if (res.ok && res.data && res.data.success) {
                localStorage.setItem('kai_gh_player_name', playerName);
                localStorage.setItem('kai_gh_player_token', res.data.player_token);

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
            const text = `Komm zu meiner Kniffel-Runde! 🎲\nTippe einfach auf den Link zum Mitspielen:\n${url}`;
            if (navigator.share) {
                navigator.share({ title: 'Kniffel Spielrunde', text: text, url: url }).catch(() => {});
            } else {
                window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
            }
        },

        startPolling() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.fetchState();
            this.pollTimer = setInterval(() => this.fetchState(), 1600);
        },

        getActivePlayerInfo() {
            const activePlayerId = Number(this.gameState?.active_player_id || 0);
            const activeIdx = Number(this.gameState?.active_player_index || 0);
            const activePlayer = (activePlayerId > 0)
                ? (this.players.find(p => Number(p.id) === activePlayerId) || this.players[activeIdx] || this.players[0])
                : (this.players[activeIdx] || this.players[0]);
            const isMyTurn = Boolean(activePlayer && activePlayer.is_me);
            return { activePlayerId, activeIdx, activePlayer, isMyTurn };
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
            this.gameMode = data.room.mode || 'digital_dice';
            this.players = data.players || [];
            this.mySheet = data.me.sheet;
            this.myName = data.me.name || '';
            this.myScore = data.me.score_summary || {};
            this.previews = data.previews || {};
            this.isHost = Boolean(data.me.is_host);
            this.myPlayerId = data.me.id;

            // Eigenen Namen in der Top-Bar aktualisieren
            document.querySelectorAll('.js-my-name-display').forEach(el => {
                el.textContent = this.myName || 'Ich';
            });

            const status = data.room.status;

            const lobbyPane = document.getElementById('pane-lobby');
            const gamePane = document.getElementById('pane-game');
            const gameOverModal = document.getElementById('game-over-modal');

            if (status === 'lobby') {
                lobbyPane?.classList.remove('hidden');
                gamePane?.classList.add('hidden');
                gameOverModal?.classList.add('hidden');
                this.renderLobby();
                return;
            }

            lobbyPane?.classList.add('hidden');
            gamePane?.classList.remove('hidden');

            this.renderDiceStage();
            this.renderPlayersBar();
            this.renderSheet();
            this.renderTurnInstructions();

            if (status === 'finished') {
                this.renderGameOver();
            } else {
                gameOverModal?.classList.add('hidden');
            }
        },

        renderLobby() {
            const list = document.getElementById('lobby-players-list');
            if (list) {
                list.innerHTML = this.players.map(p => {
                    const isMe = p.is_me;
                    return `
                        <div class="${isMe ? 'js-edit-my-name' : ''}" style="background: rgba(255,255,255,0.06); padding: 0.4rem 0.8rem; border-radius: 999px; font-weight: 600; font-size: 0.88rem; ${isMe ? 'cursor: pointer; border: 1px dashed var(--color-blue);' : ''}" title="${isMe ? 'Klicken zum Ändern deines Namens' : ''}">
                            ${HubHtml.escape(p.name)} ${p.is_host ? '👑' : ''} ${isMe ? '<span style="opacity: 0.75; font-size: 0.78rem;">(Du ✏️)</span>' : ''}
                        </div>
                    `;
                }).join('');
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

            const { activePlayerId, activeIdx } = this.getActivePlayerInfo();

            bar.innerHTML = this.players.map((p, idx) => {
                const isActive = (activePlayerId > 0) ? (Number(p.id) === activePlayerId) : (idx === activeIdx);
                const isMe = p.is_me;
                const filled = p.filled_count || 0;
                const bonusStatus = p.bonus_reached ? '🏆 +35' : `${p.upper_sum || 0}/63`;

                return `
                    <div class="qwixx-player-card ${isActive ? 'is-active-turn' : ''} ${isMe ? 'is-me js-edit-my-name' : ''}" ${isMe ? 'style="cursor: pointer;" title="Klicken zum Ändern deines Namens"' : ''}>
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.35rem; margin-bottom: 0.2rem;">
                            <span class="qwixx-player-name">${HubHtml.escape(p.name)} ${isMe ? '<span style="font-size: 0.75rem; opacity: 0.7;">✏️</span>' : ''}</span>
                            ${isActive ? '<span class="qwixx-badge-active">🎲 dran</span>' : ''}
                        </div>
                        <div class="qwixx-player-kpis" style="justify-content: space-between;">
                            <span style="color: #60a5fa; font-weight: 700;">${p.total_score || 0} Pkt.</span>
                            <span style="color: var(--text-muted);">${filled}/13</span>
                            <span style="color: ${p.bonus_reached ? '#4ade80' : 'var(--text-muted)'};">${bonusStatus}</span>
                        </div>
                    </div>
                `;
            }).join('');
        },

        renderDiceStage() {
            if (this.isRollingAnimation) return;

            const dice = this.gameState?.dice || [1, 1, 1, 1, 1];
            const held = this.gameState?.held || [false, false, false, false, false];
            const rollCount = Number(this.gameState?.roll_count || 0);
            const { isMyTurn } = this.getActivePlayerInfo();

            const diceRow = document.getElementById('dice-row');
            if (!diceRow) return;

            // Prüfen, ob ein neuer Wurf von einem Mitspieler über Polling eintraf
            const rollTime = this.gameState?.last_roll_time;
            if (rollTime && this.lastKnownRollTime && rollTime > this.lastKnownRollTime) {
                this.lastKnownRollTime = rollTime;
                this.playDiceRollAnimation(dice, held);
                return;
            }
            this.lastKnownRollTime = rollTime || this.lastKnownRollTime;

            // Würfel anzeigen
            diceRow.innerHTML = '';
            dice.forEach((val, idx) => {
                const isHeld = Boolean(held[idx]);
                const dieEl = document.createElement('div');
                dieEl.className = `qwixx-die die-white kniffel-die ${isHeld ? 'is-held' : ''} ${isMyTurn && rollCount > 0 && rollCount < 3 ? 'is-clickable' : ''}`;
                dieEl.dataset.index = String(idx);

                const pips = PIPS_MAP[val] || ['pip-center'];
                pips.forEach(pos => {
                    const pip = document.createElement('span');
                    pip.className = `die-pip ${pos}`;
                    dieEl.appendChild(pip);
                });

                if (isHeld) {
                    const lockBadge = document.createElement('span');
                    lockBadge.className = 'kniffel-die-lock';
                    lockBadge.textContent = '🔒';
                    dieEl.appendChild(lockBadge);
                }

                if (isMyTurn && rollCount > 0 && rollCount < 3) {
                    dieEl.addEventListener('click', () => this.toggleHold(idx));
                }

                diceRow.appendChild(dieEl);
            });

            // Wurf-Zähler
            const rollCounter = document.getElementById('kniffel-roll-counter');
            if (rollCounter) {
                if (rollCount === 0) {
                    rollCounter.textContent = '1. Wurf bereit';
                    rollCounter.className = 'kniffel-roll-pill';
                } else if (rollCount === 1) {
                    rollCounter.textContent = 'Wurf 1 von 3';
                    rollCounter.className = 'kniffel-roll-pill pill-roll-1';
                } else if (rollCount === 2) {
                    rollCounter.textContent = 'Wurf 2 von 3';
                    rollCounter.className = 'kniffel-roll-pill pill-roll-2';
                } else {
                    rollCounter.textContent = 'Wurf 3 von 3 (Ende)';
                    rollCounter.className = 'kniffel-roll-pill pill-roll-3';
                }
            }

            // Hinweis zum Halten
            const holdHint = document.getElementById('kniffel-hold-hint');
            if (holdHint) {
                if (isMyTurn && rollCount > 0 && rollCount < 3) {
                    holdHint.classList.remove('hidden');
                } else {
                    holdHint.classList.add('hidden');
                }
            }

            // Würfel-Button
            const btnRoll = document.getElementById('btn-roll-dice');
            if (btnRoll) {
                if (isMyTurn && rollCount < 3) {
                    btnRoll.classList.remove('hidden');
                    if (rollCount === 0) {
                        btnRoll.innerHTML = '🎲 Jetzt Würfeln (1/3)';
                    } else if (rollCount === 1) {
                        btnRoll.innerHTML = '🎲 Nochmal würfeln (2/3)';
                    } else if (rollCount === 2) {
                        btnRoll.innerHTML = '🎲 Letzter Wurf (3/3)';
                    }
                } else {
                    btnRoll.classList.add('hidden');
                }
            }
        },

        playDiceRollAnimation(targetDice, held) {
            const diceRow = document.getElementById('dice-row');
            if (!diceRow) return;

            this.isRollingAnimation = true;
            diceRow.classList.add('is-rolling');

            if ('vibrate' in navigator) {
                try { navigator.vibrate([25, 35, 25]); } catch (_) {}
            }

            let ticks = 0;
            const maxTicks = 6;
            const interval = setInterval(() => {
                ticks++;
                diceRow.innerHTML = '';
                for (let i = 0; i < 5; i++) {
                    const isHeld = Boolean(held[i]);
                    const val = isHeld ? targetDice[i] : (Math.floor(Math.random() * 6) + 1);
                    const dieEl = document.createElement('div');
                    dieEl.className = `qwixx-die die-white kniffel-die ${isHeld ? 'is-held' : ''}`;

                    const pips = PIPS_MAP[val] || ['pip-center'];
                    pips.forEach(pos => {
                        const pip = document.createElement('span');
                        pip.className = `die-pip ${pos}`;
                        dieEl.appendChild(pip);
                    });

                    if (isHeld) {
                        const lockBadge = document.createElement('span');
                        lockBadge.className = 'kniffel-die-lock';
                        lockBadge.textContent = '🔒';
                        dieEl.appendChild(lockBadge);
                    }

                    diceRow.appendChild(dieEl);
                }

                if (ticks >= maxTicks) {
                    clearInterval(interval);
                    diceRow.classList.remove('is-rolling');
                    this.isRollingAnimation = false;
                    this.renderDiceStage();

                    if ('vibrate' in navigator) {
                        try { navigator.vibrate([40]); } catch (_) {}
                    }
                }
            }, 75);
        },

        renderTurnInstructions() {
            const bar = document.getElementById('turn-instruction-bar');
            if (!bar) return;

            const { activePlayer, isMyTurn } = this.getActivePlayerInfo();
            const rollCount = Number(this.gameState?.roll_count || 0);

            if (isMyTurn) {
                if (rollCount === 0) {
                    bar.textContent = '🎲 Du bist am Zug! Tippe auf „Jetzt Würfeln“.';
                } else if (rollCount < 3) {
                    bar.textContent = '👉 Tippe auf Würfel zum Behalten 🔒 oder wähle jetzt direkt ein Feld auf deinem Zettel.';
                } else {
                    bar.textContent = '🎯 Maximale Wurfanzahl erreicht! Wähle jetzt dein gewünschtes Feld auf dem Zettel.';
                }
            } else {
                bar.textContent = `⏳ ${activePlayer?.name || 'Der aktive Spieler'} ist am Zug...`;
            }
        },

        renderSheet() {
            if (!this.mySheet) return;

            const scores = this.mySheet.scores || {};
            const rollCount = Number(this.gameState?.roll_count || 0);
            const { isMyTurn } = this.getActivePlayerInfo();
            const canScoreNow = isMyTurn && rollCount > 0;

            const categories = [
                'ones', 'twos', 'threes', 'fours', 'fives', 'sixes',
                'three_of_a_kind', 'four_of_a_kind', 'full_house',
                'small_straight', 'large_straight', 'kniffel', 'chance'
            ];

            categories.forEach(cat => {
                const valEl = document.getElementById(`val-${cat}`);
                const prevEl = document.getElementById(`prev-${cat}`);
                const rowEl = document.querySelector(`.js-category-row[data-cat="${cat}"]`);

                const isFilled = (scores[cat] !== null && scores[cat] !== undefined);
                const scoreVal = isFilled ? Number(scores[cat]) : null;

                if (rowEl) {
                    rowEl.classList.toggle('is-filled', isFilled);
                    rowEl.classList.toggle('can-score', canScoreNow && !isFilled);
                }

                if (valEl) {
                    if (isFilled) {
                        valEl.textContent = String(scoreVal);
                        valEl.classList.remove('hidden');
                        valEl.classList.toggle('is-zero', scoreVal === 0);
                    } else {
                        valEl.textContent = '-';
                        valEl.classList.remove('is-zero');
                    }
                }

                // Ausfüllhilfe (Live Preview)
                if (prevEl) {
                    if (canScoreNow && !isFilled) {
                        const previewPoints = Number(this.previews[cat] ?? 0);
                        prevEl.classList.remove('hidden');

                        if (previewPoints === 0) {
                            prevEl.textContent = '0 (Streichen)';
                            prevEl.className = 'kniffel-cat-preview preview-zero';
                        } else {
                            let extraIcon = '';
                            if (cat === 'kniffel' && previewPoints === 50) extraIcon = ' 🏆';
                            if (cat === 'full_house' && previewPoints === 25) extraIcon = ' ✨';
                            if (cat === 'large_straight' && previewPoints === 40) extraIcon = ' 🚀';
                            prevEl.textContent = `+${previewPoints}${extraIcon}`;
                            prevEl.className = 'kniffel-cat-preview preview-positive';
                        }
                    } else {
                        prevEl.classList.add('hidden');
                    }
                }
            });

            // Zwischensummen & Bonus
            const calc = this.myScore || {};
            const upperSum = Number(calc.upper_sum || 0);
            const upperBonus = Number(calc.upper_bonus || 0);
            const upperTotal = Number(calc.upper_total || 0);
            const lowerTotal = Number(calc.lower_sum || 0);
            const grandTotal = Number(calc.total || 0);

            const elUpperSum = document.getElementById('val-upper-sum');
            const elUpperBonus = document.getElementById('val-upper-bonus');
            const elUpperTotal = document.getElementById('val-upper-total');
            const elLowerTotal = document.getElementById('val-lower-total');
            const elGrandTotal = document.getElementById('val-grand-total');
            const bonusBadge = document.getElementById('upper-bonus-badge');

            if (elUpperSum) elUpperSum.textContent = String(upperSum);
            if (elUpperBonus) elUpperBonus.textContent = String(upperBonus);
            if (elUpperTotal) elUpperTotal.textContent = String(upperTotal);
            if (elLowerTotal) elLowerTotal.textContent = String(lowerTotal);
            if (elGrandTotal) elGrandTotal.textContent = `${grandTotal} Pkt.`;

            if (bonusBadge) {
                if (upperSum >= 63) {
                    bonusBadge.textContent = 'Bonus gesichert! 🏆 (+35 Pkt.)';
                    bonusBadge.className = 'kniffel-bonus-badge badge-bonus-achieved';
                } else {
                    const needed = Math.max(0, 63 - upperSum);
                    bonusBadge.textContent = `Noch ${needed} Pkt. für Bonus (+35)`;
                    bonusBadge.className = 'kniffel-bonus-badge';
                }
            }
        },

        async rollDice() {
            const btnRoll = document.getElementById('btn-roll-dice');
            if (btnRoll) btnRoll.disabled = true;

            const res = await HubHttp.postJson('./api.php?action=roll', {
                room_code: this.roomCode
            });
            if (btnRoll) btnRoll.disabled = false;

            if (res.ok && res.data && res.data.success) {
                const held = res.data.held || [];
                this.playDiceRollAnimation(res.data.dice, held);
                this.fetchState();
            } else if (res.data?.message) {
                await HubDialog.alert('Hinweis', res.data.message, '⚠️');
            }
        },

        async toggleHold(dieIndex) {
            const res = await HubHttp.postJson('./api.php?action=toggle_hold', {
                room_code: this.roomCode,
                index: dieIndex
            });

            if (res.ok && res.data && res.data.success) {
                if ('vibrate' in navigator) {
                    try { navigator.vibrate([20]); } catch (_) {}
                }
                if (this.gameState) {
                    this.gameState.held = res.data.held;
                }
                this.renderDiceStage();
            }
        },

        async chooseCategory(category) {
            const { isMyTurn } = this.getActivePlayerInfo();
            if (!isMyTurn) {
                return;
            }

            const rollCount = Number(this.gameState?.roll_count || 0);
            if (rollCount === 0) {
                await HubDialog.alert('Zuerst würfeln', 'Bitte würfle zuerst mindestens einmal, bevor du ein Feld auswählst.', '🎲');
                return;
            }

            const scores = this.mySheet?.scores || {};
            if (scores[category] !== null && scores[category] !== undefined) {
                return; // Bereits belegt
            }

            const previewPoints = Number(this.previews[category] ?? 0);

            // Sicherheitsabfrage, falls das Feld mit 0 Punkten gestrichen werden muss
            if (previewPoints === 0) {
                const catNames = {
                    ones: 'Einer', twos: 'Zweier', threes: 'Dreier', fours: 'Vierer', fives: 'Fünfer', sixes: 'Sechser',
                    three_of_a_kind: 'Dreierpasch', four_of_a_kind: 'Viererpasch', full_house: 'Full House',
                    small_straight: 'Kleine Straße', large_straight: 'Große Straße', kniffel: 'Kniffel', chance: 'Chance'
                };
                const name = catNames[category] || category;
                const proceed = await HubDialog.confirm(
                    'Feld streichen?',
                    `Dein aktueller Wurf bringt für „${name}“ 0 Punkte ein.\n\nMöchtest du das Feld wirklich mit 0 streichen?`,
                    {
                        icon: '⚠️',
                        confirmText: 'Mit 0 streichen',
                        cancelText: 'Abbrechen',
                        confirmClass: 'btn-danger'
                    }
                );
                if (!proceed) return;
            }

            const res = await HubHttp.postJson('./api.php?action=score', {
                room_code: this.roomCode,
                category: category
            });

            if (res.ok && res.data && res.data.success) {
                this.mySheet = res.data.sheet;
                this.myScore = res.data.score_summary;
                this.renderSheet();
                this.fetchState();
            } else if (res.data?.message) {
                await HubDialog.alert('Hinweis', res.data.message, '⚠️');
            }
        },

        async startGame() {
            const res = await HubHttp.postJson('./api.php?action=start', {
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

        async editPlayerName() {
            const currentName = this.myName || localStorage.getItem('kai_gh_player_name') || '';
            const newName = await HubDialog.prompt(
                'Spielername ändern',
                'Wie möchtest du im laufenden Spiel heißen?',
                currentName,
                {
                    icon: '✏️',
                    confirmText: 'Speichern',
                    placeholder: 'Dein Name...',
                }
            );

            if (!newName || newName === currentName) {
                return;
            }

            const res = await HubHttp.postJson('./api.php?action=update_name', {
                room_code: this.roomCode,
                player_name: newName,
            });

            if (res.ok && res.data && res.data.success) {
                this.myName = newName;
                localStorage.setItem('kai_gh_player_name', newName);
                document.querySelectorAll('.js-my-name-display').forEach(el => {
                    el.textContent = newName;
                });
                this.fetchState();
            } else if (res.data?.message) {
                await HubDialog.alert('Hinweis', res.data.message, '⚠️');
            }
        },

        renderGameOver() {
            const modal = document.getElementById('game-over-modal');
            const list = document.getElementById('game-over-leaderboard');
            const btnRematch = document.getElementById('btn-rematch');
            if (!modal || !list) return;

            modal.classList.remove('hidden');

            const sorted = [...this.players].sort((a, b) => (b.total_score || 0) - (a.total_score || 0));

            list.innerHTML = sorted.map((p, idx) => `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0.9rem; margin-bottom: 0.4rem; background: rgba(255, 255, 255, 0.05); border-radius: 8px;">
                    <span>${idx === 0 ? '👑' : `#${idx + 1}`} ${HubHtml.escape(p.name)}</span>
                    <span style="color: var(--accent); font-size: 1.15rem; font-weight: 700;">${p.total_score || 0} Pkt.</span>
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
        KniffelApp.init();
    });
})();

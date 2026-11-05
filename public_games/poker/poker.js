/**
 * poker.js - Texas Hold'em Poker für Quarxl Games
 * Integriert mit QuarxlCards (Französisches 52er Blatt), HubHttp und SoundFx
 */
(function () {
    'use strict';

    const urlParams = new URLSearchParams(window.location.search);
    const roomCode = (urlParams.get('room') || document.body.dataset.room || '').toUpperCase().trim();

    let myPlayerId = null;
    let isHost = false;
    let isPolling = false;
    let isJoining = false;
    let currentData = null;
    let lastEventKey = '';

    // -------------------------------------------------------------------------
    // INITIALISIERUNG
    // -------------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        if (!roomCode) {
            alert('Kein Raum-Code angegeben.');
            window.location.href = '../index.php';
            return;
        }

        setupHeader();
        setupJoinModal();
        setupActionControls();
        setupLobbyControls();
        setupShowdownControls();
        setupAbortControls();

        // Gespeicherten Spielernamen in Top-Bar anzeigen
        const savedName = localStorage.getItem('kai_gh_player_name') || '';
        if (savedName) {
            document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = savedName);
        }

        startPolling();
    });

    // -------------------------------------------------------------------------
    // HEADER & METADATEN
    // -------------------------------------------------------------------------
    function setupHeader() {
        const copyBtn = document.getElementById('btn-copy-code');
        if (copyBtn) {
            copyBtn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(roomCode);
                    const oldText = copyBtn.innerHTML;
                    copyBtn.innerHTML = 'Kopiert! ✓';
                    setTimeout(() => { copyBtn.innerHTML = oldText; }, 1800);
                } catch (_) {}
            });
        }

        const shareBtn = document.querySelector('.js-share-whatsapp');
        if (shareBtn) {
            shareBtn.addEventListener('click', () => {
                const url = window.location.href;
                const text = `Komm an den Pokertisch! Wir spielen Texas Hold'em No-Limit auf Quarxl.\nTisch: ${roomCode}\n${url}`;
                window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
            });
        }

        const editNameBtn = document.querySelector('.js-btn-edit-name');
        if (editNameBtn) {
            editNameBtn.addEventListener('click', async () => {
                const currentName = localStorage.getItem('kai_gh_player_name') || 'Ich';
                const newName = await HubDialog.prompt(
                    'Spielername ändern',
                    'Wie möchtest du am Pokertisch heißen?',
                    currentName,
                    {
                        icon: '✏️',
                        confirmText: 'Speichern',
                        placeholder: 'Dein Name...',
                    }
                );
                if (newName && newName.trim() && newName.trim() !== currentName) {
                    const trimmed = newName.trim().substring(0, 25);
                    try {
                        const res = await HubHttp.postJson(`./api.php?action=rename&room=${encodeURIComponent(roomCode)}`, {
                            name: trimmed,
                            player_name: trimmed,
                        });
                        if (res.ok && res.data && res.data.success) {
                            localStorage.setItem('kai_gh_player_name', trimmed);
                            document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = trimmed);
                            pollStatus();
                        } else if (res.data?.message) {
                            await HubDialog.alert('Hinweis', res.data.message, '⚠️');
                        }
                    } catch (_) {}
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // BEITRITT-MODAL & RECONNECT
    // -------------------------------------------------------------------------
    function setupJoinModal() {
        const joinInput = document.getElementById('join-player-name');
        const confirmBtn = document.querySelector('.js-btn-confirm-join');

        const savedName = localStorage.getItem('kai_gh_player_name') || '';
        if (joinInput && savedName) joinInput.value = savedName;

        if (confirmBtn) {
            confirmBtn.addEventListener('click', () => {
                const name = (joinInput?.value || '').trim() || 'Gast';
                joinRoom(name);
            });
        }

        if (joinInput) {
            joinInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    const name = (joinInput.value || '').trim() || 'Gast';
                    joinRoom(name);
                }
            });
        }
    }

    function showJoinModal() {
        const modal = document.getElementById('join-modal');
        if (modal) {
            modal.classList.remove('hidden');
            const input = document.getElementById('join-player-name');
            if (input) {
                const savedName = localStorage.getItem('kai_gh_player_name') || '';
                if (savedName) input.value = savedName;
                input.focus();
            }
        }
    }

    function showJoinError(msg) {
        const err = document.getElementById('join-error');
        if (err) {
            err.textContent = msg;
            err.classList.remove('hidden');
        }
    }

    async function handleNotJoined() {
        const savedName = (localStorage.getItem('kai_gh_player_name') || '').trim();
        if (savedName && !isJoining) {
            await joinRoom(savedName);
        } else {
            showJoinModal();
        }
    }

    async function joinRoom(name) {
        if (isJoining) return;
        isJoining = true;

        try {
            const res = await HubHttp.postJson('./api.php?action=join', {
                room_code: roomCode,
                player_name: name,
            });

            if (res.ok && res.data && res.data.success) {
                if (res.data.player_token) {
                    localStorage.setItem('kai_gh_player_token', res.data.player_token);
                }
                localStorage.setItem('kai_gh_player_name', name);
                document.getElementById('join-modal')?.classList.add('hidden');
                document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = name);
                isJoining = false;
                pollStatus();
            } else {
                isJoining = false;
                showJoinError(res.data?.message || 'Beitritt fehlgeschlagen');
                showJoinModal();
            }
        } catch (_) {
            isJoining = false;
            showJoinError('Verbindungsfehler beim Beitritt');
            showJoinModal();
        }
    }

    // -------------------------------------------------------------------------
    // POLLING SCHLEIFE
    // -------------------------------------------------------------------------
    function startPolling() {
        pollStatus();
        setInterval(pollStatus, 1200);
    }

    async function pollStatus() {
        if (isPolling || isJoining) return;
        isPolling = true;

        try {
            const res = await HubHttp.getJson(`./api.php?action=state&room=${encodeURIComponent(roomCode)}`);

            if (res.status === 401) {
                handleNotJoined();
                return;
            }

            if (res.ok && res.data && res.data.success && res.data.data) {
                const data = res.data.data;
                currentData = data;

                // Eigenen Spieler identifizieren (anhand des gespeicherten Tokens oder Namens)
                const savedName = (localStorage.getItem('kai_gh_player_name') || '').trim();
                const matchedPlayer = data.players.find(p => p.name === savedName) || data.players[0];
                if (matchedPlayer) {
                    myPlayerId = matchedPlayer.id;
                    isHost = Boolean(matchedPlayer.is_host);
                }

                document.getElementById('join-modal')?.classList.add('hidden');
                renderGame(data);
                handleAudioEvents(data.last_event);
            }
        } catch (_) {
        } finally {
            isPolling = false;
        }
    }

    // -------------------------------------------------------------------------
    // AUDIO- & EVENT-FEEDBACK
    // -------------------------------------------------------------------------
    function handleAudioEvents(lastEvt) {
        if (!lastEvt || !lastEvt.time) return;
        const key = `${lastEvt.time}_${lastEvt.type}`;
        if (key === lastEventKey) return;
        lastEventKey = key;

        if (window.QuarxlCards && window.QuarxlCards.SoundFx) {
            const fx = window.QuarxlCards.SoundFx;
            if (lastEvt.type === 'street_dealt' || lastEvt.type === 'cards_dealt') {
                fx.cardWhoosh();
            } else if (lastEvt.type === 'bet' || lastEvt.type === 'raise' || lastEvt.type === 'call') {
                fx.cardPlace();
            } else if (lastEvt.type === 'fold') {
                fx.cardWhoosh();
            } else if (lastEvt.type === 'showdown' || lastEvt.type === 'game_over') {
                fx.shuffle();
            }
        }
    }

    // -------------------------------------------------------------------------
    // RENDERING HAUPTROUTINE
    // -------------------------------------------------------------------------
    function renderGame(data) {
        const phase = data.phase || 'lobby';
        const myPlayer = data.players.find(p => p.id === myPlayerId);

        // Name in Top-Bar
        if (myPlayer) {
            const nameEl = document.querySelector('.js-my-name-display');
            if (nameEl) nameEl.textContent = myPlayer.name;
        }

        // Abbruch-Button in der Top-Bar steuern
        const abortBtn = document.getElementById('btn-abort-game');
        if (abortBtn) {
            abortBtn.classList.toggle('hidden', phase === 'lobby');
        }

        // Abbruch-Banner steuern
        renderAbortBanner(data);

        // Ansichten umschalten (Lobby vs. Arena)
        const paneLobby = document.getElementById('pane-lobby');
        const paneArena = document.getElementById('pane-arena');

        if (phase === 'lobby') {
            paneLobby?.classList.remove('hidden');
            paneArena?.classList.add('hidden');
            document.getElementById('modal-poker-showdown')?.classList.add('hidden');
            renderLobby(data);
            return;
        }

        paneLobby?.classList.add('hidden');
        paneArena?.classList.remove('hidden');

        // Showdown Modal
        const showdownModal = document.getElementById('modal-poker-showdown');
        if (phase === 'showdown' || phase === 'game_over') {
            showdownModal?.classList.remove('hidden');
            renderShowdown(data);
        } else {
            showdownModal?.classList.add('hidden');
        }

        renderArena(data, myPlayer);
    }

    // -------------------------------------------------------------------------
    // LOBBY RENDERING
    // -------------------------------------------------------------------------
    function renderLobby(data) {
        const listEl = document.getElementById('lobby-players-list');
        if (!listEl) return;

        listEl.innerHTML = '';
        data.players.forEach(p => {
            const row = document.createElement('div');
            row.className = 'poker-lobby-player-row';

            const left = document.createElement('div');
            left.style.display = 'flex';
            left.style.alignItems = 'center';
            left.style.gap = '0.5rem';

            const icon = p.is_bot ? '🤖' : '👤';
            const hostBadge = p.is_host ? '<span class="badge" style="background:#f59e0b; color:#000; font-size:0.7rem; padding:0.15rem 0.4rem; border-radius:4px; font-weight:800;">HOST</span>' : '';
            const botBadge = p.is_bot ? '<span class="badge" style="background:#64748b; color:#fff; font-size:0.7rem; padding:0.15rem 0.4rem; border-radius:4px;">BOT</span>' : '';

            left.innerHTML = `<span>${icon}</span> <strong>${KaiHtml.escape(p.name)}</strong> ${hostBadge} ${botBadge}`;

            const right = document.createElement('div');
            right.style.display = 'flex';
            right.style.alignItems = 'center';
            right.style.gap = '0.5rem';

            right.innerHTML = `<span style="color:#fbbf24; font-weight:700;">🟡 ${p.chips.toLocaleString('de-DE')}</span>`;

            // Kick-Bot Button für Host
            if (isHost && p.is_bot) {
                const kickBtn = document.createElement('button');
                kickBtn.type = 'button';
                kickBtn.className = 'btn btn-sm btn-outline';
                kickBtn.style.padding = '0.15rem 0.45rem';
                kickBtn.style.fontSize = '0.75rem';
                kickBtn.textContent = '❌';
                kickBtn.title = 'Bot entfernen';
                kickBtn.addEventListener('click', async () => {
                    await HubHttp.postJson(`./api.php?action=kick_bot&room=${encodeURIComponent(roomCode)}`, { bot_id: p.id });
                    pollStatus();
                });
                right.appendChild(kickBtn);
            }

            row.appendChild(left);
            row.appendChild(right);
            listEl.appendChild(row);
        });

        const addBotBtn = document.getElementById('btn-add-bot');
        const startBtn = document.getElementById('btn-start-game');

        if (isHost) {
            addBotBtn?.classList.remove('hidden');
            startBtn?.classList.remove('hidden');

            if (addBotBtn) {
                addBotBtn.disabled = data.players.length >= 8;
            }
            if (startBtn) {
                startBtn.disabled = data.players.length < 2;
                startBtn.textContent = data.players.length < 2
                    ? 'Warte auf mindestens 2 Spieler...'
                    : `🚀 Spiel mit ${data.players.length} Spielern starten`;
            }
        } else {
            addBotBtn?.classList.add('hidden');
            startBtn?.classList.add('hidden');
        }
    }

    function setupLobbyControls() {
        const addBotBtn = document.getElementById('btn-add-bot');
        if (addBotBtn) {
            addBotBtn.addEventListener('click', async () => {
                addBotBtn.disabled = true;
                try {
                    await HubHttp.postJson(`./api.php?action=add_bot&room=${encodeURIComponent(roomCode)}`, {});
                    pollStatus();
                } finally {
                    addBotBtn.disabled = false;
                }
            });
        }

        const startBtn = document.getElementById('btn-start-game');
        if (startBtn) {
            startBtn.addEventListener('click', async () => {
                startBtn.disabled = true;
                try {
                    const res = await HubHttp.postJson(`./api.php?action=start&room=${encodeURIComponent(roomCode)}`, {});
                    if (!res.ok || !res.data?.success) {
                        await HubDialog.alert('Hinweis', res.data?.message || 'Spiel konnte nicht gestartet werden.', '⚠️');
                    }
                    pollStatus();
                } finally {
                    startBtn.disabled = false;
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // ARENA RENDERING
    // -------------------------------------------------------------------------
    function renderArena(data, myPlayer) {
        // Pot & Street
        const potEl = document.getElementById('poker-pot-amount');
        if (potEl) potEl.textContent = data.pot.toLocaleString('de-DE');

        const streetEl = document.getElementById('poker-street-indicator');
        if (streetEl) {
            const streetMap = {
                preflop: 'Pre-Flop',
                flop: 'Flop',
                turn: 'Turn',
                river: 'River',
                showdown: 'Showdown',
                game_over: 'Spielende'
            };
            streetEl.textContent = streetMap[data.phase] || data.phase;
        }

        // 5 Community Cards
        renderCommunityCards(data.community_cards || []);

        // Turn Indicator Banner
        renderTurnBanner(data, myPlayer);

        // Gegner-Sitze um den Tisch
        renderSeats(data);

        // Mein Spielerbereich unten
        renderMyArea(data, myPlayer);

        // Aktionsleiste & Slider
        renderActionBar(data, myPlayer);
    }

    function renderCommunityCards(commCards) {
        const container = document.getElementById('community-cards-container');
        if (!container) return;

        const slotIds = ['card-flop-1', 'card-flop-2', 'card-flop-3', 'card-turn', 'card-river'];
        slotIds.forEach((slotId, index) => {
            const slot = document.getElementById(slotId);
            if (!slot) return;

            const card = commCards[index];
            if (card && window.QuarxlCards) {
                slot.innerHTML = window.QuarxlCards.renderCardHtml(card, { deck: 'french' });
            } else {
                slot.innerHTML = '';
            }
        });
    }

    function renderTurnBanner(data, myPlayer) {
        const banner = document.getElementById('poker-turn-banner');
        const textEl = document.getElementById('poker-turn-indicator-text');
        if (!banner || !textEl) return;

        if (data.phase === 'showdown' || data.phase === 'game_over') {
            banner.classList.remove('my-turn');
            textEl.textContent = '🃏 Showdown: Karten werden ausgewertet';
            return;
        }

        const isMyTurn = (data.current_player_id === myPlayerId);
        banner.classList.toggle('my-turn', isMyTurn);

        if (isMyTurn) {
            textEl.textContent = '👉 Du bist am Zug!';
        } else {
            const activePlayer = data.players.find(p => p.id === data.current_player_id);
            const activeName = activePlayer ? activePlayer.name : 'Anderer Spieler';
            textEl.textContent = `⏳ ${activeName} ist am Zug...`;
        }
    }

    function renderSeats(data) {
        const container = document.getElementById('poker-seats-container');
        if (!container) return;

        container.innerHTML = '';
        const opponents = data.players.filter(p => p.id !== myPlayerId);

        opponents.forEach(p => {
            const seat = document.createElement('div');
            seat.className = 'poker-seat';

            if (data.current_player_id === p.id && data.phase !== 'showdown') {
                seat.classList.add('is-active-turn');
            }
            if (p.is_folded) seat.classList.add('is-folded');
            if (p.is_all_in) seat.classList.add('is-all-in');

            // Name & Role Badge
            let roleHtml = '';
            if (p.id === data.dealer_id) roleHtml = '<span class="poker-role-badge role-d" title="Dealer Button">D</span>';
            else if (p.id === data.sb_player_id) roleHtml = '<span class="poker-role-badge role-sb" title="Small Blind">SB</span>';
            else if (p.id === data.bb_player_id) roleHtml = '<span class="poker-role-badge role-bb" title="Big Blind">BB</span>';

            const nameRow = document.createElement('div');
            nameRow.style.display = 'flex';
            nameRow.style.alignItems = 'center';
            nameRow.style.gap = '0.2rem';
            nameRow.innerHTML = `<span class="seat-player-name">${KaiHtml.escape(p.name)}</span> ${roleHtml}`;
            seat.appendChild(nameRow);

            // Chips
            const chipsEl = document.createElement('div');
            chipsEl.className = 'seat-chips';
            chipsEl.innerHTML = `🟡 ${p.chips.toLocaleString('de-DE')}`;
            seat.appendChild(chipsEl);

            // Current Bet
            if (p.current_bet > 0) {
                const betEl = document.createElement('div');
                betEl.className = 'seat-bet-badge';
                betEl.textContent = `Bet: ${p.current_bet.toLocaleString('de-DE')}`;
                seat.appendChild(betEl);
            }

            // Cards Preview
            if (p.hand && p.hand.length > 0 && !p.is_folded) {
                const cardsRow = document.createElement('div');
                cardsRow.className = 'seat-cards-preview';

                p.hand.forEach(card => {
                    if (card.is_hidden && window.QuarxlCards) {
                        cardsRow.innerHTML += window.QuarxlCards.renderCardBackHtml();
                    } else if (window.QuarxlCards) {
                        cardsRow.innerHTML += window.QuarxlCards.renderCardHtml(card, { deck: 'french' });
                    }
                });
                seat.appendChild(cardsRow);
            }

            container.appendChild(seat);
        });
    }

    function renderMyArea(data, myPlayer) {
        if (!myPlayer) return;

        // Eigene Chips
        const chipsEl = document.getElementById('my-chips-val');
        if (chipsEl) chipsEl.textContent = myPlayer.chips.toLocaleString('de-DE');

        // Eigene Rolle (D, SB, BB)
        const roleBadge = document.getElementById('my-role-badge');
        if (roleBadge) {
            roleBadge.className = 'poker-role-badge';
            if (myPlayer.id === data.dealer_id) {
                roleBadge.textContent = 'D';
                roleBadge.classList.add('role-d');
                roleBadge.classList.remove('hidden');
                roleBadge.title = 'Dealer Button';
            } else if (myPlayer.id === data.sb_player_id) {
                roleBadge.textContent = 'SB';
                roleBadge.classList.add('role-sb');
                roleBadge.classList.remove('hidden');
                roleBadge.title = 'Small Blind';
            } else if (myPlayer.id === data.bb_player_id) {
                roleBadge.textContent = 'BB';
                roleBadge.classList.add('role-bb');
                roleBadge.classList.remove('hidden');
                roleBadge.title = 'Big Blind';
            } else {
                roleBadge.classList.add('hidden');
            }
        }

        // Eigene Handkarten
        const holeContainer = document.getElementById('my-hole-cards');
        if (holeContainer) {
            holeContainer.innerHTML = '';
            if (myPlayer.hand && myPlayer.hand.length > 0) {
                myPlayer.hand.forEach(c => {
                    if (window.QuarxlCards) {
                        holeContainer.innerHTML += window.QuarxlCards.renderCardHtml(c, { deck: 'french' });
                    }
                });
            }
            holeContainer.style.opacity = myPlayer.is_folded ? '0.4' : '1';
        }

        // Hand-Stärke Badge
        const strengthBadge = document.getElementById('my-hand-strength-badge');
        if (strengthBadge) {
            if (data.my_score && !myPlayer.is_folded) {
                strengthBadge.textContent = data.my_score.label;
                strengthBadge.classList.remove('hidden');
            } else {
                strengthBadge.classList.add('hidden');
            }
        }
    }

    // -------------------------------------------------------------------------
    // AKTIONEN & SETZ-SLIDER
    // -------------------------------------------------------------------------
    function renderActionBar(data, myPlayer) {
        const isMyTurn = (
            data.current_player_id === myPlayerId &&
            data.phase !== 'showdown' &&
            data.phase !== 'game_over' &&
            myPlayer &&
            !myPlayer.is_folded &&
            !myPlayer.is_all_in &&
            !myPlayer.is_out
        );

        const foldBtn = document.getElementById('btn-poker-fold');
        const checkCallBtn = document.getElementById('btn-poker-check-call');
        const betRaiseBtn = document.getElementById('btn-poker-bet-raise');
        const sliderRow = document.getElementById('poker-bet-slider-row');
        const slider = document.getElementById('poker-bet-slider');
        const sliderVal = document.getElementById('poker-bet-slider-val');

        if (foldBtn) foldBtn.disabled = !isMyTurn;
        if (checkCallBtn) checkCallBtn.disabled = !isMyTurn;

        // Check vs. Call
        if (checkCallBtn) {
            if (data.can_check) {
                checkCallBtn.textContent = '✋ Check';
                checkCallBtn.dataset.action = 'check';
                checkCallBtn.dataset.amount = '0';
            } else {
                checkCallBtn.textContent = `💸 Call ${data.call_amount.toLocaleString('de-DE')}`;
                checkCallBtn.dataset.action = 'call';
                checkCallBtn.dataset.amount = String(data.call_amount);
            }
        }

        // Bet vs. Raise & Slider-Grenzen
        const minBet = Number(data.min_bet_amount || 20);
        const maxBet = Number(data.max_bet_amount || 1000);
        const canBetOrRaise = isMyTurn && maxBet >= minBet && (myPlayer?.chips || 0) > 0;

        if (sliderRow) {
            sliderRow.style.opacity = canBetOrRaise ? '1' : '0.4';
            sliderRow.style.pointerEvents = canBetOrRaise ? 'auto' : 'none';
        }

        if (slider) {
            slider.min = String(minBet);
            slider.max = String(maxBet);
            slider.step = '10';

            // Wert anpassen falls außerhalb der Schranken
            let curVal = parseInt(slider.value, 10);
            if (isNaN(curVal) || curVal < minBet) curVal = minBet;
            if (curVal > maxBet) curVal = maxBet;
            slider.value = String(curVal);

            if (sliderVal) sliderVal.textContent = curVal.toLocaleString('de-DE');
        }

        if (betRaiseBtn) {
            betRaiseBtn.disabled = !canBetOrRaise;
            updateBetRaiseButtonText(data);
        }
    }

    function updateBetRaiseButtonText(data) {
        const betRaiseBtn = document.getElementById('btn-poker-bet-raise');
        const slider = document.getElementById('poker-bet-slider');
        if (!betRaiseBtn || !slider) return;

        const val = parseInt(slider.value, 10) || 0;
        const highestBet = data?.current_highest_bet || 0;

        if (highestBet === 0) {
            betRaiseBtn.textContent = `🚀 Bet ${val.toLocaleString('de-DE')}`;
            betRaiseBtn.dataset.action = 'bet';
        } else {
            betRaiseBtn.textContent = `🚀 Raise auf ${val.toLocaleString('de-DE')}`;
            betRaiseBtn.dataset.action = 'raise';
        }
        betRaiseBtn.dataset.amount = String(val);
    }

    function setupActionControls() {
        const foldBtn = document.getElementById('btn-poker-fold');
        const checkCallBtn = document.getElementById('btn-poker-check-call');
        const betRaiseBtn = document.getElementById('btn-poker-bet-raise');
        const slider = document.getElementById('poker-bet-slider');
        const sliderVal = document.getElementById('poker-bet-slider-val');

        if (slider && sliderVal) {
            slider.addEventListener('input', () => {
                sliderVal.textContent = parseInt(slider.value, 10).toLocaleString('de-DE');
                if (currentData) updateBetRaiseButtonText(currentData);
            });
        }

        // Quick Bet Buttons (Min, 1/2 Pot, Pot, All-In)
        document.querySelectorAll('.js-btn-quick-bet').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!slider || !currentData) return;
                const frac = btn.dataset.fraction;
                const minBet = Number(slider.min);
                const maxBet = Number(slider.max);
                const pot = currentData.pot || 0;

                let target = minBet;
                if (frac === 'min') {
                    target = minBet;
                } else if (frac === 'half') {
                    target = Math.max(minBet, Math.min(maxBet, Math.floor(pot / 2)));
                } else if (frac === 'pot') {
                    target = Math.max(minBet, Math.min(maxBet, pot));
                } else if (frac === 'allin') {
                    target = maxBet;
                }

                // Runden auf Vielfaches von 10
                target = Math.round(target / 10) * 10;
                target = Math.max(minBet, Math.min(maxBet, target));

                slider.value = String(target);
                if (sliderVal) sliderVal.textContent = target.toLocaleString('de-DE');
                updateBetRaiseButtonText(currentData);
            });
        });

        // Fold Action
        if (foldBtn) {
            foldBtn.addEventListener('click', async () => {
                foldBtn.disabled = true;
                try {
                    await HubHttp.postJson(`./api.php?action=play_turn&room=${encodeURIComponent(roomCode)}`, {
                        action_type: 'fold',
                        amount: 0,
                    });
                    pollStatus();
                } finally {
                    foldBtn.disabled = false;
                }
            });
        }

        // Check / Call Action
        if (checkCallBtn) {
            checkCallBtn.addEventListener('click', async () => {
                const act = checkCallBtn.dataset.action || 'check';
                const amt = parseInt(checkCallBtn.dataset.amount, 10) || 0;
                checkCallBtn.disabled = true;
                try {
                    await HubHttp.postJson(`./api.php?action=play_turn&room=${encodeURIComponent(roomCode)}`, {
                        action_type: act,
                        amount: amt,
                    });
                    pollStatus();
                } finally {
                    checkCallBtn.disabled = false;
                }
            });
        }

        // Bet / Raise Action
        if (betRaiseBtn) {
            betRaiseBtn.addEventListener('click', async () => {
                const act = betRaiseBtn.dataset.action || 'bet';
                const amt = parseInt(betRaiseBtn.dataset.amount, 10) || 0;
                betRaiseBtn.disabled = true;
                try {
                    await HubHttp.postJson(`./api.php?action=play_turn&room=${encodeURIComponent(roomCode)}`, {
                        action_type: act,
                        amount: amt,
                    });
                    pollStatus();
                } finally {
                    betRaiseBtn.disabled = false;
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // SHOWDOWN MODAL RENDERING
    // -------------------------------------------------------------------------
    function renderShowdown(data) {
        const res = data.round_result;
        if (!res) return;

        const titleEl = document.getElementById('poker-showdown-title');
        const summaryEl = document.getElementById('poker-showdown-summary');
        const listEl = document.getElementById('poker-showdown-results-list');
        const nextBtn = document.getElementById('btn-next-hand');

        if (titleEl) {
            titleEl.textContent = res.is_game_over ? '🏆 Gesamtsieg!' : '🃏 Hand-Ergebnis';
        }

        if (summaryEl) {
            if (res.won_by_fold) {
                summaryEl.innerHTML = `<strong>${KaiHtml.escape(res.winner_name || 'Ein Spieler')}</strong> gewinnt kampflos, da alle anderen gefoldet haben.`;
            } else if (res.pot_resolutions && res.pot_resolutions.length > 0) {
                const parts = res.pot_resolutions.map(pot => {
                    const names = (pot.winner_names || []).map(KaiHtml.escape).join(', ');
                    return `<strong>${names}</strong> gewinnt mit <em>${KaiHtml.escape(pot.hand_desc)}</em> (${pot.amount.toLocaleString('de-DE')} Chips)`;
                });
                summaryEl.innerHTML = parts.join('<br>');
            } else {
                summaryEl.textContent = 'Showdown ausgewertet.';
            }
        }

        if (listEl) {
            listEl.innerHTML = '';
            data.players.forEach(p => {
                if (p.is_folded || !p.hand || p.hand.length === 0) return;

                const cardDiv = document.createElement('div');
                cardDiv.className = 'showdown-player-card';

                const isWinner = (res.payouts && (res.payouts[p.id] || 0) > 0);
                if (isWinner) cardDiv.classList.add('is-winner');

                const left = document.createElement('div');
                left.style.display = 'flex';
                left.style.flexDirection = 'column';
                left.style.gap = '0.2rem';

                const evalInfo = res.evaluations ? res.evaluations[p.id] : null;
                const handDesc = evalInfo ? evalInfo.label : '';

                left.innerHTML = `
                    <div style="font-weight: 800; font-size: 0.95rem;">${KaiHtml.escape(p.name)}</div>
                    <div style="font-size: 0.8rem; color: #38bdf8;">${KaiHtml.escape(handDesc)}</div>
                `;

                if (isWinner) {
                    const wonAmount = res.payouts[p.id];
                    left.innerHTML += `<div style="font-size: 0.82rem; color: #22c55e; font-weight: 800;">+ ${wonAmount.toLocaleString('de-DE')} Chips gewonnen! 🏆</div>`;
                }

                const right = document.createElement('div');
                right.style.display = 'flex';
                right.style.gap = '0.25rem';

                p.hand.forEach(c => {
                    if (window.QuarxlCards) {
                        right.innerHTML += window.QuarxlCards.renderCardHtml(c, { deck: 'french' });
                    }
                });

                cardDiv.appendChild(left);
                cardDiv.appendChild(right);
                listEl.appendChild(cardDiv);
            });
        }

        if (nextBtn) {
            if (res.is_game_over) {
                nextBtn.textContent = '🔄 Neues Spiel starten';
                nextBtn.dataset.action = 'restart';
            } else {
                nextBtn.textContent = '➡️ Nächste Hand starten';
                nextBtn.dataset.action = 'next_hand';
            }
        }
    }

    function setupShowdownControls() {
        const nextBtn = document.getElementById('btn-next-hand');
        if (nextBtn) {
            nextBtn.addEventListener('click', async () => {
                const act = nextBtn.dataset.action || 'next_hand';
                nextBtn.disabled = true;
                try {
                    await HubHttp.postJson(`./api.php?action=${act}&room=${encodeURIComponent(roomCode)}`, {});
                    pollStatus();
                } finally {
                    nextBtn.disabled = false;
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // SPIELABBRUCH (VOTE TO ABORT)
    // -------------------------------------------------------------------------
    function renderAbortBanner(data) {
        const banner = document.getElementById('abort-vote-banner');
        const textEl = document.getElementById('abort-banner-text');
        const confirmBtn = document.getElementById('btn-confirm-abort');
        if (!banner) return;

        const votes = data.abort_votes || [];
        const humanPlayers = data.players.filter(p => !p.is_bot && p.is_connected);

        if (votes.length > 0 && data.phase !== 'lobby') {
            banner.classList.remove('hidden');
            if (textEl) {
                textEl.textContent = `🏳️ Spielabbruch vorgeschlagen: ${votes.length} von ${humanPlayers.length} Spielern haben zugestimmt.`;
            }
            if (confirmBtn) {
                const hasVoted = votes.includes(myPlayerId);
                confirmBtn.disabled = hasVoted;
                confirmBtn.textContent = hasVoted ? '✓ Zugestimmt' : 'Zustimmen';
            }
        } else {
            banner.classList.add('hidden');
        }
    }

    function setupAbortControls() {
        const abortBtn = document.getElementById('btn-abort-game');
        if (abortBtn) {
            abortBtn.addEventListener('click', async () => {
                const ok = await HubDialog.confirm(
                    'Spiel vorzeitig abbrechen?',
                    'Möchtest du den Spielabbruch vorschlagen? Sobald alle Mitspieler zustimmen, wird das Spiel sauber in die Lobby zurückgesetzt.',
                    {
                        icon: '🏳️',
                        confirmText: 'Abbruch vorschlagen',
                        cancelText: 'Weiterspielen',
                    }
                );
                if (ok) {
                    try {
                        const res = await HubHttp.postJson(`./api.php?action=vote_abort&room=${encodeURIComponent(roomCode)}`, {});
                        if (res.ok && res.data && res.data.success) {
                            pollStatus();
                        }
                    } catch (_) {}
                }
            });
        }

        const confirmAbortBtn = document.getElementById('btn-confirm-abort');
        if (confirmAbortBtn) {
            confirmAbortBtn.addEventListener('click', async () => {
                confirmAbortBtn.disabled = true;
                try {
                    await HubHttp.postJson(`./api.php?action=vote_abort&room=${encodeURIComponent(roomCode)}`, {});
                    pollStatus();
                } finally {
                    confirmAbortBtn.disabled = false;
                }
            });
        }
    }
})();

/**
 * schnauzer.js - Quarxl Schnauzer (31 / Schwimmen)
 * Nutzt das zentrale QuarxlCards Design-System für deutsches & französisches Blatt
 */
(function () {
    'use strict';

    const roomCode = document.body.dataset.room || '';
    let myPlayerId = null;
    let isHost = false;

    let pollTimer = null;
    let isPolling = false;
    let isJoining = false;
    let currentRoomData = null;
    let lastEventKey = '';

    // Selektions-Zustand für Kartentausch
    let selectedHandIndex = null;
    let selectedTableIndex = null;

    // -------------------------------------------------------------------------
    // INITIALISIERUNG
    // -------------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        setupHeader();
        setupJoinModal();
        setupActionButtons();

        // Gespeicherten Spielernamen in der Top-Bar anzeigen falls vorhanden
        const savedName = localStorage.getItem('kai_gh_player_name') || '';
        if (savedName) {
            document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = savedName);
        }

        // Deck-Umschalter im Header einhängen
        const toggleContainer = document.getElementById('deck-toggle-container');
        if (toggleContainer && window.QuarxlCards) {
            window.QuarxlCards.renderDeckToggle(toggleContainer, () => {
                if (currentRoomData) renderGame(currentRoomData);
            });
        }

        // Auf Deck-Änderung lauschen
        window.addEventListener('quarxl:deckchange', () => {
            if (currentRoomData) renderGame(currentRoomData);
        });

        // Polling starten (prüft Session & Beitritt)
        startPolling();
    });

    // -------------------------------------------------------------------------
    // HEADER & MODALS
    // -------------------------------------------------------------------------
    function setupHeader() {
        const shareBtn = document.querySelector('.js-share-whatsapp');
        if (shareBtn) {
            shareBtn.addEventListener('click', () => {
                const url = window.location.href;
                const text = `Komm an den Tisch! Wir spielen Schnauzer (31 / Schwimmen) auf Quarxl.\nRaum-Code: ${roomCode}\n${url}`;
                window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(text)}`, '_blank');
            });
        }

        const editNameBtn = document.querySelector('.js-btn-edit-name');
        if (editNameBtn) {
            editNameBtn.addEventListener('click', async () => {
                const currentName = localStorage.getItem('kai_gh_player_name') || 'Ich';
                const newName = window.prompt('Dein neuer Spielername:', currentName);
                if (newName && newName.trim() && newName.trim() !== currentName) {
                    const trimmed = newName.trim().substring(0, 25);
                    try {
                        const res = await HubHttp.postJson(`./api.php?action=rename&room=${encodeURIComponent(roomCode)}`, {
                            name: trimmed,
                        });
                        if (res.ok && res.data && res.data.success) {
                            localStorage.setItem('kai_gh_player_name', trimmed);
                            document.querySelectorAll('.js-my-name-display').forEach(el => el.textContent = trimmed);
                            pollStatus();
                        }
                    } catch (_) {}
                }
            });
        }
    }

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

    // -------------------------------------------------------------------------
    // BEITRITT-LOGIK (Automatischer Beitritt mit bekanntem Namen)
    // -------------------------------------------------------------------------
    async function handleNotJoined() {
        const savedName = (localStorage.getItem('kai_gh_player_name') || '').trim();
        if (savedName && !isJoining) {
            // Nahtloser automatischer Beitritt mit dem gespeicherten Quarxl-Spielernamen
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
    // POLLING-SCHLEIFE
    // -------------------------------------------------------------------------
    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollStatus();
        pollTimer = setInterval(pollStatus, 1200);
    }

    async function pollStatus() {
        if (isPolling || isJoining) return;
        isPolling = true;

        try {
            const res = await HubHttp.getJson(`./api.php?action=status&room=${encodeURIComponent(roomCode)}`);

            if (res.status === 401) {
                // Spieler noch nicht an diesem Tisch registriert
                handleNotJoined();
                return;
            }

            if (res.ok && res.data && res.data.success) {
                myPlayerId = res.data.my_player_id;
                isHost = Boolean(res.data.is_host);
                currentRoomData = res.data.data;
                document.getElementById('join-modal')?.classList.add('hidden');
                renderGame(res.data.data);
                handleEvents(res.data.data.last_event);
            }
        } catch (_) {
        } finally {
            isPolling = false;
        }
    }

    // -------------------------------------------------------------------------
    // EVENT- & AUDIO-HANDLING
    // -------------------------------------------------------------------------
    function handleEvents(lastEvt) {
        if (!lastEvt || !lastEvt.time) return;
        const key = `${lastEvt.time}_${lastEvt.type}`;
        if (key === lastEventKey) return;
        lastEventKey = key;

        if (window.QuarxlCards && window.QuarxlCards.SoundFx) {
            if (lastEvt.type === 'player_knocked') {
                window.QuarxlCards.SoundFx.knock();
            } else if (lastEvt.type === 'card_swapped_single' || lastEvt.type === 'cards_dealt') {
                window.QuarxlCards.SoundFx.cardWhoosh();
            } else if (lastEvt.type === 'card_swapped_all') {
                window.QuarxlCards.SoundFx.shuffle();
            }
        }
    }

    // -------------------------------------------------------------------------
    // RENDERING
    // -------------------------------------------------------------------------
    function renderGame(data) {
        const phase = data.phase || 'lobby';
        const myPlayer = data.players.find(p => p.id === myPlayerId);

        // Name in Top-Bar
        if (myPlayer) {
            const nameEl = document.querySelector('.js-my-name-display');
            if (nameEl) nameEl.textContent = myPlayer.name;
        }

        // Ansichten umschalten (Lobby vs. Arena)
        const paneLobby = document.getElementById('pane-lobby');
        const paneArena = document.getElementById('pane-arena');

        if (phase === 'lobby') {
            paneLobby?.classList.remove('hidden');
            paneArena?.classList.add('hidden');
            renderLobby(data);
            return;
        }

        paneLobby?.classList.add('hidden');
        paneArena?.classList.remove('hidden');

        // Geber-Auswahl Modal
        const dealerModal = document.getElementById('modal-dealer-choice');
        if (phase === 'dealing_choice' && data.dealer_id === myPlayerId) {
            dealerModal?.classList.remove('hidden');
            renderDealerPreview(data, myPlayer);
        } else {
            dealerModal?.classList.add('hidden');
        }

        // Showdown Modal
        const showdownModal = document.getElementById('modal-showdown');
        if (phase === 'showdown') {
            showdownModal?.classList.remove('hidden');
            renderShowdown(data);
        } else {
            showdownModal?.classList.add('hidden');
        }

        // Game Over Modal
        const gameOverModal = document.getElementById('modal-game-over');
        if (phase === 'game_over') {
            gameOverModal?.classList.remove('hidden');
            const wText = document.getElementById('game-over-winner-text');
            if (wText) wText.textContent = `Glückwunsch an ${data.round_result?.winner_name || 'den Gewinner'}!`;
        } else {
            gameOverModal?.classList.add('hidden');
        }

        renderArena(data, myPlayer);
    }

    // -------------------------------------------------------------------------
    // LOBBY
    // -------------------------------------------------------------------------
    function renderLobby(data) {
        const listEl = document.getElementById('lobby-players-list');
        if (!listEl) return;

        listEl.innerHTML = data.players.map(p => `
            <div class="schnauzer-lobby-player-row">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>${p.is_bot ? '🤖' : (p.is_host ? '👑' : '👤')}</span>
                    <strong>${escapeHtml(p.name)}</strong>
                    ${p.id === myPlayerId ? '<span style="color: #94a3b8; font-size: 0.8rem;">(Du)</span>' : ''}
                </div>
                <div>
                    ${(isHost && p.is_bot) ? `<button type="button" class="btn btn-sm btn-outline js-btn-kick-bot" data-bot-id="${p.id}">Entfernen</button>` : ''}
                </div>
            </div>
        `).join('');

        // Bot-Kicken Event-Delegation
        listEl.querySelectorAll('.js-btn-kick-bot').forEach(btn => {
            btn.addEventListener('click', async () => {
                const botId = parseInt(btn.dataset.botId, 10);
                await HubHttp.postJson(`./api.php?action=kick_bot&room=${encodeURIComponent(roomCode)}`, { bot_id: botId });
                pollStatus();
            });
        });

        // Host-Buttons
        const btnStart = document.getElementById('btn-start-game');
        const btnAddBot = document.getElementById('btn-add-bot');

        if (btnStart) btnStart.classList.toggle('hidden', !isHost);
        if (btnAddBot) btnAddBot.classList.toggle('hidden', !isHost || data.players.length >= 8);
    }

    // -------------------------------------------------------------------------
    // ARENA
    // -------------------------------------------------------------------------
    function renderArena(data, myPlayer) {
        const isMyTurn = (data.current_player_id === myPlayerId && data.phase === 'playing');

        // 1. Gegner rendern
        const oppContainer = document.getElementById('opponents-container');
        if (oppContainer) {
            const opponents = data.players.filter(p => p.id !== myPlayerId);
            oppContainer.innerHTML = opponents.map(op => {
                const activeClass = (op.id === data.current_player_id) ? 'is-active' : '';
                const outClass = op.is_out ? 'is-out' : '';
                
                let livesHtml = '';
                if (op.is_out) {
                    livesHtml = '<span style="color: #ef4444; font-size: 0.8rem; font-weight: 800;">Ertrunken ☠️</span>';
                } else if (op.is_swimming) {
                    livesHtml = '<span title="Schwimmer (0 Leben - Schiebeleben!)">🛟 Schwimmt</span>';
                } else {
                    livesHtml = '🪙'.repeat(Math.max(0, op.lives));
                }

                return `
                    <div class="schnauzer-opponent-card ${activeClass} ${outClass}">
                        <div class="opponent-avatar-wrap">${op.is_bot ? '🤖' : (op.is_dealer ? '👑' : '👤')}</div>
                        <div class="opponent-name-text" title="${escapeHtml(op.name)}">${escapeHtml(op.name)}</div>
                        <div class="opponent-lives-bar">${livesHtml}</div>
                        <div class="opponent-cards-fan">
                            ${op.is_out ? '' : '<div class="opponent-mini-back"></div>'.repeat(op.card_count || 3)}
                        </div>
                    </div>
                `;
            }).join('');
        }

        // 2. Tisch-Banner & Klopfen
        const bannerEl = document.getElementById('table-turn-banner');
        const turnTextEl = document.getElementById('turn-indicator-text');
        if (bannerEl && turnTextEl) {
            if (data.knocked_by_player_id) {
                bannerEl.classList.add('knocked-alert');
                const knocker = data.players.find(p => p.id === data.knocked_by_player_id);
                turnTextEl.textContent = `✊ ${knocker ? knocker.name : 'Jemand'} hat geklopft! Noch ${data.turns_after_knock_left} Züge bis zum Showdown!`;
            } else {
                bannerEl.classList.remove('knocked-alert');
                if (isMyTurn) {
                    turnTextEl.textContent = '🌟 DU BIST AM ZUG! Wähle deine Aktion';
                } else {
                    const activeP = data.players.find(p => p.id === data.current_player_id);
                    turnTextEl.textContent = `${activeP ? activeP.name : 'Spieler'} ist am Zug...`;
                }
            }
        }

        // 3. Offene Tischkarten
        const tableCont = document.getElementById('table-cards-container');
        if (tableCont && window.QuarxlCards) {
            const tableCards = data.table_cards || [];
            tableCont.innerHTML = tableCards.map((c, idx) => {
                return window.QuarxlCards.renderCardHtml(c, {
                    selectable: isMyTurn,
                    isSelected: (idx === selectedTableIndex),
                    zIndex: idx + 1,
                });
            }).join('');

            // Klick auf Tischkarte
            tableCont.querySelectorAll('.quarxl-card').forEach((el, idx) => {
                el.addEventListener('click', () => {
                    if (!isMyTurn) return;
                    selectedTableIndex = (selectedTableIndex === idx) ? null : idx;
                    if (window.QuarxlCards.SoundFx) window.QuarxlCards.SoundFx.cardWhoosh();
                    renderArena(data, myPlayer);
                });
            });
        }

        // 4. Eigene Leben & Punkte
        const livesEl = document.getElementById('my-lives-display');
        if (livesEl && myPlayer) {
            if (myPlayer.is_out) {
                livesEl.innerHTML = '<span style="color:#ef4444;font-weight:800;">☠️ Du bist ausgeschieden</span>';
            } else if (myPlayer.is_swimming) {
                livesEl.innerHTML = '<span style="color:#38bdf8;font-weight:800;">🛟 Du schwimmst (Schiebeleben!)</span>';
            } else {
                livesEl.innerHTML = '🪙'.repeat(myPlayer.lives);
            }
        }

        const scoreBadge = document.getElementById('my-score-badge');
        if (scoreBadge && data.my_score) {
            scoreBadge.textContent = data.my_score.label;
            scoreBadge.classList.toggle('is-schnauzer', Boolean(data.my_score.is_31 || data.my_score.is_feuer));
        }

        // 5. Eigene Handkarten
        const handCont = document.getElementById('my-hand-container');
        if (handCont && myPlayer && window.QuarxlCards) {
            const myHand = myPlayer.hand || [];
            handCont.innerHTML = myHand.map((c, idx) => {
                return window.QuarxlCards.renderCardHtml(c, {
                    selectable: isMyTurn,
                    isSelected: (idx === selectedHandIndex),
                    zIndex: idx + 1,
                });
            }).join('');

            // Klick auf Handkarte
            handCont.querySelectorAll('.quarxl-card').forEach((el, idx) => {
                el.addEventListener('click', () => {
                    if (!isMyTurn) return;
                    selectedHandIndex = (selectedHandIndex === idx) ? null : idx;
                    if (window.QuarxlCards.SoundFx) window.QuarxlCards.SoundFx.cardWhoosh();
                    renderArena(data, myPlayer);
                });
            });
        }

        // 6. Buttons aktualisieren
        const btnSwapOne = document.getElementById('btn-swap-one');
        const btnSwapAll = document.getElementById('btn-swap-all');
        const btnPass = document.getElementById('btn-pass');
        const btnKnock = document.getElementById('btn-knock');

        const canSwapOne = (isMyTurn && selectedHandIndex !== null && selectedTableIndex !== null);
        if (btnSwapOne) btnSwapOne.disabled = !canSwapOne;
        if (btnSwapAll) btnSwapAll.disabled = !isMyTurn;
        if (btnPass) btnPass.disabled = !isMyTurn;
        if (btnKnock) {
            const knockAllowed = Boolean(data.can_knock);
            btnKnock.disabled = !isMyTurn || !knockAllowed;
            if (isMyTurn && !data.knocked_by_player_id && !knockAllowed) {
                btnKnock.title = 'In der ersten Runde darf nicht geklopft werden (Umlaufschutz).';
            } else if (Boolean(data.knocked_by_player_id)) {
                btnKnock.title = 'Es wurde bereits geklopft.';
            } else {
                btnKnock.title = '';
            }
        }
    }

    // -------------------------------------------------------------------------
    // GEBER-PREVIEW & SHOWDOWN RENDERING
    // -------------------------------------------------------------------------
    function renderDealerPreview(data, myPlayer) {
        const previewCont = document.getElementById('dealer-preview-hand');
        const scoreText = document.getElementById('dealer-preview-score');
        if (!previewCont || !myPlayer || !window.QuarxlCards) return;

        const myHand = myPlayer.hand || [];
        previewCont.innerHTML = myHand.map(c => window.QuarxlCards.renderCardHtml(c)).join('');

        if (scoreText && data.my_score) {
            scoreText.textContent = data.my_score.label;
        }
    }

    function renderShowdown(data) {
        const titleEl = document.getElementById('showdown-modal-title');
        const summaryEl = document.getElementById('showdown-summary-text');
        const listEl = document.getElementById('showdown-results-list');
        const actionsCont = document.getElementById('showdown-actions-container');
        const res = data.round_result;
        if (!listEl || !res) return;

        const isGameOver = Boolean(res.is_game_over || res.winner_id);

        if (titleEl) {
            titleEl.textContent = isGameOver
                ? `🏆 Finale Runde ${data.round_number} – Auswertung`
                : `Ergebnis Runde ${data.round_number}`;
        }

        if (summaryEl) {
            if (isGameOver) {
                const wName = escapeHtml(res.winner_name || 'Gewinner');
                const loserText = res.loser_names ? `<br><span style="color:#ef4444;font-size:0.85rem;font-weight:600;">Ausgeschieden: ${escapeHtml(res.loser_names)}</span>` : '';
                summaryEl.innerHTML = `🏆 <strong>${wName}</strong> hat das Spiel gewonnen!${loserText}`;
            } else {
                summaryEl.textContent = res.was_instant_schnauzer
                    ? '⚡ Sofortiger Schnauzer! Ein Spieler hatte 31 Punkte.'
                    : `Verliert 1 Leben: ${res.loser_names}`;
            }
        }

        const scores = Object.values(res.scores || {});
        listEl.innerHTML = scores.map(s => {
            const isWinner = isGameOver && (s.player_id === res.winner_id);
            const isLoser = (res.loser_ids || []).includes(s.player_id);
            const handHtml = (s.hand || []).map(c => window.QuarxlCards.renderCardHtml(c)).join('');

            let badgeHtml = '';
            if (isWinner) {
                badgeHtml = ' <span style="color:#f59e0b;font-weight:800;">(🏆 SIEGER)</span>';
            } else if (isLoser) {
                badgeHtml = isGameOver
                    ? ' <span style="color:#ef4444;">(☠️ Ausgeschieden)</span>'
                    : ' <span style="color:#ef4444;">(-1 🪙)</span>';
            }

            return `
                <div class="showdown-player-card ${isLoser ? 'is-loser' : ''} ${isWinner ? 'is-winner' : ''}">
                    <div class="showdown-player-info">
                        <div class="showdown-player-name">
                            ${escapeHtml(s.player_name)}${badgeHtml}
                        </div>
                        <div class="showdown-player-score">${escapeHtml(s.label)}</div>
                    </div>
                    <div class="showdown-player-hand">${handHtml}</div>
                </div>
            `;
        }).join('');

        if (actionsCont) {
            if (isGameOver) {
                actionsCont.innerHTML = `
                    <div style="display: flex; gap: 0.5rem; width: 100%;">
                        <button type="button" class="btn btn-warning btn-lg js-btn-open-ceremony" id="btn-open-ceremony" style="flex: 1; font-weight:800;">
                            🏆 Zur Siegerehrung
                        </button>
                        <button type="button" class="btn btn-secondary btn-lg js-btn-restart-game" style="flex: 1;">
                            🔄 Neues Spiel
                        </button>
                    </div>
                `;
            } else {
                actionsCont.innerHTML = `
                    <button type="button" class="btn btn-primary btn-lg js-btn-next-round" id="btn-next-round" style="width: 100%;">
                        ➡️ Nächste Runde starten
                    </button>
                `;
            }
        }
    }

    // -------------------------------------------------------------------------
    // AKTIONEN & BUTTON-HANDLING
    // -------------------------------------------------------------------------
    function setupActionButtons() {
        // 1 Karte tauschen (mit Flug-Animation)
        const btnSwapOne = document.getElementById('btn-swap-one');
        if (btnSwapOne) {
            btnSwapOne.addEventListener('click', async () => {
                if (selectedHandIndex === null || selectedTableIndex === null) return;
                
                const handCards = document.querySelectorAll('#my-hand-container .quarxl-card');
                const tableCards = document.querySelectorAll('#table-cards-container .quarxl-card');
                const hEl = handCards[selectedHandIndex];
                const tEl = tableCards[selectedTableIndex];

                const hIdx = selectedHandIndex;
                const tIdx = selectedTableIndex;
                selectedHandIndex = null;
                selectedTableIndex = null;

                if (window.QuarxlCards && hEl && tEl) {
                    window.QuarxlCards.animateFlight(hEl, tEl);
                    window.QuarxlCards.animateFlight(tEl, hEl);
                }

                try {
                    await HubHttp.postJson(`./api.php?action=swap_one&room=${encodeURIComponent(roomCode)}`, {
                        hand_index: hIdx,
                        table_index: tIdx,
                    });
                    pollStatus();
                } catch (_) {}
            });
        }

        // Alle 3 tauschen
        const btnSwapAll = document.getElementById('btn-swap-all');
        if (btnSwapAll) {
            btnSwapAll.addEventListener('click', async () => {
                if (window.QuarxlCards?.SoundFx) window.QuarxlCards.SoundFx.shuffle();
                selectedHandIndex = null;
                selectedTableIndex = null;
                try {
                    await HubHttp.postJson(`./api.php?action=swap_all&room=${encodeURIComponent(roomCode)}`);
                    pollStatus();
                } catch (_) {}
            });
        }

        // Schieben
        const btnPass = document.getElementById('btn-pass');
        if (btnPass) {
            btnPass.addEventListener('click', async () => {
                if (window.QuarxlCards?.SoundFx) window.QuarxlCards.SoundFx.cardWhoosh();
                selectedHandIndex = null;
                selectedTableIndex = null;
                try {
                    await HubHttp.postJson(`./api.php?action=pass&room=${encodeURIComponent(roomCode)}`);
                    pollStatus();
                } catch (_) {}
            });
        }

        // Klopfen (Zumachen)
        const btnKnock = document.getElementById('btn-knock');
        if (btnKnock) {
            btnKnock.addEventListener('click', async () => {
                if (window.QuarxlCards?.SoundFx) window.QuarxlCards.SoundFx.knock();
                selectedHandIndex = null;
                selectedTableIndex = null;
                try {
                    await HubHttp.postJson(`./api.php?action=knock&room=${encodeURIComponent(roomCode)}`);
                    pollStatus();
                } catch (_) {}
            });
        }

        // Geber: Behalten
        document.querySelector('.js-btn-dealer-keep')?.addEventListener('click', async () => {
            await HubHttp.postJson(`./api.php?action=dealer_choice&room=${encodeURIComponent(roomCode)}`, { choice: 'keep' });
            document.getElementById('modal-dealer-choice')?.classList.add('hidden');
            pollStatus();
        });

        // Geber: Tauschen
        document.querySelector('.js-btn-dealer-swap')?.addEventListener('click', async () => {
            if (window.QuarxlCards?.SoundFx) window.QuarxlCards.SoundFx.cardWhoosh();
            await HubHttp.postJson(`./api.php?action=dealer_choice&room=${encodeURIComponent(roomCode)}`, { choice: 'swap' });
            document.getElementById('modal-dealer-choice')?.classList.add('hidden');
            pollStatus();
        });

        // Nächste Runde, Siegerehrung, Kartenansicht & Neustart via Event Delegation
        document.addEventListener('click', async (e) => {
            const btnNext = e.target.closest('.js-btn-next-round');
            if (btnNext) {
                await HubHttp.postJson(`./api.php?action=next_round&room=${encodeURIComponent(roomCode)}`);
                document.getElementById('modal-showdown')?.classList.add('hidden');
                pollStatus();
                return;
            }

            const btnCeremony = e.target.closest('.js-btn-open-ceremony');
            if (btnCeremony) {
                document.getElementById('modal-showdown')?.classList.add('hidden');
                document.getElementById('modal-game-over')?.classList.remove('hidden');
                return;
            }

            const btnViewCards = e.target.closest('.js-btn-view-cards');
            if (btnViewCards) {
                document.getElementById('modal-game-over')?.classList.add('hidden');
                document.getElementById('modal-showdown')?.classList.remove('hidden');
                return;
            }

            const btnRestart = e.target.closest('.js-btn-restart-game');
            if (btnRestart) {
                await HubHttp.postJson(`./api.php?action=restart&room=${encodeURIComponent(roomCode)}`);
                document.getElementById('modal-game-over')?.classList.add('hidden');
                document.getElementById('modal-showdown')?.classList.add('hidden');
                pollStatus();
                return;
            }
        });

        // Spiel starten (Lobby)
        document.getElementById('btn-start-game')?.addEventListener('click', async () => {
            await HubHttp.postJson(`./api.php?action=start&room=${encodeURIComponent(roomCode)}`);
            pollStatus();
        });

        // KI-Bot hinzufügen
        document.getElementById('btn-add-bot')?.addEventListener('click', async () => {
            await HubHttp.postJson(`./api.php?action=add_bot&room=${encodeURIComponent(roomCode)}`);
            pollStatus();
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
})();

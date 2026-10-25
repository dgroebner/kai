/**
 * hub.js - Startseiten-Logik für games.agent-smith.de
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        // Service Worker registrieren
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('./sw.js').catch(() => {});
        }

        const nameInput = document.getElementById('hub-player-name');
        const savedName = localStorage.getItem('kai_gh_player_name');
        if (nameInput && savedName) {
            nameInput.value = savedName;
        }

        const modal = document.getElementById('modal-create-room');
        const modalTitle = document.getElementById('create-modal-title');
        const gameTypeInput = document.getElementById('create-game-type');
        const diceGroup = document.getElementById('group-dice-modes');
        const cardGroup = document.getElementById('group-card-modes');
        const loch6Group = document.getElementById('group-loch6-modes');
        const pinInput = document.getElementById('create-room-pin');
        const pinBadge = document.getElementById('pin-saved-badge');

        function updateSavedPinField() {
            const savedPin = localStorage.getItem('kai_gh_host_pin') || '';
            if (pinInput) {
                if (savedPin) {
                    pinInput.value = savedPin;
                    if (pinBadge) pinBadge.classList.remove('hidden');
                } else {
                    if (pinBadge) pinBadge.classList.add('hidden');
                }
            }
        }

        if (pinInput) {
            pinInput.addEventListener('input', () => {
                const savedPin = localStorage.getItem('kai_gh_host_pin') || '';
                if (pinBadge) {
                    if (pinInput.value && pinInput.value === savedPin) {
                        pinBadge.classList.remove('hidden');
                    } else {
                        pinBadge.classList.add('hidden');
                    }
                }
            });
        }

        updateSavedPinField();

        function openCreateModal(gameType, title, showDice, showCards, showLoch6 = false) {
            if (gameTypeInput) gameTypeInput.value = gameType;
            if (modalTitle) modalTitle.textContent = title;
            if (diceGroup) diceGroup.classList.toggle('hidden', !showDice);
            if (cardGroup) cardGroup.classList.toggle('hidden', !showCards);
            if (loch6Group) loch6Group.classList.toggle('hidden', !showLoch6);
            updateSavedPinField();
            modal.classList.remove('hidden');
            if (pinInput && !pinInput.value) {
                pinInput.focus();
            }
        }

        // Kachel-Klick auf Qwixx
        const qwixxCard = document.getElementById('card-qwixx');
        if (qwixxCard && modal) {
            qwixxCard.addEventListener('click', () => {
                openCreateModal('qwixx', 'Neues Qwixx-Spiel erstellen', true, false, false);
            });
        }

        // Kachel-Klick auf Kniffel
        const kniffelCard = document.getElementById('card-kniffel');
        if (kniffelCard && modal) {
            kniffelCard.addEventListener('click', () => {
                openCreateModal('kniffel', 'Neues Kniffel-Spiel erstellen', true, false, false);
            });
        }

        // Kachel-Klick auf Mau-Mau / Uno
        const cardsCard = document.getElementById('card-cards');
        if (cardsCard && modal) {
            cardsCard.addEventListener('click', () => {
                openCreateModal('cards', 'Neues Mau-Mau / Uno Spiel erstellen', false, true, false);
            });
        }

        // Kachel-Klick auf Shut the Box
        const stbCard = document.getElementById('card-shut-the-box');
        if (stbCard && modal) {
            stbCard.addEventListener('click', () => {
                openCreateModal('shut_the_box', 'Neues Shut the Box Spiel erstellen', false, false, false);
            });
        }

        // Kachel-Klick auf Loch 6
        const loch6Card = document.getElementById('card-loch6');
        if (loch6Card && modal) {
            loch6Card.addEventListener('click', () => {
                openCreateModal('loch6', 'Neues Loch 6 Spiel erstellen', false, false, true);
            });
        }

        // Modal schließen
        document.querySelectorAll('.js-close-modal').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (modal) modal.classList.add('hidden');
            });
        });

        // Raum erstellen
        const formCreate = document.getElementById('form-create-room');
        if (formCreate) {
            formCreate.addEventListener('submit', async (e) => {
                e.preventDefault();
                const gameType = gameTypeInput?.value || 'qwixx';
                const pin = document.getElementById('create-room-pin')?.value.trim();
                const name = nameInput?.value.trim() || 'Host';
                let mode = 'digital_dice';
                const payload = {
                    pin: pin,
                    player_name: name
                };

                if (gameType === 'cards') {
                    mode = document.querySelector('input[name="card_game_mode"]:checked')?.value || 'uno';
                    payload.game_mode = mode;
                } else if (gameType === 'shut_the_box') {
                    mode = '4_player';
                    payload.game_mode = mode;
                } else if (gameType === 'loch6') {
                    const stones = parseInt(document.querySelector('input[name="loch6_stones"]:checked')?.value || '6', 10);
                    payload.stones = stones;
                    payload.game_mode = `${stones}_stones`;
                } else {
                    mode = document.querySelector('input[name="game_mode"]:checked')?.value || 'digital_dice';
                    payload.game_mode = mode;
                }

                const errBox = document.getElementById('create-room-error');

                if (errBox) errBox.classList.add('hidden');

                localStorage.setItem('kai_gh_player_name', name);

                const res = await HubHttp.postJson(`./${gameType}/api.php?action=create`, payload);

                if (res.ok && res.data && res.data.success) {
                    if (pin) {
                        localStorage.setItem('kai_gh_host_pin', pin);
                    }
                    if (res.data.player_token) {
                        localStorage.setItem('kai_gh_player_token', res.data.player_token);
                    }
                    window.location.href = `./${gameType}/index.php?room=${encodeURIComponent(res.data.room_code)}`;
                } else {
                    if (res.status === 403) {
                        localStorage.removeItem('kai_gh_host_pin');
                        updateSavedPinField();
                    }
                    if (errBox) {
                        errBox.textContent = res.data?.message || 'Fehler beim Erstellen des Raums.';
                        errBox.classList.remove('hidden');
                    }
                }
            });
        }

        // Raum beitreten per Code
        const formJoin = document.getElementById('form-join-room');
        if (formJoin) {
            formJoin.addEventListener('submit', async (e) => {
                e.preventDefault();
                const code = document.getElementById('join-room-code')?.value.trim().toUpperCase();
                const name = nameInput?.value.trim() || 'Gast';

                if (!code || code.length < 4) return;
                localStorage.setItem('kai_gh_player_name', name);

                // Spieltyp des Raumes über API ermitteln
                const res = await HubHttp.getJson(`./api.php?action=lookup&code=${encodeURIComponent(code)}`);
                if (res.ok && res.data && res.data.success) {
                    const gameType = res.data.game_type || 'qwixx';
                    window.location.href = `./${gameType}/index.php?room=${encodeURIComponent(code)}`;
                } else {
                    await HubDialog.alert('Raum nicht gefunden', 'Der eingegebene Raum-Code existiert nicht. Bitte prüfe deine Eingabe.', '🔍');
                }
            });
        }
    });
})();

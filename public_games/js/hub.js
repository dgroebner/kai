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

        // Kachel-Klick auf Qwixx
        const qwixxCard = document.getElementById('card-qwixx');
        if (qwixxCard && modal) {
            qwixxCard.addEventListener('click', () => {
                if (gameTypeInput) gameTypeInput.value = 'qwixx';
                if (modalTitle) modalTitle.textContent = 'Neues Qwixx-Spiel erstellen';
                if (diceGroup) diceGroup.classList.remove('hidden');
                if (cardGroup) cardGroup.classList.add('hidden');
                modal.classList.remove('hidden');
                const pinInput = document.getElementById('create-room-pin');
                if (pinInput) pinInput.focus();
            });
        }

        // Kachel-Klick auf Kniffel
        const kniffelCard = document.getElementById('card-kniffel');
        if (kniffelCard && modal) {
            kniffelCard.addEventListener('click', () => {
                if (gameTypeInput) gameTypeInput.value = 'kniffel';
                if (modalTitle) modalTitle.textContent = 'Neues Kniffel-Spiel erstellen';
                if (diceGroup) diceGroup.classList.remove('hidden');
                if (cardGroup) cardGroup.classList.add('hidden');
                modal.classList.remove('hidden');
                const pinInput = document.getElementById('create-room-pin');
                if (pinInput) pinInput.focus();
            });
        }

        // Kachel-Klick auf Kartenspiele
        const cardsCard = document.getElementById('card-cards');
        if (cardsCard && modal) {
            cardsCard.addEventListener('click', () => {
                if (gameTypeInput) gameTypeInput.value = 'cards';
                if (modalTitle) modalTitle.textContent = 'Neues Kartenspiel erstellen';
                if (diceGroup) diceGroup.classList.add('hidden');
                if (cardGroup) cardGroup.classList.remove('hidden');
                modal.classList.remove('hidden');
                const pinInput = document.getElementById('create-room-pin');
                if (pinInput) pinInput.focus();
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

                if (gameType === 'cards') {
                    mode = document.querySelector('input[name="card_game_mode"]:checked')?.value || 'uno';
                } else {
                    mode = document.querySelector('input[name="game_mode"]:checked')?.value || 'digital_dice';
                }

                const errBox = document.getElementById('create-room-error');

                if (errBox) errBox.classList.add('hidden');

                localStorage.setItem('kai_gh_player_name', name);

                const res = await HubHttp.postJson(`./${gameType}/api.php?action=create`, {
                    pin: pin,
                    player_name: name,
                    game_mode: mode
                });

                if (res.ok && res.data && res.data.success) {
                    if (res.data.player_token) {
                        localStorage.setItem('kai_gh_player_token', res.data.player_token);
                    }
                    window.location.href = `./${gameType}/index.php?room=${encodeURIComponent(res.data.room_code)}`;
                } else {
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

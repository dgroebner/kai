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

        // Kachel-Klick auf Qwixx
        const qwixxCard = document.getElementById('card-qwixx');
        const modal = document.getElementById('modal-qwixx-create');
        if (qwixxCard && modal) {
            qwixxCard.addEventListener('click', () => {
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
                const pin = document.getElementById('create-room-pin')?.value.trim();
                const name = nameInput?.value.trim() || 'Host';
                const mode = document.querySelector('input[name="game_mode"]:checked')?.value || 'digital_dice';
                const errBox = document.getElementById('create-room-error');

                if (errBox) errBox.classList.add('hidden');

                localStorage.setItem('kai_gh_player_name', name);

                const res = await HubHttp.postJson('./qwixx/api.php?action=create', {
                    pin: pin,
                    player_name: name,
                    game_mode: mode
                });

                if (res.ok && res.data && res.data.success) {
                    if (res.data.player_token) {
                        localStorage.setItem('kai_gh_player_token', res.data.player_token);
                    }
                    window.location.href = `./qwixx/index.php?room=${encodeURIComponent(res.data.room_code)}`;
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
            formJoin.addEventListener('submit', (e) => {
                e.preventDefault();
                const code = document.getElementById('join-room-code')?.value.trim().toUpperCase();
                const name = nameInput?.value.trim() || 'Gast';

                if (!code || code.length < 4) return;
                localStorage.setItem('kai_gh_player_name', name);
                window.location.href = `./qwixx/index.php?room=${encodeURIComponent(code)}`;
            });
        }
    });
})();

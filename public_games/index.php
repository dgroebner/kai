<?php
require_once __DIR__ . '/../bootstrap.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kai Games Hub - Spiele &amp; Würfel</title>
    <link rel="manifest" href="./manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="./android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="./apple-touch-icon.png">
    <meta name="theme-color" content="#0f172a">
    <link rel="stylesheet" href="./css/games-hub.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body">
    <div class="hub-container">
        <header class="hub-header">
            <h1>🎲 Kai Games Hub</h1>
            <p class="hub-subtitle">Gesellschaftsspiele online oder als digitaler Spielblock am Tisch</p>
        </header>

        <!-- Dein Spielername & Schnellbeitritt -->
        <div class="card-box" style="margin-bottom: 1.75rem;">
            <div class="form-group" style="margin-bottom: 1rem;">
                <label for="hub-player-name">Dein Spielername:</label>
                <input type="text" id="hub-player-name" class="form-control" placeholder="z. B. Danny, Lisa..." maxlength="25">
            </div>

            <div style="border-top: 1px solid rgba(255, 255, 255, 0.08); padding-top: 1rem;">
                <h3 style="font-size: 1.05rem; margin-bottom: 0.35rem;">🚀 Spielraum beitreten</h3>
                <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 0.75rem;">
                    Gib den 6-stelligen Code ein (z. B. aus dem WhatsApp-Chat):
                </p>
                <form id="form-join-room">
                    <div class="hub-join-bar">
                        <input type="text" id="join-room-code" class="form-control room-code-input" placeholder="CODE" maxlength="6" autocomplete="off" spellcheck="false">
                        <button type="submit" class="btn btn-primary">Beitreten</button>
                    </div>
                </form>
            </div>
        </div>

        <h2 style="font-size: 1.15rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem;">
            Neues Spiel starten (Host)
        </h2>

        <!-- Spielauswahl Kacheln -->
        <div class="games-kachel-grid">
            <!-- Qwixx Kachel -->
            <div class="game-kachel" id="card-qwixx" role="button" tabindex="0">
                <div class="game-kachel-icon">🎲</div>
                <div class="game-kachel-content">
                    <h2>Qwixx</h2>
                    <p>Der rasante Würfelklassiker. Spiele mit echten Würfeln am Tisch oder live mit digitalen Würfeln.</p>
                </div>
                <span class="game-kachel-badge">2–6 Spieler</span>
            </div>

            <!-- Vorbereitung: Kniffel Kachel (Coming Soon) -->
            <div class="game-kachel" style="opacity: 0.65; cursor: default;">
                <div class="game-kachel-icon">🏆</div>
                <div class="game-kachel-content">
                    <h2>Kniffel</h2>
                    <p>Große &amp; kleine Straße, Full House und Kniffel. Digitaler Punktezettel.</p>
                </div>
                <span class="game-kachel-badge" style="background: rgba(148, 163, 184, 0.15); color: var(--text-muted);">Demnächst</span>
            </div>
        </div>

        <footer style="text-align: center; margin-top: 2rem; color: var(--text-muted); font-size: 0.8rem;">
            games.agent-smith.de &bull; v<?= APP_VERSION ?>
        </footer>
    </div>

    <!-- Modal: Qwixx Raum erstellen -->
    <div id="modal-qwixx-create" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal">
            <div class="hub-modal-header">
                <h3 class="hub-modal-title">Neues Qwixx-Spiel erstellen</h3>
                <button type="button" class="hub-modal-close js-close-modal">&times;</button>
            </div>
            <form id="form-create-room">
                <div class="form-group">
                    <label>Spielmodus:</label>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal; cursor: pointer;">
                            <input type="radio" name="game_mode" value="digital_dice" checked>
                            <span><strong>Integrierte Würfel:</strong> Gewürfelt wird live in der App</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal; cursor: pointer;">
                            <input type="radio" name="game_mode" value="sheet_only">
                            <span><strong>Digitaler Spielblock:</strong> Für echte Würfel am Tisch</span>
                        </label>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 1rem;">
                    <label for="create-room-pin">Tisch-PIN (Host-Schutz):</label>
                    <input type="password" id="create-room-pin" class="form-control" placeholder="PIN aus der Konfiguration" required autocomplete="current-password">
                    <small class="text-muted">Gäste, die per WhatsApp-Link beitreten, benötigen keinen PIN.</small>
                </div>

                <div id="create-room-error" class="hidden" style="color: var(--color-red); font-size: 0.88rem; margin-top: 0.5rem;"></div>

                <div class="hub-modal-actions">
                    <button type="button" class="btn btn-outline js-close-modal" style="flex: 1;">Abbrechen</button>
                    <button type="submit" class="btn btn-primary" style="flex: 2;">Raum eröffnen</button>
                </div>
            </form>
        </div>
    </div>

    <script src="./js/http-hub.js?v=<?= APP_VERSION ?>"></script>
    <script src="./js/hub.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

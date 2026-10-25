<?php
require_once __DIR__ . '/../../bootstrap.php';
$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Mau-Mau / Uno - Quarxl</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="../android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
    <meta name="theme-color" content="#0f172a">
    <link rel="stylesheet" href="../css/games-hub.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="./cards.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body cards-viewport" data-room="<?= $roomCode ?>">

    <!-- TOP-BAR -->
    <header class="cards-header">
        <div class="cards-header-title">
            <a href="../index.php" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none;">&larr; Hub</a>
            <span class="cards-room-badge" id="room-code-display">RAUM: <?= $roomCode ?: '...' ?></span>
            <span id="game-mode-badge" style="font-size: 0.8rem; color: #94a3b8; font-weight: normal;"></span>
        </div>
        <div style="display: flex; gap: 0.4rem; align-items: center;">
            <button type="button" class="btn btn-sm btn-outline js-btn-edit-name" title="Spielername ändern" style="display: flex; align-items: center; gap: 0.25rem;">
                ✏️ <span class="js-my-name-display" style="max-width: 90px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Ich</span>
            </button>
            <button type="button" class="btn btn-sm btn-whatsapp js-share-whatsapp" title="Per WhatsApp einladen">
                📲 Einladen
            </button>
        </div>
    </header>

    <!-- BEITRITT-OVERLAY -->
    <div id="join-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center">
            <div style="font-size: 2.75rem; margin-bottom: 0.5rem;">🃏</div>
            <h3 class="hub-modal-title">Mau-Mau / Uno beitreten</h3>
            <p class="text-muted" style="font-size: 0.88rem; margin-bottom: 1rem;">
                Raum: <strong><?= $roomCode ?></strong>
            </p>
            <div class="form-group">
                <label for="join-player-name">Dein Spielername:</label>
                <input type="text" id="join-player-name" class="form-control text-center" placeholder="z. B. Danny" maxlength="25">
            </div>
            <div id="join-error" class="hidden" style="color: var(--color-red); font-size: 0.85rem; margin-bottom: 0.5rem;"></div>
            <button type="button" class="btn btn-primary btn-lg js-btn-confirm-join" style="width: 100%;">Am Tisch Platz nehmen</button>
        </div>
    </div>

    <!-- FARBWAHL-MODAL -->
    <div id="modal-color-choice" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center" style="max-width: 360px;">
            <div id="color-choice-modal-icon" style="font-size: 2.3rem; margin-bottom: 0.25rem;">🌈</div>
            <h3 class="hub-modal-title">Farbe wünschen</h3>
            <p class="text-muted" style="font-size: 0.85rem;">Wähle die nächste Spielfarbe:</p>
            <div class="color-choice-grid" id="color-choice-container">
                <!-- Dynamisch befüllt für Uno oder Mau-Mau -->
            </div>
        </div>
    </div>

    <!-- UNO/MAU 2-SEKUNDEN COUNTDOWN PROMPT -->
    <div id="modal-uno-countdown" class="hub-modal-overlay hidden" role="dialog" aria-modal="true" style="z-index: 100;">
        <div class="hub-modal text-center" style="max-width: 330px;">
            <div style="font-size: 3rem; margin-bottom: 0.25rem;">📢</div>
            <h3 class="hub-modal-title" id="uno-countdown-title">Vorletzte Karte!</h3>
            <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 1.25rem;">
                Tippe schnell, um deine letzte Karte anzusagen:
            </p>
            <button type="button" class="btn btn-primary btn-lg js-btn-trigger-uno-countdown" id="btn-trigger-uno-countdown" style="width: 100%; font-size: 1.25rem; font-weight: 900; padding: 0.85rem; background: linear-gradient(135deg, #ef4444, #dc2626); box-shadow: 0 0 20px rgba(239, 68, 68, 0.7); animation: bounce 0.5s infinite;">
                MAU! RUFEN
            </button>
            <div class="uno-timer-bar-wrap" style="height: 6px; background: rgba(255, 255, 255, 0.1); border-radius: 3px; margin-top: 1.25rem; overflow: hidden;">
                <div id="uno-timer-bar" style="height: 100%; width: 100%; background: #ef4444;"></div>
            </div>
        </div>
    </div>

    <!-- 1. LOBBY-ANSICHT -->
    <div id="pane-lobby" class="cards-lobby-box hidden">
        <h2 style="font-size: 1.4rem; margin-bottom: 0.25rem;" id="lobby-title">Mau-Mau / Uno Wartezimmer</h2>
        <p class="text-muted" style="font-size: 0.85rem;">Teile den Code oder lade Mitspieler per WhatsApp ein.</p>
        
        <div class="cards-lobby-players" id="lobby-players-list">
            <!-- Spielerliste -->
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
            <button type="button" class="btn btn-outline js-btn-add-bot hidden" id="btn-add-bot">
                🤖 KI-Bot hinzufügen
            </button>
            <button type="button" class="btn btn-primary btn-lg js-btn-start-game hidden" id="btn-start-game">
                🚀 Spiel starten
            </button>
            <p id="lobby-waiting-notice" class="text-muted" style="font-size: 0.85rem; margin: 0.5rem 0;">
                Warte darauf, dass der Host das Spiel startet...
            </p>
        </div>
    </div>

    <!-- 2. SPIELTISCH-ARENA -->
    <main id="pane-game" class="cards-table-arena hidden">
        <!-- Gegner-Leiste (Oben) -->
        <div class="opponents-bar" id="opponents-bar">
            <!-- Gegner-Karten -->
        </div>

        <!-- Event-Ticker Banner -->
        <div class="cards-event-ticker" id="event-ticker-text">
            Spiel läuft...
        </div>

        <!-- Tischzentrum -->
        <div class="table-center-zone">
            <div class="center-piles-wrapper">
                <!-- Nachziehstapel ODER Uno Extreme Launcher -->
                <div id="draw-pile-container">
                    <!-- Wird dynamisch durch JS gerendert -->
                </div>

                <!-- Ablagestapel (Oberste gespielte Karte) -->
                <div id="discard-pile-container">
                    <!-- Oberste Karte -->
                </div>
            </div>

            <!-- Wunschfarben-Indikator -->
            <div id="active-color-indicator" class="hidden"></div>
        </div>

        <!-- Spieler-Zone (Daumenzone unten) -->
        <div class="player-hand-container">
            <div class="player-action-controls">
                <div style="font-size: 0.85rem; font-weight: 700; color: #94a3b8;" id="my-turn-status">
                    <!-- Dein Zug / Warte auf Spieler X -->
                </div>
                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <button type="button" class="btn-uno-shout js-btn-shout-uno" id="btn-uno-shout">
                        UNO!
                    </button>
                </div>
            </div>

            <!-- Fächer der Handkarten -->
            <div class="player-hand-fanned" id="player-hand-fanned">
                <!-- Eigene Handkarten -->
            </div>
        </div>
    </main>

    <!-- 3. GEWINNER-ANSICHT -->
    <div id="pane-finished" class="cards-lobby-box text-center hidden">
        <div style="font-size: 4rem; margin-bottom: 0.5rem;">🏆</div>
        <h2 style="font-size: 1.6rem; color: #fbbf24; margin-bottom: 0.5rem;" id="winner-name-display">Gewonnen!</h2>
        <p class="text-muted" style="margin-bottom: 1.5rem;" id="winner-desc">Alle Karten wurden erfolgreich abgelegt.</p>
        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
            <a href="../index.php" class="btn btn-outline" style="flex: 1; text-align: center; text-decoration: none;">Zur Lobby</a>
            <button type="button" class="btn btn-primary js-btn-rematch hidden" id="btn-rematch" style="flex: 1;">🔄 Revanche</button>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../js/http-hub.js?v=<?= APP_VERSION ?>"></script>
    <script src="./cards.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>

<?php
require_once __DIR__ . '/../../bootstrap.php';
$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Loch 6 - Quarxl</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="../android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
    <meta name="theme-color" content="#1e293b">
    <link rel="stylesheet" href="../css/games-hub.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="./loch6.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body loch6-viewport" data-room="<?= $roomCode ?>">

    <!-- TOP-BAR -->
    <header class="loch6-header">
        <div class="loch6-header-title">
            <a href="../index.php" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none;">&larr; Hub</a>
            <span class="loch6-room-badge" id="room-code-display">RAUM: <?= $roomCode ?: '...' ?></span>
            <span id="round-indicator-badge" class="loch6-round-pill">Runde 1 (Pflichtwurf)</span>
        </div>
        <div style="display: flex; gap: 0.4rem; align-items: center;">
            <button type="button" class="btn btn-sm btn-outline js-btn-edit-name" title="Spielername ändern" style="display: flex; align-items: center; gap: 0.25rem;">
                ✏️ <span class="js-my-name-display" style="max-width: 80px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Ich</span>
            </button>
            <button type="button" class="btn btn-sm btn-whatsapp js-share-whatsapp" title="Per WhatsApp einladen">
                📲 Einladen
            </button>
        </div>
    </header>

    <!-- BEITRITT-OVERLAY -->
    <div id="join-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center">
            <div style="font-size: 2.75rem; margin-bottom: 0.5rem;">🕳️</div>
            <h3 class="hub-modal-title">Loch 6 beitreten</h3>
            <p class="text-muted" style="font-size: 0.88rem; margin-bottom: 1rem;">
                Raum: <strong><?= $roomCode ?></strong> (Push-Your-Luck)
            </p>
            <div class="form-group">
                <label for="join-player-name">Dein Spielername:</label>
                <input type="text" id="join-player-name" class="form-control text-center" placeholder="z. B. Danny" maxlength="25">
            </div>
            <div id="join-error" class="hidden" style="color: var(--color-red); font-size: 0.85rem; margin-bottom: 0.5rem;"></div>
            <button type="button" class="btn btn-primary btn-lg js-btn-confirm-join" style="width: 100%;">An den Spieltisch setzen</button>
        </div>
    </div>

    <!-- 1. LOBBY-ANSICHT -->
    <div id="pane-lobby" class="loch6-lobby-box hidden">
        <div style="font-size: 3rem; margin-bottom: 0.5rem;">🕳️💎</div>
        <h2 style="font-size: 1.4rem; margin-bottom: 0.25rem;">Loch 6 – Der Schlund</h2>
        <p class="text-muted" style="font-size: 0.85rem;">Push-Your-Luck Würfelspiel. Wer zuerst alle Mineral-Steine ablegt, gewinnt!</p>
        
        <div class="loch6-lobby-players" id="lobby-players-list">
            <!-- Spielerliste -->
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 1.25rem;">
            <button type="button" class="btn btn-outline js-btn-add-bot hidden" id="btn-add-bot">
                🤖 KI-Bot hinzufügen (+1)
            </button>
            <button type="button" class="btn btn-primary btn-lg js-btn-start-game hidden" id="btn-start-game">
                🚀 Spiel starten
            </button>
            <div id="lobby-waiting-notice" class="text-muted hidden" style="font-size: 0.9rem; padding: 0.5rem;">
                Warte auf den Host zum Starten...
            </div>
        </div>
    </div>

    <!-- 2. SPIELARENA -->
    <main id="pane-game" class="loch6-game-arena hidden">
        <!-- Event-Ticker Banner -->
        <div class="loch6-event-ticker" id="event-ticker-text">
            Spiel gestartet...
        </div>

        <!-- Mitspieler-Kacheln (Oben) -->
        <div class="loch6-opponents-bar" id="opponents-bar">
            <!-- Dynamisch generiert -->
        </div>

        <!-- Zentrales Holzbrett mit 15 Mulden (1 bis 5) + Mittenloch 6 -->
        <div class="loch6-board-container">
            <div class="loch6-wood-board" id="loch6-board">
                <!-- Sektor 4 (Oben-Links: 4 Mulden im 2x2 Raster) -->
                <span class="sector-label sector-lbl-4">4</span>
                <div class="loch6-pit pit-pos-4-0"><div class="pit-crystal-slot" id="slot-4-0"></div></div>
                <div class="loch6-pit pit-pos-4-1"><div class="pit-crystal-slot" id="slot-4-1"></div></div>
                <div class="loch6-pit pit-pos-4-2"><div class="pit-crystal-slot" id="slot-4-2"></div></div>
                <div class="loch6-pit pit-pos-4-3"><div class="pit-crystal-slot" id="slot-4-3"></div></div>

                <!-- Sektor 3 (Oben-Rechts: 3 Mulden in diagonaler Reihe) -->
                <span class="sector-label sector-lbl-3">3</span>
                <div class="loch6-pit pit-pos-3-0"><div class="pit-crystal-slot" id="slot-3-0"></div></div>
                <div class="loch6-pit pit-pos-3-1"><div class="pit-crystal-slot" id="slot-3-1"></div></div>
                <div class="loch6-pit pit-pos-3-2"><div class="pit-crystal-slot" id="slot-3-2"></div></div>

                <!-- Sektor 5 (Unten-Links: 5 Mulden wie die 5 auf dem Würfel) -->
                <span class="sector-label sector-lbl-5">5</span>
                <div class="loch6-pit pit-pos-5-0"><div class="pit-crystal-slot" id="slot-5-0"></div></div>
                <div class="loch6-pit pit-pos-5-1"><div class="pit-crystal-slot" id="slot-5-1"></div></div>
                <div class="loch6-pit pit-pos-5-2"><div class="pit-crystal-slot" id="slot-5-2"></div></div>
                <div class="loch6-pit pit-pos-5-3"><div class="pit-crystal-slot" id="slot-5-3"></div></div>
                <div class="loch6-pit pit-pos-5-4"><div class="pit-crystal-slot" id="slot-5-4"></div></div>

                <!-- Sektor 1 (Unten-Rechts innen: 1 Mulde) -->
                <span class="sector-label sector-lbl-1">1</span>
                <div class="loch6-pit pit-pos-1-0"><div class="pit-crystal-slot" id="slot-1-0"></div></div>

                <!-- Sektor 2 (Unten-Rechts außen: 2 Mulden in Linie) -->
                <span class="sector-label sector-lbl-2">2</span>
                <div class="loch6-pit pit-pos-2-0"><div class="pit-crystal-slot" id="slot-2-0"></div></div>
                <div class="loch6-pit pit-pos-2-1"><div class="pit-crystal-slot" id="slot-2-1"></div></div>

                <!-- Der Schlund / Loch 6 im Zentrum -->
                <div class="loch6-abyss" id="pit-6">
                    <div class="abyss-depth">
                        <span class="abyss-number">6</span>
                        <div class="abyss-counter-badge" id="abyss-counter" title="Im Schlund versenkt">0 💎</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Würfel-Bühne (Quarxl-Standard) -->
        <div class="loch6-dice-stage">
            <div class="qwixx-dice-row dice-wrapper" id="dice-container">
                <div class="qwixx-die die-white" id="die-1" data-val="1">
                    <span class="die-pip pip-center"></span>
                </div>
            </div>

            <div class="dice-status-line" id="dice-status-line">
                Augen: <strong>-</strong>
            </div>

            <!-- Aktions-Buttons: Würfeln oder Sichern -->
            <div class="loch6-action-buttons">
                <button type="button" class="btn-loch6-roll js-btn-roll" id="btn-roll-dice">
                    🎲 WÜRFELN
                </button>
                <button type="button" class="btn-loch6-pass js-btn-pass hidden" id="btn-pass-turn">
                    🛑 Sichern &amp; Weitergeben
                </button>
            </div>

            <div class="turn-prompt-text" id="turn-prompt-text">
                Warte auf Spielzug...
            </div>
        </div>

        <!-- Eigene Mineralien-Ablageschale (Unten im Daumenbereich) -->
        <div class="loch6-player-tray">
            <div class="tray-header">
                <div class="tray-title">
                    <span>💎 Mein Mineralien-Vorrat</span>
                    <span class="tray-count-badge" id="my-stones-count-badge">6 Steine</span>
                </div>
                <div class="tray-stats" id="my-tray-stats">
                    <!-- Z. B. Kleben geblieben: 0 -->
                </div>
            </div>

            <!-- Dynamische Anzeige aller Mineralien im eigenen Vorrat -->
            <div class="tray-crystals-grid" id="my-crystals-grid">
                <!-- Dynamisch generierte Kristalle -->
            </div>
        </div>
    </main>

    <!-- 3. GEWINNER-ANSICHT -->
    <div id="pane-finished" class="loch6-lobby-box text-center hidden">
        <div style="font-size: 4rem; margin-bottom: 0.5rem;">🏆</div>
        <h2 style="font-size: 1.6rem; color: #fbbf24; margin-bottom: 0.5rem;" id="winner-name-display">Gewonnen!</h2>
        <p class="text-muted" style="margin-bottom: 1.25rem;" id="winner-desc">Alle Mineral-Steine erfolgreich abgebaut!</p>

        <div class="loch6-finished-stats" id="finished-stats-list" style="margin-bottom: 1.5rem;">
            <!-- Rangliste / Statistik -->
        </div>

        <div style="display: flex; gap: 0.5rem; justify-content: center;">
            <a href="../index.php" class="btn btn-outline" style="flex: 1; text-decoration: none; text-align: center;">
                Zur Lobby
            </a>
            <button type="button" class="btn btn-primary js-btn-rematch hidden" id="btn-rematch" style="flex: 1;">
                🔄 Revanche
            </button>
        </div>
    </div>

    <script src="../js/http-hub.js?v=<?= APP_VERSION ?>"></script>
    <script src="./loch6.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>

<?php
require_once __DIR__ . '/../../bootstrap.php';
$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Shut the Box - Quarxl</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="../android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
    <meta name="theme-color" content="#1e293b">
    <link rel="stylesheet" href="../css/games-hub.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="./shut_the_box.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body stb-viewport" data-room="<?= $roomCode ?>">

    <!-- TOP-BAR -->
    <header class="stb-header">
        <div class="stb-header-title">
            <a href="../index.php" style="color: var(--text-muted); font-size: 0.85rem; text-decoration: none;">&larr; Hub</a>
            <span class="stb-room-badge" id="room-code-display">RAUM: <?= $roomCode ?: '...' ?></span>
            <span id="round-indicator-badge" class="stb-round-pill">Runde 1/10</span>
        </div>
        <div style="display: flex; gap: 0.4rem; align-items: center;">
            <button type="button" class="btn btn-sm btn-outline js-btn-scorecard" title="Spielblatt öffnen" id="btn-toggle-scorecard">
                📋 Zettel
            </button>
            <button type="button" class="btn btn-sm btn-outline js-btn-edit-name" title="Spielername ändern" style="display: flex; align-items: center; gap: 0.25rem;">
                ✏️ <span class="js-my-name-display" style="max-width: 80px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Ich</span>
            </button>
            <button type="button" class="btn btn-sm btn-whatsapp js-share-whatsapp" title="Per WhatsApp einladen">
                📲 Einladen
            </button>
            <button type="button" class="btn btn-sm btn-outline js-btn-request-abort hidden" id="btn-request-abort" title="Spiel vorzeitig beenden & auswerten">
                ⏹️ Ende
            </button>
        </div>
    </header>

    <!-- BEITRITT-OVERLAY -->
    <div id="join-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center">
            <div style="font-size: 2.75rem; margin-bottom: 0.5rem;">📦</div>
            <h3 class="hub-modal-title">Shut the Box beitreten</h3>
            <p class="text-muted" style="font-size: 0.88rem; margin-bottom: 1rem;">
                Raum: <strong><?= $roomCode ?></strong> (4-Spieler Klappbrett)
            </p>
            <div class="form-group">
                <label for="join-player-name">Dein Spielername:</label>
                <input type="text" id="join-player-name" class="form-control text-center" placeholder="z. B. Danny" maxlength="25">
            </div>
            <div id="join-error" class="hidden" style="color: var(--color-red); font-size: 0.85rem; margin-bottom: 0.5rem;"></div>
            <button type="button" class="btn btn-primary btn-lg js-btn-confirm-join" style="width: 100%;">Am Klappbrett Platz nehmen</button>
        </div>
    </div>

    <!-- ABSTIMMUNG VORZEITIGER ABBRUCH -->
    <div id="modal-abort-vote" class="hub-modal-overlay hidden" role="dialog" aria-modal="true" style="z-index: 120;">
        <div class="hub-modal text-center" style="max-width: 380px;">
            <div style="font-size: 2.8rem; margin-bottom: 0.25rem;">⚠️</div>
            <h3 class="hub-modal-title" id="abort-vote-title">Spiel vorzeitig beenden?</h3>
            <p class="text-muted" style="font-size: 0.9rem; margin-bottom: 1.25rem;" id="abort-vote-desc">
                Ein Mitspieler schlägt vor, das Spiel jetzt zu beenden und nach den bisherigen Runden auszuwerten.
            </p>
            <div style="display: flex; gap: 0.75rem;">
                <button type="button" class="btn btn-outline btn-lg" id="btn-vote-abort-no" style="flex: 1;">Weiterspielen</button>
                <button type="button" class="btn btn-primary btn-lg" id="btn-vote-abort-yes" style="flex: 1; background: #ef4444; border-color: #f87171;">Zustimmen</button>
            </div>
        </div>
    </div>

    <!-- RUNDEN-ZUSAMMENFASSUNG (ÜBERGANG ZWISCHEN DEN RUNDEN) -->
    <div id="modal-round-summary" class="hub-modal-overlay hidden" role="dialog" aria-modal="true" style="z-index: 115;">
        <div class="hub-modal text-center" style="max-width: 420px; width: 95%;">
            <div style="font-size: 2.8rem; margin-bottom: 0.25rem;">🔔</div>
            <h3 class="hub-modal-title" id="round-summary-title">Runde beendet!</h3>
            <p class="text-muted" style="font-size: 0.85rem; margin-bottom: 1rem;" id="round-summary-subtitle">
                Ergebnisse der vergangenen Spielrunde:
            </p>
            <div class="round-summary-list" id="round-summary-scores" style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.25rem; text-align: left;">
                <!-- Dynamisch befüllt -->
            </div>
            <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center;">
                <button type="button" class="btn btn-primary btn-lg js-close-round-summary" id="btn-next-round-continue" style="flex: 1;">
                    Weiter &rarr;
                </button>
            </div>
        </div>
    </div>

    <!-- SPIELZETTEL-MODAL (10 RUNDEN SCORECARD) -->
    <div id="modal-scorecard" class="hub-modal-overlay hidden" role="dialog" aria-modal="true" style="z-index: 110;">
        <div class="hub-modal" style="max-width: 520px; width: 95%;">
            <div class="hub-modal-header" style="margin-bottom: 0.75rem;">
                <h3 class="hub-modal-title">📋 Spielzettel (10 Runden)</h3>
                <button type="button" class="hub-modal-close js-close-scorecard">&times;</button>
            </div>
            <p class="text-muted" style="font-size: 0.8rem; margin-bottom: 0.75rem;">
                Ziel: Geringste Gesamtzahl an Strafpunkten. (Shut the Box = 0 Punkte!)
            </p>
            <div class="scorecard-table-wrap">
                <table class="stb-score-table" id="stb-score-table">
                    <!-- Dynamisch befüllt -->
                </table>
            </div>
            <div style="margin-top: 1rem; text-align: right;">
                <button type="button" class="btn btn-primary js-close-scorecard">Schließen</button>
            </div>
        </div>
    </div>

    <!-- 1. LOBBY-ANSICHT -->
    <div id="pane-lobby" class="stb-lobby-box hidden">
        <div style="font-size: 3rem; margin-bottom: 0.5rem;">📦</div>
        <h2 style="font-size: 1.4rem; margin-bottom: 0.25rem;">Shut the Box – Klappbrett</h2>
        <p class="text-muted" style="font-size: 0.85rem;">Für 1 bis 4 Spieler. Lade Mitspieler ein oder füge Bots hinzu.</p>
        
        <div class="stb-lobby-players" id="lobby-players-list">
            <!-- Spielerliste -->
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 1.25rem;">
            <button type="button" class="btn btn-outline js-btn-add-bot hidden" id="btn-add-bot">
                🤖 KI-Bot hinzufügen (+1)
            </button>
            <button type="button" class="btn btn-primary btn-lg js-btn-start-game hidden" id="btn-start-game">
                Würfel rollen lassen! (Start)
            </button>
            <div id="lobby-waiting-notice" class="text-muted hidden" style="font-size: 0.9rem; padding: 0.5rem;">
                Warte auf den Host zum Starten...
            </div>
        </div>
    </div>

    <!-- 2. SPIELARENA -->
    <main id="pane-game" class="stb-game-arena hidden">
        <!-- Event-Ticker Banner -->
        <div class="stb-event-ticker" id="event-ticker-text">
            Spiel gestartet...
        </div>

        <!-- Mitspieler-Klappenleisten (Rund um das Brett) -->
        <div class="opponents-flaps-container" id="opponents-flaps-container">
            <!-- 1 bis 3 Mitspieler-Seiten -->
        </div>

        <!-- Zentrales Würfelfeld (Grüner Samt-Teller im Holzrahmen) -->
        <div class="center-rolling-tray">
            <div class="felt-mat">
                <div class="qwixx-dice-row dice-wrapper" id="dice-container">
                    <div class="qwixx-die die-white stb-die" id="die-1" data-val="1">
                        <span class="die-pip pip-center"></span>
                    </div>
                    <div class="qwixx-die die-white stb-die" id="die-2" data-val="1">
                        <span class="die-pip pip-center"></span>
                    </div>
                </div>

                <div class="dice-sum-display" id="dice-sum-display">
                    Augensumme: <strong>-</strong>
                </div>

                <div class="turn-action-zone">
                    <button type="button" class="btn-stb-roll js-btn-roll" id="btn-roll-dice">
                        🎲 WÜRFELN
                    </button>
                    <div class="turn-status-text" id="turn-status-text">
                        Du bist am Zug!
                    </div>
                </div>
            </div>
        </div>

        <!-- Schnellauswahl für gefundene gültige Kombinationen -->
        <div class="valid-combos-shelf hidden" id="valid-combos-shelf">
            <span class="shelf-label">Mögliche Kombinationen:</span>
            <div class="combo-pills-row" id="combo-pills-container">
                <!-- Schnellklick-Buttons für Kombinationen -->
            </div>
        </div>

        <!-- Eigene Klappenleiste (Unten im Daumenbereich) -->
        <div class="player-flaps-shelf">
            <div class="shelf-header">
                <div class="shelf-player-info">
                    <span class="shelf-avatar">👤</span>
                    <strong id="my-shelf-name">Meine Klappen</strong>
                    <span class="shelf-penalty-badge" id="my-open-penalty-badge">Offen: 55</span>
                </div>
                <button type="button" class="btn-flip-confirm js-btn-confirm-flip" id="btn-confirm-flip" disabled>
                    ✓ Umlegen
                </button>
            </div>

            <!-- Die 10 Holzklappen -->
            <div class="flaps-row" id="my-flaps-row">
                <!-- 1 bis 10 -->
            </div>
        </div>
    </main>

    <!-- 3. GEWINNER-ANSICHT -->
    <div id="pane-finished" class="stb-lobby-box text-center hidden">
        <div style="font-size: 4rem; margin-bottom: 0.5rem;">🏆</div>
        <h2 style="font-size: 1.6rem; color: #fbbf24; margin-bottom: 0.5rem;" id="winner-name-display">Gewonnen!</h2>
        <p class="text-muted" style="margin-bottom: 1.25rem;" id="winner-desc">Geringste Gesamtpunktzahl erreicht.</p>
        
        <div class="scorecard-table-wrap" style="margin-bottom: 1.5rem;">
            <table class="stb-score-table" id="finished-score-table">
                <!-- Endstand-Tabelle -->
            </table>
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
    <script src="./shut_the_box.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>

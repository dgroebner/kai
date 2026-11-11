<?php
require_once __DIR__ . '/../../bootstrap.php';
$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Schnauzer (31 / Schwimmen) - Quarxl</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="../android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
    <meta name="theme-color" content="#0f172a">
    <link rel="stylesheet" href="../css/games-hub.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="../css/playing-cards.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="./schnauzer.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body schnauzer-viewport" data-room="<?= $roomCode ?>">

    <!-- TOP-BAR -->
    <header class="schnauzer-header">
        <div class="schnauzer-header-left">
            <a href="../index.php" class="schnauzer-back-btn">&larr; Hub</a>
            <span class="schnauzer-room-badge" id="room-code-display"><span class="schnauzer-btn-label">RAUM: </span><?= $roomCode ?: '...' ?></span>
            <div id="deck-toggle-container"></div>
        </div>
        <div class="schnauzer-header-right">
            <button type="button" class="btn btn-sm btn-outline js-btn-reactions" title="Reaktion senden">
                😀
            </button>
            <button type="button" class="btn btn-sm btn-outline js-btn-edit-name" title="Spielername ändern">
                ✏️ <span class="js-my-name-display schnauzer-my-name">Ich</span>
            </button>
            <button type="button" class="btn btn-sm btn-whatsapp js-share-whatsapp" title="Per WhatsApp einladen">
                📲 <span class="schnauzer-btn-label">Einladen</span>
            </button>
        </div>
    </header>

    <!-- BEITRITT-OVERLAY -->
    <div id="join-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center">
            <div style="font-size: 2.75rem; margin-bottom: 0.5rem;">🪙</div>
            <h3 class="hub-modal-title">Schnauzer beitreten</h3>
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

    <!-- GEBER-AUSWAHL MODAL -->
    <div id="modal-dealer-choice" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center" style="max-width: 440px;">
            <div style="font-size: 2.5rem; margin-bottom: 0.25rem;">🃏</div>
            <h3 class="hub-modal-title">Du bist der Geber!</h3>
            <p class="text-muted" style="font-size: 0.88rem; margin-bottom: 1rem;">
                Wähle, ob du dein erstes 3er-Päckchen behalten oder mit dem zweiten verdeckten Päckchen tauschen möchtest:
            </p>
            <div style="margin-bottom: 1rem;">
                <div style="font-size: 0.8rem; font-weight: 700; color: #94a3b8; margin-bottom: 0.5rem; text-transform: uppercase;">
                    Dein aktuelles Päckchen:
                </div>
                <div id="dealer-preview-hand" class="dealer-cards-preview"></div>
                <div id="dealer-preview-score" style="margin-top: 0.5rem; font-weight: 800; font-size: 0.95rem; color: #f59e0b;"></div>
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button type="button" class="btn btn-outline btn-lg js-btn-dealer-keep" style="flex: 1;">
                    ✋ Behalten
                </button>
                <button type="button" class="btn btn-primary btn-lg js-btn-dealer-swap" style="flex: 1;">
                    🔄 Tauschen
                </button>
            </div>
        </div>
    </div>

    <!-- 1. LOBBY-ANSICHT -->
    <div id="pane-lobby" class="schnauzer-lobby-box hidden">
        <h2 style="font-size: 1.4rem; margin-bottom: 0.25rem;">Schnauzer (31) Wartezimmer</h2>
        <p class="text-muted" style="font-size: 0.85rem;">Teile den Code oder lade Mitspieler per WhatsApp ein.</p>
        <div id="lobby-rules-badge" style="margin: 0.65rem 0 0.85rem 0; font-size: 0.82rem; font-weight: 700;"></div>
        
        <div class="schnauzer-lobby-players" id="lobby-players-list">
            <!-- Spielerliste -->
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1.25rem;">
            <button type="button" class="btn btn-outline js-btn-add-bot hidden" id="btn-add-bot">
                🤖 KI-Bot hinzufügen
            </button>
            <button type="button" class="btn btn-primary btn-lg js-btn-start-game hidden" id="btn-start-game">
                🚀 Spiel starten
            </button>
        </div>
    </div>

    <!-- 2. SPIELTISCH-ARENA -->
    <div id="pane-arena" class="schnauzer-arena hidden">
        
        <!-- GEGNER-LEISTE (OBEN) -->
        <div class="schnauzer-opponents-row" id="opponents-container">
            <!-- Dynamisch befüllt -->
        </div>

        <!-- TISCHMITTE: OFFENE GEMEINSCHAFTSKARTEN -->
        <div class="schnauzer-table-center">
            <div class="table-turn-banner" id="table-turn-banner">
                <span id="turn-indicator-text">Runde wird vorbereitet...</span>
            </div>

            <!-- 3 Offene Tischkarten -->
            <div class="schnauzer-table-cards" id="table-cards-container">
                <!-- 3 Karten in der Mitte -->
            </div>

            <div class="table-instruction" id="table-instruction">
                Wähle eine Tischkarte und eine deiner Handkarten zum Tauschen
            </div>
        </div>

        <!-- EIGENER BEREICH (UNTEN) -->
        <div class="schnauzer-my-area">
            <!-- Eigene Status-Leiste (Leben, Punkte, Geber-Badge) -->
            <div class="schnauzer-my-status-bar">
                <div class="my-lives-display" id="my-lives-display">
                    <!-- Münzen / Schwimmer -->
                </div>
                <div class="my-score-badge" id="my-score-badge">
                    Blatt: --
                </div>
            </div>

            <!-- Eigene 3 Handkarten -->
            <div class="schnauzer-my-hand" id="my-hand-container">
                <!-- 3 eigene Handkarten -->
            </div>

            <!-- Aktions-Schaltflächen -->
            <div class="schnauzer-action-bar">
                <button type="button" class="btn btn-primary js-btn-swap-one" id="btn-swap-one" disabled>
                    🔄 1 Tauschen
                </button>
                <button type="button" class="btn btn-secondary js-btn-swap-all" id="btn-swap-all" disabled>
                    🔀 Alle 3 tauschen
                </button>
                <button type="button" class="btn btn-outline js-btn-pass" id="btn-pass" disabled>
                    ⏩ Schieben
                </button>
                <button type="button" class="btn btn-danger js-btn-knock" id="btn-knock" disabled title="Macht die Runde zu – jeder andere hat noch genau einen Zug">
                    ✊ Klopfen (Zumachen)
                </button>
            </div>
        </div>

    </div>

    <!-- SHOWDOWN / RUNDEN-ENDE MODAL -->
    <div id="modal-showdown" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal" style="max-width: 500px;">
            <div class="hub-modal-header">
                <h3 class="hub-modal-title" id="showdown-modal-title">Rundenende</h3>
            </div>
            <div id="showdown-summary-text" style="font-size: 0.95rem; margin-bottom: 1rem; color: #f59e0b; font-weight: 700;"></div>
            
            <div class="showdown-results-list" id="showdown-results-list">
                <!-- Alle Spielerhände & Punkte -->
            </div>

            <div style="margin-top: 1.5rem; text-align: center;" id="showdown-actions-container">
                <button type="button" class="btn btn-primary btn-lg js-btn-next-round" id="btn-next-round" style="width: 100%;">
                    ➡️ Nächste Runde starten
                </button>
            </div>
        </div>
    </div>

    <!-- SCRIPTE -->
    <script src="../js/http-hub.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/reactions.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/playing-cards.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="./schnauzer.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

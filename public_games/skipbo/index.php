<?php
require_once __DIR__ . '/../../bootstrap.php';
$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Skip-Bo - Quarxl</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="../android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
    <meta name="theme-color" content="#064e3b">
    <link rel="stylesheet" href="../css/games-hub.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="./skipbo.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body skipbo-viewport" data-room="<?= $roomCode ?>">

    <!-- TOP-BAR -->
    <header class="skipbo-header">
        <div class="skipbo-header-title">
            <a href="../index.php" class="skipbo-back-link">&larr; Hub</a>
            <span class="skipbo-room-badge" id="room-code-display">RAUM: <?= $roomCode ?: '...' ?></span>
            <span id="game-mode-badge" class="skipbo-mode-badge">Skip-Bo</span>
        </div>
        <div class="skipbo-header-actions">
            <button type="button" class="btn btn-sm btn-outline js-btn-rules" title="Regeln &amp; Hilfe">
                ❓ <span class="skipbo-btn-label">Regeln</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline js-btn-reactions" title="Reaktion senden">
                😀
            </button>
            <button type="button" class="btn btn-sm btn-outline js-btn-edit-name" title="Spielername ändern">
                ✏️ <span class="js-my-name-display">Ich</span>
            </button>
            <button type="button" class="btn btn-sm btn-whatsapp js-share-whatsapp" title="Per WhatsApp einladen">
                📲 Einladen
            </button>
        </div>
    </header>

    <!-- BEITRITT-OVERLAY -->
    <div id="join-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center">
            <div class="skipbo-modal-icon">🎴</div>
            <h3 class="hub-modal-title">Skip-Bo beitreten</h3>
            <p class="text-muted" style="font-size: 0.88rem; margin-bottom: 1rem;">
                Raum: <strong><?= $roomCode ?></strong>
            </p>
            <div class="form-group">
                <label for="join-player-name">Dein Spielername:</label>
                <input type="text" id="join-player-name" class="form-control text-center" placeholder="z. B. Danny" maxlength="25">
            </div>
            <div id="join-error" class="hidden" style="color: var(--color-red); font-size: 0.85rem; margin-bottom: 0.5rem;"></div>
            <button type="button" class="btn btn-primary btn-lg js-btn-confirm-join" style="width: 100%;">Am Spieltisch Platz nehmen</button>
        </div>
    </div>

    <!-- REGELN / HILFE MODAL -->
    <div id="rules-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal" style="max-width: 520px; text-align: left;">
            <div class="hub-modal-header" style="margin-bottom: 0.75rem;">
                <h3 class="hub-modal-title">🃏 Skip-Bo Spielregeln</h3>
                <button type="button" class="hub-modal-close js-close-rules">&times;</button>
            </div>
            <div class="skipbo-rules-content" style="font-size: 0.88rem; line-height: 1.5; color: #cbd5e1; max-height: 65vh; overflow-y: auto; padding-right: 0.5rem;">
                <p><strong>🎯 Spielziel:</strong> Sei der Erste, der alle Karten seines eigenen <em>Zielstapels (Stockpile)</em> abgebaut hat!</p>
                <hr style="border: none; border-top: 1px solid rgba(255,255,255,0.1); margin: 0.75rem 0;">
                <p><strong>1. Die 4 Baustapel in der Tischmitte:</strong></p>
                <ul style="padding-left: 1.25rem; margin-bottom: 0.75rem;">
                    <li>Müssen mit einer <strong>1</strong> (oder einem <strong>Skip-Bo Joker</strong>) eröffnet werden.</li>
                    <li>Werden fortlaufend von <strong>1 bis 12</strong> aufsteigend bedient (1, 2, 3 ... 12).</li>
                    <li>Erreicht ein Stapel die <strong>12</strong>, wird er automatisch abgeräumt und steht wieder leer für eine neue 1 bereit.</li>
                </ul>
                <p><strong>2. Dein Spielzug:</strong></p>
                <ul style="padding-left: 1.25rem; margin-bottom: 0.75rem;">
                    <li>Zu Beginn jedes Zuges wird deine Hand auf <strong>5 Karten</strong> aufgefüllt.</li>
                    <li>Du kannst beliebig viele passende Karten auf die 4 Baustapel legen von:
                        <br>&bull; der obersten Karte deines <strong>Zielstapels</strong> (höchste Priorität!),
                        <br>&bull; beliebigen deiner <strong>5 Handkarten</strong>,
                        <br>&bull; der obersten Karte deiner <strong>4 persönlichen Hilfsstapel</strong>.</li>
                    <li><strong>Hand-Bonus:</strong> Legst du alle 5 Handkarten ab, ziehst du sofort 5 neue und spielst weiter!</li>
                    <li><strong>Zug beenden:</strong> Um deinen Zug zu beenden, musst du <strong>eine Handkarte</strong> auf einen deiner 4 Hilfsstapel ablegen.</li>
                </ul>
                <p><strong>3. Der Skip-Bo Joker:</strong></p>
                <p>Die <em>SKIP-BO</em> Karte ersetzt jede beliebige Zahl von 1 bis 12.</p>
            </div>
            <div style="margin-top: 1rem; text-align: center;">
                <button type="button" class="btn btn-primary js-close-rules" style="width: 100%;">Verstanden</button>
            </div>
        </div>
    </div>

    <!-- SIEGER-MODAL (GAMEOVER) -->
    <div id="modal-winner" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center" style="max-width: 400px;">
            <div style="font-size: 3.5rem; margin-bottom: 0.25rem;">🏆</div>
            <h2 class="hub-modal-title" style="font-size: 1.6rem; color: #f59e0b;" id="winner-title">Glückwunsch!</h2>
            <p id="winner-message" style="font-size: 1.05rem; margin: 0.75rem 0 1.5rem; color: #f8fafc; font-weight: 600;">
                Danny hat alle Zielstapel-Karten abgeräumt!
            </p>
            <div style="display: flex; gap: 0.75rem; justify-content: center;">
                <button type="button" class="btn btn-outline js-btn-back-hub">Zum Hub</button>
                <button type="button" class="btn btn-primary js-btn-play-again hidden" id="btn-play-again">Nochmal spielen</button>
            </div>
        </div>
    </div>

    <!-- 1. LOBBY-ANSICHT -->
    <div id="pane-lobby" class="skipbo-lobby-box hidden">
        <div class="skipbo-lobby-header">
            <div class="skipbo-card-logo-badge">SKIP-BO</div>
            <h2 class="skipbo-lobby-title">Wartezimmer</h2>
            <p class="text-muted" style="font-size: 0.85rem;" id="lobby-mode-desc">Standard-Runde mit 20 Karten im Zielstapel.</p>
        </div>

        <div class="skipbo-lobby-players" id="lobby-players-list">
            <!-- Spielerliste dynamically populated -->
        </div>

        <div class="skipbo-lobby-actions">
            <button type="button" class="btn btn-outline js-btn-add-bot hidden" id="btn-add-bot">
                🤖 KI-Bot hinzufügen
            </button>
            <button type="button" class="btn btn-primary btn-lg js-btn-start-game hidden" id="btn-start-game">
                🚀 Spiel starten
            </button>
            <div id="lobby-waiting-notice" class="text-muted" style="font-size: 0.85rem; margin-top: 0.5rem;">
                Warte auf den Host zum Starten...
            </div>
        </div>
    </div>

    <!-- 2. SPIELTISCH (BOARD ARENA) -->
    <main id="pane-game" class="skipbo-table-arena hidden">

        <!-- MITBEWERBER / GEGNER-LEISTE (OBEN) -->
        <section class="skipbo-opponents-bar" id="opponents-container" aria-label="Mitspieler am Tisch">
            <!-- Dynamisch befüllt mit Opponent-Slots -->
        </section>

        <!-- SPIELTISCH-MITTE: DAS GRÜNE FILZBRETT -->
        <section class="skipbo-mat">
            
            <!-- STAPEL-KOPFZEILE / STATUS-TICKER -->
            <div class="skipbo-mat-header">
                <div class="skipbo-ticker-text" id="event-ticker">
                    Willkommen am Skip-Bo Spieltisch!
                </div>
            </div>

            <!-- DIE ZENTRALE BAUSTAPEL-ZONE (4 BAUSTAPEL + NACHZIEHSTAPEL) -->
            <div class="skipbo-center-board">
                
                <!-- NACHZIEHSTAPEL -->
                <div class="skipbo-draw-deck-slot" id="draw-deck-slot" title="Nachziehstapel (wird automatisch nachgezogen)">
                    <div class="skipbo-deck-back">
                        <div class="skipbo-deck-logo">SKIP-BO</div>
                    </div>
                    <div class="skipbo-draw-count-badge" id="draw-pile-count">162</div>
                    <div class="skipbo-slot-label">Nachziehen</div>
                </div>

                <!-- 4 GEMEINSAME BAUSTAPEL (1 BIS 12) -->
                <div class="skipbo-building-grid" id="building-piles-grid">
                    <!-- 4 Baustapel-Slots (Index 0..3) -->
                    <?php for ($bp = 0; $bp < 4; $bp++): ?>
                    <div class="skipbo-building-slot js-building-slot" data-pile="<?= $bp ?>" id="building-pile-<?= $bp ?>">
                        <div class="skipbo-slot-ring">
                            <span class="skipbo-slot-needed-num">1</span>
                        </div>
                        <div class="skipbo-slot-card-container js-slot-card-target"></div>
                        <div class="skipbo-building-label">Stapel <?= $bp + 1 ?> <span class="skipbo-sequence-indicator">[1➔12]</span></div>
                    </div>
                    <?php endfor; ?>
                </div>

            </div>

        </section>

        <!-- MEINE SPIELERZONE (UNTEN) -->
        <section class="skipbo-my-zone" id="my-player-zone">

            <!-- INTERAKTIONS-HINWEIS & ZUG-STEUERUNG -->
            <div class="skipbo-turn-guide" id="turn-guide-bar">
                <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
                    <span id="turn-status-badge" class="skipbo-turn-badge">⏳ Warte</span>
                    <span id="turn-guide-text">Warte auf deinen Zug...</span>
                </div>
                <div id="turn-actions-row" class="hidden" style="margin-top: 0.45rem; display: flex; justify-content: center; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="button" class="btn btn-warning btn-sm js-btn-end-turn" id="btn-end-turn" style="font-weight: 700;">
                        ⏭️ Handkarte abwerfen &amp; Zug beenden
                    </button>
                </div>
            </div>

            <!-- MEIN ZIELSTAPEL & MEINE 4 HILFSSTAPEL -->
            <div class="skipbo-my-stacks-row">
                
                <!-- MEIN ZIELSTAPEL (STOCKPILE) -->
                <div class="skipbo-stockpile-wrapper">
                    <div class="skipbo-stockpile-box js-my-stockpile" id="my-stockpile-box" role="button" tabindex="0">
                        <div class="skipbo-stock-thickness-3d" id="my-stock-thickness"></div>
                        <div class="skipbo-stock-top-card js-my-stock-card" id="my-stock-card-slot">
                            <!-- Oberste Karte des Zielstapels -->
                        </div>
                        <div class="skipbo-stock-count-badge" id="my-stock-count-badge">Ziel: 20</div>
                    </div>
                    <div class="skipbo-zone-label">🎯 Zielstapel</div>
                </div>

                <!-- TRENNER -->
                <div class="skipbo-stacks-divider"></div>

                <!-- MEINE 4 HILFSSTAPEL (DISCARD PILES A-D) -->
                <div class="skipbo-discard-grid" id="my-discard-grid">
                    <?php for ($dp = 0; $dp < 4; $dp++): ?>
                    <div class="skipbo-discard-slot js-my-discard-slot" data-discard="<?= $dp ?>" id="my-discard-slot-<?= $dp ?>" role="button" tabindex="0">
                        <div class="skipbo-discard-card-target js-discard-card-target"></div>
                        <div class="skipbo-discard-counter-badge js-discard-count">0</div>
                        <div class="skipbo-discard-label">Hilfe <?= $dp + 1 ?></div>
                    </div>
                    <?php endfor; ?>
                </div>

            </div>

            <!-- MEINE HANDKARTEN (BIS ZU 5 KARTEN) -->
            <div class="skipbo-hand-container">
                <div class="skipbo-hand-label">
                    <span>Deine Handkarten</span>
                    <span class="skipbo-hand-count-pill" id="hand-count-indicator">0 / 5</span>
                </div>
                <div class="skipbo-hand-cards" id="my-hand-cards-rack">
                    <!-- 5 Handkarten dynamically rendered -->
                </div>
            </div>

        </section>

    </main>

    <!-- FLOATING REAKTIONEN LAYER -->
    <div id="reactions-floating-layer" class="reactions-floating-layer" aria-hidden="true"></div>

    <script src="../js/http-hub.js?v=<?= APP_VERSION ?>"></script>
    <script src="../js/reactions.js?v=<?= APP_VERSION ?>"></script>
    <script src="./skipbo.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

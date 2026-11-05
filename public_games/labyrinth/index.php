<?php
require_once __DIR__ . '/../../bootstrap.php';
$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Das verrückte Labyrinth - Quarxl</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="../android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
    <meta name="theme-color" content="#1e1b4b">
    <link rel="stylesheet" href="../css/games-hub.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="./labyrinth.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body lb-viewport" data-room="<?= $roomCode ?>">

    <!-- TOP-BAR -->
    <header class="lb-header">
        <div class="lb-header-title">
            <a href="../index.php" class="lb-back-link">&larr; Hub</a>
            <span class="lb-room-badge" id="room-code-display">RAUM: <?= $roomCode ?: '...' ?></span>
            <span id="game-mode-badge" class="lb-mode-badge">Labyrinth</span>
        </div>
        <div class="lb-header-actions">
            <button type="button" class="btn btn-sm btn-outline js-btn-rules" title="Regeln &amp; Hilfe">
                ❓ <span class="lb-btn-label">Regeln</span>
            </button>
            <button type="button" class="btn btn-sm btn-outline js-btn-reactions" title="Reaktion senden">😀</button>
            <button type="button" class="btn btn-sm btn-outline js-btn-edit-name" title="Spielername ändern">
                ✏️ <span class="js-my-name-display lb-btn-label">Ich</span>
            </button>
            <button type="button" class="btn btn-sm btn-whatsapp js-share-whatsapp" title="Per WhatsApp einladen">
                📲 Einladen
            </button>
        </div>
    </header>

    <!-- BEITRITT-OVERLAY -->
    <div id="join-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center">
            <div class="lb-modal-icon">🧭</div>
            <h3 class="hub-modal-title">Labyrinth beitreten</h3>
            <p class="text-muted lb-modal-room">Raum: <strong><?= $roomCode ?></strong></p>
            <div class="form-group">
                <label for="join-player-name">Dein Spielername:</label>
                <input type="text" id="join-player-name" class="form-control text-center" placeholder="z. B. Danny" maxlength="25">
            </div>
            <div id="join-error" class="lb-error hidden"></div>
            <button type="button" class="btn btn-primary btn-lg js-btn-confirm-join lb-btn-block">Am Spieltisch Platz nehmen</button>
        </div>
    </div>

    <!-- REGELN / HILFE MODAL -->
    <div id="rules-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal lb-rules-modal">
            <div class="hub-modal-header">
                <h3 class="hub-modal-title">🧭 Regeln: Das verrückte Labyrinth</h3>
                <button type="button" class="hub-modal-close js-close-rules">&times;</button>
            </div>
            <div class="lb-rules-content">
                <p><strong>🎯 Spielziel:</strong> Finde der Reihe nach alle Schätze deiner Schatzkarten – und kehre danach zu deinem Startfeld zurück!</p>
                <p><strong>1. Kachel einschieben:</strong> Dreh die freie Kachel (↻ / ↺) und schiebe sie über einen der Pfeile am Rand ins Labyrinth. Das ist Pflicht, auch wenn dein Schatz schon erreichbar wäre.</p>
                <p><strong>2. Figur ziehen:</strong> Anschließend ziehst du auf ein beliebiges Feld, das du über offene Gänge erreichen kannst (leuchtet grün) – oder bleibst stehen.</p>
                <p><strong>🚫 Rückgängig-Verbot:</strong> Der Einschub des Vorgängers darf nicht direkt in Gegenrichtung zurückgeschoben werden.</p>
                <p><strong>🏃 Rausgeschoben?</strong> Wird deine Figur aus dem Brett geschoben, landet sie auf der neu eingeschobenen Kachel am anderen Ende.</p>
                <p><strong>🕵️ Geheim:</strong> Du siehst nur deinen aktuellen Schatz. Von den Mitspielern siehst du nur, wie viele Karten sie noch haben.</p>
            </div>
            <div class="lb-modal-footer">
                <button type="button" class="btn btn-primary js-close-rules lb-btn-block">Verstanden</button>
            </div>
        </div>
    </div>

    <!-- SIEGER-MODAL -->
    <div id="modal-winner" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center lb-winner-modal">
            <div class="lb-winner-icon">🏆</div>
            <h2 class="hub-modal-title lb-winner-title">Glückwunsch!</h2>
            <p id="winner-message" class="lb-winner-message"></p>
            <div class="lb-winner-actions">
                <button type="button" class="btn btn-outline js-btn-back-hub">Zum Hub</button>
                <button type="button" class="btn btn-primary js-btn-play-again hidden" id="btn-play-again">Nochmal spielen</button>
            </div>
            <div id="winner-wait-notice" class="text-muted lb-small hidden">Warte auf den Host für die nächste Runde...</div>
        </div>
    </div>

    <!-- 1. LOBBY -->
    <div id="pane-lobby" class="lb-lobby-box hidden">
        <div class="lb-logo-badge">🧭 LABYRINTH</div>
        <h2 class="lb-lobby-title">Wartezimmer</h2>
        <p class="text-muted lb-small" id="lobby-mode-desc"></p>
        <div class="lb-lobby-players" id="lobby-players-list"></div>

        <!-- Bot-Schwierigkeit Steuerung (in der Lobby wählbar) -->
        <div class="lb-lobby-diff-wrap" id="lobby-diff-wrap">
            <label for="select-bot-difficulty" class="lb-small text-muted" style="display: block; margin-bottom: 0.35rem; font-weight: 600;">
                🤖 Bot-Schwierigkeitsgrad:
            </label>
            <div id="host-diff-controls" class="hidden">
                <select id="select-bot-difficulty" class="form-control" style="font-size: 0.88rem; padding: 0.4rem 0.6rem; text-align-last: center; margin-bottom: 0.25rem;">
                    <option value="easy">🌱 Leicht (Gelegenheitsspieler)</option>
                    <option value="normal" selected>⚔️ Normal (Ausgewogen)</option>
                    <option value="master">🧠 Meister (Gnadenlos)</option>
                </select>
            </div>
            <div id="guest-diff-display" class="badge" style="font-size: 0.85rem; padding: 0.35rem 0.75rem; background: rgba(99, 102, 241, 0.25); border: 1px solid rgba(99, 102, 241, 0.4); border-radius: 8px;">
                ⚔️ Normal (Ausgewogen)
            </div>
        </div>

        <div class="lb-lobby-actions">
            <button type="button" class="btn btn-outline hidden" id="btn-add-bot">🤖 KI-Bot hinzufügen</button>
            <button type="button" class="btn btn-primary btn-lg hidden" id="btn-start-game">🚀 Spiel starten</button>
            <div id="lobby-waiting-notice" class="text-muted lb-small">Warte auf den Host zum Starten...</div>
        </div>
    </div>

    <!-- 2. SPIELTISCH -->
    <main id="pane-game" class="lb-arena hidden">
        <section class="lb-players-bar" id="players-bar" aria-label="Mitspieler"></section>

        <div class="lb-ticker" id="event-ticker">Willkommen im Labyrinth!</div>

        <section class="lb-table">
            <div class="lb-board-wrap">
                <div class="lb-board" id="lb-board" aria-label="Spielfeld"></div>
            </div>

            <aside class="lb-side">
                <div class="lb-panel">
                    <div class="lb-panel-label">🎴 Deine Schatzkarte</div>
                    <div class="lb-target" id="my-target"></div>
                </div>

                <div class="lb-panel">
                    <div class="lb-panel-label">🧩 Freie Kachel</div>
                    <div class="lb-spare-holder" id="spare-holder"></div>
                    <div class="lb-spare-controls">
                        <button type="button" class="btn btn-sm btn-outline" id="btn-rotate-left" title="Links drehen">↺</button>
                        <button type="button" class="btn btn-sm btn-outline" id="btn-rotate-right" title="Rechts drehen">↻</button>
                    </div>
                </div>

                <div class="lb-panel lb-turn-panel">
                    <span id="turn-status-badge" class="lb-turn-badge">⏳ Warte</span>
                    <span id="turn-guide-text" class="lb-small">Warte auf deinen Zug...</span>
                    <button type="button" class="btn btn-primary btn-sm lb-btn-stay hidden" id="btn-stay">🚶 Stehen bleiben</button>
                </div>
            </aside>
        </section>
    </main>

    <div id="reactions-floating-layer" class="reactions-floating-layer" aria-hidden="true"></div>

    <script src="../js/http-hub.js?v=<?= APP_VERSION ?>"></script>
    <script src="../js/reactions.js?v=<?= APP_VERSION ?>"></script>
    <script src="./labyrinth.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

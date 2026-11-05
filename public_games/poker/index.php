<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Texas Hold'em Poker | Quarxl Games</title>
    <link rel="stylesheet" href="../css/games-hub.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="../css/playing-cards.css?v=<?= APP_VERSION ?>">
    <link rel="stylesheet" href="./poker.css?v=<?= APP_VERSION ?>">
    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0c2316">
    <link rel="icon" type="image/png" sizes="192x192" href="../android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
</head>
<body class="poker-body" data-room="<?= $roomCode ?>">
    <!-- TOP-BAR -->
    <header class="hub-header">
        <div class="hub-header-left">
            <a href="../index.php" class="poker-back-btn" title="Zurück zur Spielauswahl">&larr; Hub</a>
            <span class="poker-room-badge" id="btn-copy-code" title="Code anklicken zum Kopieren">RAUM: <?= $roomCode ?: '...' ?></span>
        </div>

        <div class="hub-header-right">
            <button type="button" class="btn btn-sm btn-outline js-btn-poker-help" id="btn-poker-help" title="Poker-Blätter & Rangfolge">
                📖 Blätter
            </button>
            <button type="button" class="btn btn-sm btn-outline js-btn-abort-game hidden" id="btn-abort-game" title="Spiel vorzeitig beenden (alle müssen zustimmen)">
                🏳️ Abbruch
            </button>
            <button type="button" class="btn btn-sm btn-outline js-btn-edit-name" title="Spielername ändern">
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
            <div style="font-size: 2.75rem; margin-bottom: 0.5rem;">♠️</div>
            <h3 class="hub-modal-title">Texas Hold'em beitreten</h3>
            <p class="text-muted" style="font-size: 0.88rem; margin-bottom: 1rem;">
                Tisch: <strong><?= $roomCode ?></strong>
            </p>
            <div class="form-group">
                <label for="join-player-name">Dein Spielername:</label>
                <input type="text" id="join-player-name" class="form-control text-center" placeholder="z. B. Danny" maxlength="25">
            </div>
            <div id="join-error" class="hidden" style="color: var(--color-red); font-size: 0.85rem; margin-bottom: 0.5rem;"></div>
            <button type="button" class="btn btn-primary btn-lg js-btn-confirm-join" style="width: 100%;">Platz nehmen</button>
        </div>
    </div>

    <!-- ABBRUCH-VOTE BANNER -->
    <div id="abort-vote-banner" class="abort-banner hidden">
        <div class="abort-banner-text" id="abort-banner-text">Ein Mitspieler schlägt den Abbruch des Spiels vor.</div>
        <button type="button" class="btn btn-sm btn-warning js-btn-confirm-abort" id="btn-confirm-abort">
            Zustimmen
        </button>
    </div>

    <!-- 1. LOBBY-ANSICHT -->
    <div id="pane-lobby" class="poker-lobby-box hidden">
        <h2 style="font-size: 1.4rem; margin-bottom: 0.25rem;">Texas Hold'em Wartezimmer</h2>
        <p class="text-muted" style="font-size: 0.85rem;">Teile den Code oder lade Mitspieler per WhatsApp ein.</p>
        <div id="lobby-rules-badge" style="margin: 0.65rem 0 0.85rem 0; font-size: 0.82rem; font-weight: 700;">
            <span style="display:inline-block; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:#94a3b8; padding:0.25rem 0.65rem; border-radius:9999px;">
                📜 No-Limit Hold'em · 1.000 Startchips · Blinds 10 / 20
            </span>
        </div>
        
        <div class="poker-lobby-players" id="lobby-players-list">
            <!-- Spielerliste -->
        </div>

        <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1.25rem;">
            <button type="button" class="btn btn-outline js-btn-add-bot hidden" id="btn-add-bot">
                🤖 + KI-Bot hinzufügen
            </button>
            <button type="button" class="btn btn-primary btn-lg js-btn-start-game hidden" id="btn-start-game">
                🚀 Spiel starten
            </button>
        </div>
    </div>

    <!-- 2. POKER ARENA -->
    <main id="pane-arena" class="poker-arena hidden">
        <!-- OVALER POKERTISCH -->
        <div class="poker-table-wrapper">
            <div class="poker-table" id="poker-table">
                <!-- MITTE: POT & BOARD-KARTEN -->
                <div class="poker-center">
                    <div class="poker-pot-badge" id="poker-pot-badge">
                        <span class="poker-chip-icon">🟡</span> Pot: <span id="poker-pot-amount" class="poker-pot-val">0</span>
                    </div>

                    <div class="poker-street-indicator" id="poker-street-indicator">Pre-Flop</div>

                    <!-- 5 Community Cards -->
                    <div class="community-cards-container" id="community-cards-container">
                        <div class="board-card-slot" id="card-flop-1"></div>
                        <div class="board-card-slot" id="card-flop-2"></div>
                        <div class="board-card-slot" id="card-flop-3"></div>
                        <div class="board-card-slot" id="card-turn"></div>
                        <div class="board-card-slot" id="card-river"></div>
                    </div>

                    <div class="table-turn-banner" id="poker-turn-banner">
                        <span id="poker-turn-indicator-text">Spiel wird geladen...</span>
                    </div>
                </div>

                <!-- GEGNER-SITZE RUND UM DEN TISCH -->
                <div class="poker-seats-grid" id="poker-seats-container">
                    <!-- Wird dynamisch befüllt -->
                </div>
            </div>
        </div>

        <!-- MEIN SPIELERBEREICH (UNTEN) -->
        <div class="my-poker-area" id="my-poker-area">
            <div class="my-cards-and-info">
                <div class="my-hole-cards" id="my-hole-cards">
                    <!-- 2 Handkarten -->
                </div>

                <div class="my-poker-meta">
                    <div class="my-chips-wrap">
                        <span class="poker-chip-icon">🟡</span> <strong id="my-chips-val">1.000</strong> Chips
                        <span id="my-role-badge" class="poker-role-badge hidden"></span>
                    </div>
                    <div id="my-hand-strength-badge" class="my-hand-strength hidden">
                        <!-- Automatische Hand-Auswertung -->
                    </div>
                </div>
            </div>

            <!-- LIVE SPIELHILFE / HAND-BERATER -->
            <div id="my-poker-assistant" class="poker-assistant-box hidden">
                <!-- Dynamisch durch JS befüllt: Bewertung, Möglichkeiten (Draws) & Strategie-Tipp -->
            </div>

            <!-- AKTIONEN-BEDIENLEISTE -->
            <div class="poker-action-bar" id="poker-action-bar">
                <!-- Setz-Slider & Schnellwahl (nur bei Bet/Raise) -->
                <div class="poker-bet-slider-row" id="poker-bet-slider-row">
                    <div class="poker-quick-bets">
                        <button type="button" class="btn btn-sm btn-outline js-btn-quick-bet" data-fraction="min">Min</button>
                        <button type="button" class="btn btn-sm btn-outline js-btn-quick-bet" data-fraction="half">½ Pot</button>
                        <button type="button" class="btn btn-sm btn-outline js-btn-quick-bet" data-fraction="pot">Pot</button>
                        <button type="button" class="btn btn-sm btn-outline js-btn-quick-bet" data-fraction="allin">All-In</button>
                    </div>
                    <div class="poker-slider-wrap">
                        <input type="range" id="poker-bet-slider" min="20" max="1000" step="10" value="20" class="poker-range">
                        <span id="poker-bet-slider-val" class="poker-slider-val">20</span>
                    </div>
                </div>

                <!-- Primäre Aktions-Buttons -->
                <div class="poker-main-buttons">
                    <button type="button" class="btn btn-danger btn-lg js-btn-fold" id="btn-poker-fold" disabled>
                        ❌ Fold
                    </button>
                    <button type="button" class="btn btn-primary btn-lg js-btn-check-call" id="btn-poker-check-call" disabled>
                        ✋ Check
                    </button>
                    <button type="button" class="btn btn-success btn-lg js-btn-bet-raise" id="btn-poker-bet-raise" disabled>
                        🚀 Bet 20
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- 3. SHOWDOWN / HAND-RESULTAT MODAL -->
    <div id="modal-poker-showdown" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal text-center" style="max-width: 500px; width: 95%;">
            <div style="font-size: 2.25rem; margin-bottom: 0.25rem;">🏆</div>
            <h3 class="hub-modal-title" id="poker-showdown-title">Hand-Ergebnis</h3>
            <div id="poker-showdown-summary" class="showdown-summary-text" style="margin-bottom: 1rem;"></div>

            <!-- Spieler-Hände am Showdown -->
            <div id="poker-showdown-results-list" class="showdown-results-list" style="margin-bottom: 1.25rem;"></div>

            <!-- Nächste Hand / Neustart Button -->
            <div id="poker-showdown-actions">
                <button type="button" class="btn btn-primary btn-lg js-btn-next-hand" id="btn-next-hand" style="width: 100%; font-weight: 800;">
                    ➡️ Nächste Hand starten
                </button>
            </div>
        </div>
    </div>

    <!-- 4. POKER-BLÄTTER & RANGFOLGE MODAL (SPIELHILFE) -->
    <div id="modal-poker-help" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal" style="max-width: 540px; width: 95%; max-height: 88vh; overflow-y: auto; text-align: left;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <h3 class="hub-modal-title" style="margin: 0;">📖 Poker-Blätter (Rangfolge)</h3>
                <button type="button" class="btn btn-sm btn-outline js-close-poker-help">✕</button>
            </div>
            <p class="text-muted" style="font-size: 0.82rem; margin-bottom: 0.85rem;">
                Aus deinen <strong>2 Handkarten</strong> und den <strong>5 Tischkarten</strong> zählen automatisch die besten 5 Karten. Von oben (unschlagbar) nach unten:
            </p>
            <div class="poker-hand-rankings-list">
                <div class="ranking-row"><span class="ranking-pos">1.</span><div><strong>👑 Royal Flush</strong> <span class="ranking-ex">10 J Q K A in einer Farbe</span><div class="ranking-desc">Höchste Straße in derselben Farbe – unschlagbar!</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">2.</span><div><strong>🌟 Straight Flush</strong> <span class="ranking-ex">z.B. 5 6 7 8 9 in ♥</span><div class="ranking-desc">Fünf aufeinanderfolgende Karten derselben Farbe.</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">3.</span><div><strong>💣 Vierling (Quads)</strong> <span class="ranking-ex">z.B. A A A A + K</span><div class="ranking-desc">Vier Karten desselben Rangs.</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">4.</span><div><strong>🏠 Full House</strong> <span class="ranking-ex">z.B. K K K + Q Q</span><div class="ranking-desc">Ein Drilling plus ein Paar.</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">5.</span><div><strong>🎨 Flush</strong> <span class="ranking-ex">Beliebige 5 Karten in ♠</span><div class="ranking-desc">Fünf Karten derselben Farbe (Reihenfolge egal).</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">6.</span><div><strong>📏 Straße (Straight)</strong> <span class="ranking-ex">z.B. 7 8 9 10 J (bunt)</span><div class="ranking-desc">Fünf aufeinanderfolgende Karten (Farbe egal).</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">7.</span><div><strong>🔥 Drilling (Trips/Set)</strong> <span class="ranking-ex">z.B. Q Q Q + 9 + 4</span><div class="ranking-desc">Drei Karten desselben Rangs.</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">8.</span><div><strong>✌️ Zwei Paare</strong> <span class="ranking-ex">z.B. J J + 8 8 + A</span><div class="ranking-desc">Zwei verschiedene Paare plus 1 Beikarte (Kicker).</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">9.</span><div><strong>👍 Ein Paar</strong> <span class="ranking-ex">z.B. 10 10 + A K 5</span><div class="ranking-desc">Zwei Karten desselben Rangs.</div></div></div>
                <div class="ranking-row"><span class="ranking-pos">10.</span><div><strong>🃏 Höchste Karte (High Card)</strong> <span class="ranking-ex">z.B. A hoch</span><div class="ranking-desc">Keine Kombination – die höchste Einzelkarte entscheidet.</div></div></div>
            </div>
            <div style="margin-top: 1rem; text-align: right;">
                <button type="button" class="btn btn-primary js-close-poker-help" style="width: 100%;">Verstanden</button>
            </div>
        </div>
    </div>

    <!-- SCRIPTE -->
    <script src="../js/http-hub.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/reactions.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/playing-cards.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="./poker.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

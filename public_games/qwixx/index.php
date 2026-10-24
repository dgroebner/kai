<?php
require_once __DIR__ . '/../../bootstrap.php';
$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Qwixx - Kai Games Hub</title>
    <link rel="manifest" href="../manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="../android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
    <meta name="theme-color" content="#0f172a">
    <link rel="stylesheet" href="../css/games-hub.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body" data-room="<?= $roomCode ?>">
    <div class="hub-container">
        <!-- TOP-BAR: Raum-Code & Aktionen -->
        <header class="qwixx-top-bar">
            <div>
                <a href="../index.php" style="color: var(--text-muted); font-size: 0.85rem; display: block;">&larr; Lobby</a>
                <span class="qwixx-room-pill" id="room-code-display">RAUM: <?= $roomCode ?: '...' ?></span>
            </div>
            <div style="display: flex; gap: 0.4rem;">
                <button type="button" class="btn btn-sm btn-whatsapp js-share-whatsapp" title="Per WhatsApp einladen">
                    📲 Einladen
                </button>
            </div>
        </header>

        <!-- BEITRITT-OVERLAY (Falls Spieler noch keinen Namen eingegeben hat) -->
        <div id="join-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
            <div class="hub-modal text-center">
                <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🎲</div>
                <h3 class="hub-modal-title">Dem Qwixx-Spiel beitreten</h3>
                <p class="text-muted" style="font-size: 0.88rem; margin-bottom: 1rem;">
                    Raum: <strong><?= $roomCode ?></strong>
                </p>
                <div class="form-group">
                    <label for="join-player-name">Dein Spielername:</label>
                    <input type="text" id="join-player-name" class="form-control text-center" placeholder="z. B. Danny" maxlength="25">
                </div>
                <div id="join-error" class="hidden" style="color: var(--color-red); font-size: 0.85rem; margin-bottom: 0.5rem;"></div>
                <button type="button" class="btn btn-primary btn-lg js-btn-confirm-join">Dem Spiel beitreten</button>
            </div>
        </div>

        <!-- LOBBY-ANSICHT (Bevor das Spiel gestartet ist) -->
        <section id="pane-lobby" class="card-box text-center hidden">
            <h2>Wartezimmer</h2>
            <p class="text-muted" style="margin-bottom: 1.5rem;">
                Lade Mitspieler ein, indem du den Link oder Code teilst.
            </p>
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: var(--text-muted);">Bereit am Spieltisch:</h4>
                <div id="lobby-players-list" style="display: flex; justify-content: center; gap: 0.5rem; flex-wrap: wrap;"></div>
            </div>
            <div id="lobby-host-controls" class="hidden">
                <button type="button" class="btn btn-primary btn-lg js-btn-start-game">🏁 Spiel jetzt starten</button>
            </div>
            <div id="lobby-guest-hint" class="text-muted hidden" style="font-size: 0.88rem;">
                Der Host startet das Spiel, sobald alle Mitspieler da sind...
            </div>
        </section>

        <!-- HAUPT-SPIELFELD (Aktiv während des Spiels) -->
        <main id="pane-game" class="hidden">
            <!-- Mitspieler-Score-Bar oben -->
            <div class="qwixx-players-scroll" id="players-bar">
                <!-- Dynamisch generiert -->
            </div>

            <!-- Würfel-Bühne (Nur im Modus digital_dice) -->
            <section id="dice-stage" class="qwixx-dice-stage hidden">
                <div class="qwixx-dice-row" id="dice-row">
                    <!-- 6 Würfel -->
                </div>
                <div style="display: flex; justify-content: center; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                    <div class="qwixx-white-sum-box" id="white-sum-box">
                        <span>⚪➕⚪ Weiße Summe:</span>
                        <strong id="white-sum-val" style="font-size: 1.25rem; color: #fff;">-</strong>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm js-btn-pass-white hidden" id="btn-pass-white" title="Weiße Summe nicht ankreuzen">
                        ⚪ Auslassen
                    </button>
                    <button type="button" class="btn btn-primary btn-sm js-btn-roll hidden" id="btn-roll-dice">
                        🎲 Jetzt Würfeln
                    </button>
                    <button type="button" class="btn btn-outline btn-sm js-btn-next-turn hidden" id="btn-next-turn">
                        Zug abschließen &rarr;
                    </button>
                </div>
            </section>

            <!-- Status-Banner (Wer ist am Zug / Hinweise) -->
            <div id="turn-instruction-bar" style="background: rgba(59, 130, 246, 0.15); border: 1px solid var(--accent); border-radius: 8px; padding: 0.5rem 0.75rem; text-align: center; font-size: 0.88rem; font-weight: 600; margin-bottom: 1rem;">
                Spiel läuft...
            </div>

            <!-- DER DIGITALE QWIXX SPIELZETTEL -->
            <section class="qwixx-sheet" id="qwixx-sheet" aria-label="Qwixx Spielzettel">
                <!-- ROTE REIHE (2 bis 12) -->
                <div class="qwixx-row qwixx-row-red" data-color="red">
                    <?php for ($n = 2; $n <= 12; $n++): ?>
                        <div class="qwixx-cell js-cell" data-color="red" data-num="<?= $n ?>" role="button" tabindex="0"><?= $n ?></div>
                    <?php endfor; ?>
                    <div class="qwixx-cell qwixx-cell-lock" id="lock-red" title="Reihe abschließen">🔒</div>
                </div>

                <!-- GRÜNE REIHE (2 bis 12) -->
                <div class="qwixx-row qwixx-row-green" data-color="green">
                    <?php for ($n = 2; $n <= 12; $n++): ?>
                        <div class="qwixx-cell js-cell" data-color="green" data-num="<?= $n ?>" role="button" tabindex="0"><?= $n ?></div>
                    <?php endfor; ?>
                    <div class="qwixx-cell qwixx-cell-lock" id="lock-green" title="Reihe abschließen">🔒</div>
                </div>

                <!-- GELBE REIHE (12 bis 2) -->
                <div class="qwixx-row qwixx-row-yellow" data-color="yellow">
                    <?php for ($n = 12; $n >= 2; $n--): ?>
                        <div class="qwixx-cell js-cell" data-color="yellow" data-num="<?= $n ?>" role="button" tabindex="0"><?= $n ?></div>
                    <?php endfor; ?>
                    <div class="qwixx-cell qwixx-cell-lock" id="lock-yellow" title="Reihe abschließen">🔒</div>
                </div>

                <!-- BLAUE REIHE (12 bis 2) -->
                <div class="qwixx-row qwixx-row-blue" data-color="blue">
                    <?php for ($n = 12; $n >= 2; $n--): ?>
                        <div class="qwixx-cell js-cell" data-color="blue" data-num="<?= $n ?>" role="button" tabindex="0"><?= $n ?></div>
                    <?php endfor; ?>
                    <div class="qwixx-cell qwixx-cell-lock" id="lock-blue" title="Reihe abschließen">🔒</div>
                </div>
            </section>

            <!-- Fehlwürfe Bar -->
            <div class="qwixx-missed-bar">
                <span style="font-size: 0.88rem; font-weight: 600;">⚡ Fehlwürfe (-5 Pkt.):</span>
                <div class="qwixx-missed-slots">
                    <div class="qwixx-missed-box js-missed-box" data-index="1">-5</div>
                    <div class="qwixx-missed-box js-missed-box" data-index="2">-5</div>
                    <div class="qwixx-missed-box js-missed-box" data-index="3">-5</div>
                    <div class="qwixx-missed-box js-missed-box" data-index="4">-5</div>
                </div>
            </div>

            <!-- Punktestand-Vorschau -->
            <div class="qwixx-score-summary">
                <div class="qwixx-score-col-red">🔴 <span id="score-red">0</span></div>
                <div class="qwixx-score-col-green">🟢 <span id="score-green">0</span></div>
                <div class="qwixx-score-col-yellow">🟡 <span id="score-yellow">0</span></div>
                <div class="qwixx-score-col-blue">🔵 <span id="score-blue">0</span></div>
                <div class="qwixx-score-col-missed">⚡ <span id="score-missed">0</span></div>
                <div class="qwixx-score-col-total">🏆 <span id="score-total">0</span></div>
            </div>
        </main>

        <!-- SIEGEREHRUNGS-OVERLAY (Spielende) -->
        <div id="game-over-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
            <div class="hub-modal text-center">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">🎉</div>
                <h2 id="game-over-title">Spiel beendet!</h2>
                <p id="game-over-reason" class="text-muted" style="margin-bottom: 1.25rem;">Zwei Reihen wurden abgeschlossen.</p>
                <div id="game-over-leaderboard" style="margin-bottom: 1.5rem; text-align: left;">
                    <!-- Rangliste aller Spieler -->
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <a href="../index.php" class="btn btn-outline" style="flex: 1;">Zur Lobby</a>
                    <button type="button" class="btn btn-primary js-btn-rematch hidden" id="btn-rematch" style="flex: 1;">🔄 Revanche</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../js/http-hub.js?v=<?= APP_VERSION ?>"></script>
    <script src="../js/qwixx.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

<?php
require_once __DIR__ . '/../../bootstrap.php';
$roomCode = htmlspecialchars(strtoupper(trim($_GET['room'] ?? '')), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Kniffel - Quarxl</title>
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
            <div style="display: flex; gap: 0.4rem; align-items: center;">
                <button type="button" class="btn btn-sm btn-outline js-btn-reactions" title="Reaktion senden" style="display: flex; align-items: center; gap: 0.25rem;">
                    😀 <span class="quarxl-react-btn-label">Reagieren</span>
                </button>
                <button type="button" class="btn btn-sm btn-outline js-btn-edit-name" title="Spielername ändern" style="display: flex; align-items: center; gap: 0.25rem;">
                    ✏️ <span class="js-my-name-display" style="max-width: 95px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">Ich</span>
                </button>
                <button type="button" class="btn btn-sm btn-whatsapp js-share-whatsapp" title="Per WhatsApp einladen">
                    📲 Einladen
                </button>
            </div>
        </header>

        <!-- BEITRITT-OVERLAY (Falls Spieler noch keinen Namen eingegeben hat) -->
        <div id="join-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
            <div class="hub-modal text-center">
                <div style="font-size: 2.5rem; margin-bottom: 0.5rem;">🎲</div>
                <h3 class="hub-modal-title">Dem Kniffel-Spiel beitreten</h3>
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
            <h2>Kniffel Wartezimmer</h2>
            <p class="text-muted" style="margin-bottom: 1.5rem;">
                Lade Mitspieler ein, indem du den Raum-Link teilst.
            </p>
            <div style="margin-bottom: 1.5rem;">
                <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.5rem;">Bereits am Tisch:</div>
                <div id="lobby-players-list" style="display: flex; gap: 0.5rem; justify-content: center; flex-wrap: wrap;">
                    <!-- Dynamische Spielerliste -->
                </div>
            </div>
            <div id="lobby-host-controls" class="hidden">
                <button type="button" class="btn btn-primary btn-lg js-btn-start-game">
                    🚀 Spiel jetzt starten
                </button>
            </div>
            <div id="lobby-guest-hint" class="hidden text-muted" style="font-size: 0.9rem;">
                ⏳ Warte, bis der Host das Spiel startet...
            </div>
        </section>

        <!-- HAUPTSPIEL-ANSICHT -->
        <main id="pane-game" class="hidden">
            <!-- Mitspieler-Kacheln mit Punktestand & Bonus-Status -->
            <div class="qwixx-players-scroll" id="players-bar">
                <!-- Dynamisch generiert -->
            </div>

            <!-- Würfel-Bühne (Nur im Modus digital_dice) -->
            <section id="dice-stage" class="kniffel-dice-stage">
                <div class="kniffel-stage-header">
                    <span id="kniffel-roll-counter" class="kniffel-roll-pill">Noch nicht gewürfelt</span>
                    <span id="kniffel-hold-hint" class="text-muted" style="font-size: 0.78rem;">Tippe Würfel an zum Behalten 🔒</span>
                </div>

                <!-- Die 5 Kniffel-Würfel -->
                <div class="kniffel-dice-row" id="dice-row">
                    <!-- 5 Würfel -->
                </div>

                <!-- Aktions-Button zum Würfeln -->
                <div class="qwixx-stage-actions" style="margin-top: 0.75rem;">
                    <button type="button" class="btn btn-action-roll js-btn-roll" id="btn-roll-dice">
                        🎲 Jetzt Würfeln (1/3)
                    </button>
                </div>
            </section>

            <!-- Status-Banner (Wer ist am Zug / Anweisungen) -->
            <div id="turn-instruction-bar" class="kniffel-instruction-bar">
                Spiel läuft...
            </div>

            <!-- DER DIGITALE KNIFFEL-BLOCK -->
            <section class="kniffel-sheet" id="kniffel-sheet" aria-label="Kniffel Spielblatt">
                <!-- OBERER TEIL -->
                <div class="kniffel-section card-box">
                    <div class="kniffel-section-header">
                        <h4>Oberer Teil (1er bis 6er)</h4>
                        <span class="kniffel-bonus-badge" id="upper-bonus-badge">Noch 63 für Bonus (+35)</span>
                    </div>

                    <div class="kniffel-rows-grid">
                        <div class="kniffel-row js-category-row" data-cat="ones" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">1er</span>
                                <span class="kniffel-cat-desc">Nur Einer zählen</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-ones">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-ones">0</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="twos" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">2er</span>
                                <span class="kniffel-cat-desc">Nur Zweier zählen</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-twos">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-twos">0</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="threes" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">3er</span>
                                <span class="kniffel-cat-desc">Nur Dreier zählen</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-threes">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-threes">0</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="fours" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">4er</span>
                                <span class="kniffel-cat-desc">Nur Vierer zählen</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-fours">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-fours">0</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="fives" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">5er</span>
                                <span class="kniffel-cat-desc">Nur Fünfer zählen</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-fives">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-fives">0</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="sixes" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">6er</span>
                                <span class="kniffel-cat-desc">Nur Sechser zählen</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-sixes">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-sixes">0</div>
                        </div>
                    </div>

                    <!-- Zwischensumme oben & Bonus -->
                    <div class="kniffel-subtotal-bar">
                        <div>Zwischensumme: <strong id="val-upper-sum">0</strong> / 63</div>
                        <div>Bonus (+35): <strong id="val-upper-bonus">0</strong></div>
                        <div>Gesamt oben: <strong id="val-upper-total" style="color: var(--color-green);">0</strong></div>
                    </div>
                </div>

                <!-- UNTERER TEIL -->
                <div class="kniffel-section card-box">
                    <div class="kniffel-section-header">
                        <h4>Unterer Teil (Kombinationen)</h4>
                        <span class="text-muted" style="font-size: 0.78rem;">Punkte oder Streichen</span>
                    </div>

                    <div class="kniffel-rows-grid">
                        <div class="kniffel-row js-category-row" data-cat="three_of_a_kind" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">Dreierpasch</span>
                                <span class="kniffel-cat-desc">Mind. 3 gleiche (alle Augen)</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-three_of_a_kind">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-three_of_a_kind">0</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="four_of_a_kind" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">Viererpasch</span>
                                <span class="kniffel-cat-desc">Mind. 4 gleiche (alle Augen)</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-four_of_a_kind">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-four_of_a_kind">0</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="full_house" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">Full House</span>
                                <span class="kniffel-cat-desc">3 gleiche + 2 gleiche (25 Pkt)</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-full_house">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-full_house">25</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="small_straight" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">Kleine Straße</span>
                                <span class="kniffel-cat-desc">4er-Folge (30 Pkt)</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-small_straight">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-small_straight">30</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="large_straight" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">Große Straße</span>
                                <span class="kniffel-cat-desc">5er-Folge (40 Pkt)</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-large_straight">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-large_straight">40</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="kniffel" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">Kniffel 🏆</span>
                                <span class="kniffel-cat-desc">5 gleiche (50 Pkt)</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-kniffel">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-kniffel">50</div>
                        </div>

                        <div class="kniffel-row js-category-row" data-cat="chance" role="button" tabindex="0">
                            <div class="kniffel-cat-info">
                                <span class="kniffel-cat-name">Chance</span>
                                <span class="kniffel-cat-desc">Alle Augen zählen</span>
                            </div>
                            <div class="kniffel-cat-score" id="val-chance">-</div>
                            <div class="kniffel-cat-preview hidden" id="prev-chance">0</div>
                        </div>
                    </div>

                    <!-- Zwischensumme unten -->
                    <div class="kniffel-subtotal-bar">
                        <div>Gesamt unten: <strong id="val-lower-total">0</strong></div>
                    </div>
                </div>

                <!-- ENDSUMME -->
                <div class="kniffel-grand-total-card">
                    <span>Gesamtergebnis:</span>
                    <strong id="val-grand-total">0 Pkt.</strong>
                </div>
            </section>
        </main>

        <!-- SIEGEREHRUNGS-OVERLAY (Spielende) -->
        <div id="game-over-modal" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
            <div class="hub-modal text-center">
                <div style="font-size: 3rem; margin-bottom: 0.5rem;">🎉</div>
                <h2 id="game-over-title">Spiel beendet!</h2>
                <p id="game-over-reason" class="text-muted" style="margin-bottom: 1.25rem;">Alle 13 Runden wurden gespielt.</p>
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
    <script src="../js/reactions.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="../js/kniffel.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

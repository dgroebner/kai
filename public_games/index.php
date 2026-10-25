<?php
require_once __DIR__ . '/../bootstrap.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quarxl - Spiele &amp; Würfel</title>
    <link rel="manifest" href="./manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="./android-chrome-192x192.png">
    <link rel="apple-touch-icon" href="./apple-touch-icon.png">
    <meta name="theme-color" content="#0f172a">
    <link rel="stylesheet" href="./css/games-hub.css?v=<?= APP_VERSION ?>">
</head>
<body class="games-hub-body">
    <div class="hub-container">
        <header class="hub-header">
            <h1>🎲 Quarxl</h1>
            <p class="hub-subtitle">Gesellschaftsspiele online oder als digitaler Spielblock am Tisch</p>
        </header>

        <!-- Dein Spielername & Schnellbeitritt -->
        <div class="card-box" style="margin-bottom: 1.75rem;">
            <div class="form-group" style="margin-bottom: 1rem;">
                <label for="hub-player-name">Dein Spielername:</label>
                <input type="text" id="hub-player-name" class="form-control" placeholder="z. B. Danny, Anja..." maxlength="25">
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

            <!-- Kniffel Kachel -->
            <div class="game-kachel" id="card-kniffel" role="button" tabindex="0">
                <div class="game-kachel-icon">🏆</div>
                <div class="game-kachel-content">
                    <h2>Kniffel</h2>
                    <p>Große &amp; kleine Straße, Full House und Kniffel. Mit intelligenter Live-Ausfüllhilfe.</p>
                </div>
                <span class="game-kachel-badge">1–8 Spieler</span>
            </div>

            <!-- Mau-Mau / Uno Kachel -->
            <div class="game-kachel" id="card-cards" role="button" tabindex="0">
                <div class="game-kachel-icon">🃏</div>
                <div class="game-kachel-content">
                    <h2>Mau-Mau / Uno</h2>
                    <p>Uno Klassik, Uno Extreme (mit animiertem Launcher) &amp; Mau-Mau. Live mit Freunden oder gegen Bots.</p>
                </div>
                <span class="game-kachel-badge">2–8 Spieler</span>
            </div>

            <!-- Shut the Box Kachel -->
            <div class="game-kachel" id="card-shut-the-box" role="button" tabindex="0">
                <div class="game-kachel-icon">📦</div>
                <div class="game-kachel-content">
                    <h2>Shut the Box</h2>
                    <p>Das 4-Spieler Klappbrett. Klappe 1 bis 10 um und erreiche die geringste Strafpunkte-Zahl über 10 Runden.</p>
                </div>
                <span class="game-kachel-badge">1–4 Spieler</span>
            </div>

            <!-- Loch 6 Kachel -->
            <div class="game-kachel" id="card-loch6" role="button" tabindex="0">
                <div class="game-kachel-icon">🕳️</div>
                <div class="game-kachel-content">
                    <h2>Loch 6</h2>
                    <p>Push-Your-Luck Würfelspiel mit funkelnden Mineral-Steinen. Werde alle Steine los und meide besetzte Mulden!</p>
                </div>
                <span class="game-kachel-badge">1–6 Spieler</span>
            </div>
        </div>

        <footer style="text-align: center; margin-top: 2rem; color: var(--text-muted); font-size: 0.8rem;">
            games.agent-smith.de &bull; v<?= APP_VERSION ?>
        </footer>
    </div>

    <!-- Modal: Raum erstellen -->
    <div id="modal-create-room" class="hub-modal-overlay hidden" role="dialog" aria-modal="true">
        <div class="hub-modal">
            <div class="hub-modal-header">
                <h3 class="hub-modal-title" id="create-modal-title">Neues Spiel erstellen</h3>
                <button type="button" class="hub-modal-close js-close-modal">&times;</button>
            </div>
            <form id="form-create-room">
                <input type="hidden" id="create-game-type" value="qwixx">
                
                <!-- Würfel-Optionen (für Qwixx & Kniffel) -->
                <div class="form-group" id="group-dice-modes">
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

                <!-- Kartenspiel-Optionen (für Cards) -->
                <div class="form-group hidden" id="group-card-modes">
                    <label>Regelwerk &amp; Variante:</label>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal; cursor: pointer;">
                            <input type="radio" name="card_game_mode" value="uno" checked>
                            <span><strong>Uno Klassik:</strong> 108 Karten mit +2, +4 Farbwahl, Richtungswechsel &amp; Aussetzen</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal; cursor: pointer;">
                            <input type="radio" name="card_game_mode" value="uno_extreme">
                            <span><strong>Uno Extreme:</strong> Mit mechanischem Launcher, Hit 2, Extreme Hit &amp; Discard All</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-weight: normal; cursor: pointer;">
                            <input type="radio" name="card_game_mode" value="mau_mau">
                            <span><strong>Mau-Mau:</strong> 32 Karten französisches Blatt mit 7 (2 Ziehen), Bube (Farbwahl) &amp; Ass (Aussetzen)</span>
                        </label>
                    </div>
                </div>

                <!-- Loch 6 Optionen -->
                <div class="form-group hidden" id="group-loch6-modes">
                    <label>Start-Steine pro Spieler:</label>
                    <div style="display: flex; flex-direction: column; gap: 0.45rem; margin-top: 0.25rem;">
                        <label style="display: flex; align-items: center; gap: 0.45rem; font-weight: normal; cursor: pointer;">
                            <input type="radio" name="loch6_stones" value="auto" checked>
                            <span><strong>Dynamisch (Empfohlen):</strong> Passt sich der Spielerzahl an (2 Sp: 15, 3 Sp: 10, 4 Sp: 8, 5 Sp: 6, 6 Sp: 5)</span>
                        </label>
                        <div style="display: flex; gap: 0.75rem; align-items: center; margin-top: 0.15rem; flex-wrap: wrap;">
                            <span style="font-size: 0.82rem; color: var(--text-muted);">Oder feste Anzahl:</span>
                            <label style="display: flex; align-items: center; gap: 0.25rem; font-weight: normal; cursor: pointer; font-size: 0.85rem;">
                                <input type="radio" name="loch6_stones" value="15">
                                <span>15</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.25rem; font-weight: normal; cursor: pointer; font-size: 0.85rem;">
                                <input type="radio" name="loch6_stones" value="12">
                                <span>12</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.25rem; font-weight: normal; cursor: pointer; font-size: 0.85rem;">
                                <input type="radio" name="loch6_stones" value="10">
                                <span>10</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.25rem; font-weight: normal; cursor: pointer; font-size: 0.85rem;">
                                <input type="radio" name="loch6_stones" value="8">
                                <span>8</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.25rem; font-weight: normal; cursor: pointer; font-size: 0.85rem;">
                                <input type="radio" name="loch6_stones" value="6">
                                <span>6</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 1rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <label for="quarxl-table-access-code" style="margin-bottom: 0;">Tisch-PIN (Host-Schutz):</label>
                        <span id="pin-saved-badge" class="hidden" style="font-size: 0.75rem; color: #22c55e; font-weight: 700;">✓ PIN gespeichert</span>
                    </div>
                    <input type="text"
                           id="quarxl-table-access-code"
                           name="quarxl_table_access_code"
                           class="form-control"
                           placeholder="PIN aus der Konfiguration"
                           required
                           autocomplete="off"
                           autocapitalize="off"
                           autocorrect="off"
                           spellcheck="false"
                           data-lpignore="true"
                           data-1p-ignore="true"
                           data-form-type="other"
                           style="margin-top: 0.25rem; -webkit-text-security: disc; text-security: disc;">
                    <small class="text-muted">Wird nach der ersten erfolgreichen Eingabe lokal im Browser gespeichert.</small>
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

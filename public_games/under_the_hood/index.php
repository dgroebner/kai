<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\GamesHub\UnderTheHood\HoodConfig;
use Kai\Tools\Shared\Security\Auth;

$csrfToken = Auth::csrfToken();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Under the Hood 🏎️ — Quarxl 3D Cockpit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#090d16">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="icon" type="image/png" href="../android-chrome-192x192.png">
    <link rel="manifest" href="../manifest.json">
    <link rel="apple-touch-icon" href="../apple-touch-icon.png">
    <link rel="stylesheet" href="./hood.css?v=<?= APP_VERSION ?>">
</head>
<body class="hood-body">

    <!-- 3D First-Person Cockpit & Street Canvas -->
    <div id="hood-canvas-container"></div>

    <!-- UI Overlay Layer -->
    <div class="hood-ui-layer">
        
        <!-- Header / Navigation Bar -->
        <header class="hood-topbar hood-interactive">
            <div style="display: flex; gap: 0.4rem; align-items: center;">
                <button type="button" class="hood-btn-icon" id="btn-leave" title="Zurück zu Quarxl">◀</button>
                <div class="hood-badge">
                    <span>🏎️</span>
                    <span id="room-badge-code">---</span>
                </div>
            </div>

            <div class="hood-badge hood-cash-badge">
                <span>💰</span>
                <span id="cash-badge-amount">1.500 $</span>
            </div>

            <!-- Blick-Steuerung & Ton -->
            <div class="hood-view-controls">
                <button type="button" class="hood-btn-icon" id="btn-look-road" title="Blick nach vorn auf die Straße">🛣️</button>
                <button type="button" class="hood-btn-icon" id="btn-look-plot" title="Blick aus dem Fenster aufs Grundstück">🏡</button>
                <button type="button" class="hood-btn-icon" id="btn-audio-toggle" title="Ton an/aus">🔊</button>
            </div>
        </header>

        <!-- Live Ticker Bar -->
        <div class="hood-ticker-bar hood-interactive">
            <span style="font-size: 1rem;">📢</span>
            <div class="hood-ticker-text" id="ticker-text">Du sitzt am Steuer. Lass die Motoren an!</div>
        </div>

        <!-- ==================================================================
             DAS ARMATURENBRETT & DAS MITTEL-NAVI (Cockpit-Console)
             ================================================================== -->
        <div class="hood-cockpit-console hood-interactive">
            
            <div class="hood-navi-screen">
                
                <!-- Navi Header -->
                <div class="hood-navi-header">
                    <div class="hood-navi-location">
                        <span>📍</span>
                        <div class="hood-navi-name" id="navi-name">Start &amp; Ziel</div>
                    </div>
                    <span class="hood-navi-zone-tag" id="navi-zone-tag" style="background: var(--hood-accent);">START</span>
                </div>

                <!-- Navi Daten-Anzeige für kaufbare Grundstücke -->
                <div class="hood-navi-stats" id="navi-stats-purchasable">
                    <div class="hood-navi-stat-box">
                        <div class="label">Kaufpreis</div>
                        <div class="val gold" id="navi-price">0 $</div>
                    </div>
                    <div class="hood-navi-stat-box">
                        <div class="label">Miete</div>
                        <div class="val" id="navi-rent">0 $</div>
                    </div>
                    <div class="hood-navi-stat-box">
                        <div class="label">Besitzer</div>
                        <div class="val" id="navi-owner" style="font-size: 0.85rem;">Frei</div>
                    </div>
                </div>

                <!-- Navi Anzeige für Ereignisse, Karten & Sonderfelder -->
                <div class="hood-navi-event-box" id="navi-event-box" style="display: none;">
                    <div class="hood-navi-event-icon" id="navi-event-icon">🗃️</div>
                    <div class="hood-navi-event-body">
                        <div class="hood-navi-event-title" id="navi-event-title">Handschuhfach</div>
                        <div class="hood-navi-event-desc" id="navi-event-desc">Greif ins Fach: Glück oder Bußgeld?</div>
                    </div>
                </div>

                <!-- Würfel-Cluster & Aktions-Buttons -->
                <div class="hood-dice-cluster" id="dice-display">
                    <div class="hood-die" id="die-1">?</div>
                    <div class="hood-die" id="die-2">?</div>
                </div>

                <!-- 1. Motor Start / Würfeln -->
                <button type="button" class="hood-btn-primary pulse" id="btn-roll">
                    <span>⚡</span> MOTOR START &amp; WÜRFELN
                </button>

                <!-- 2. Kaufen / Weiterfahren -->
                <div class="hood-btn-group" id="btn-group-buy" style="display: none;">
                    <button type="button" class="hood-btn-primary hood-btn-gold" id="btn-buy">
                        <span>🏡</span> KAUFEN
                    </button>
                    <button type="button" class="hood-btn-primary hood-btn-secondary" id="btn-pass">
                        <span>💨</span> WEITER
                    </button>
                </div>

                <!-- 3. Kaution zahlen -->
                <button type="button" class="hood-btn-primary hood-btn-gold" id="btn-bail" style="display: none;">
                    <span>💸</span> KAUTION ZAHLEN (50 $)
                </button>

                <!-- 4. Zug beenden -->
                <button type="button" class="hood-btn-primary" id="btn-end-turn" style="display: none;">
                    <span>👉</span> ZUG BEENDEN
                </button>

            </div>

        </div>

    </div>

    <!-- Detail Bottom Sheet für Grundstücke (Inspektion & Ausbau) -->
    <div class="hood-sheet-backdrop" id="sheet-backdrop">
        <div class="hood-sheet">
            <div class="hood-sheet-handle"></div>
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <h3 id="sheet-title" style="font-size: 1.3rem; margin-bottom: 0.2rem;">Grundstück</h3>
                    <div id="sheet-owner" style="font-size: 0.85rem; color: var(--hood-muted);">Besitzer: Niemand</div>
                </div>
                <button type="button" class="hood-btn-icon" id="sheet-close">✕</button>
            </div>

            <div class="hood-sheet-price-tag">
                <span class="amount" id="sheet-price">0 $</span>
                <span style="font-size: 0.8rem; color: var(--hood-muted);">Kaufpreis</span>
            </div>

            <div style="margin-top: 1rem; display: flex; flex-direction: column; gap: 0.5rem;">
                <button type="button" class="hood-btn-primary hood-btn-gold" id="sheet-btn-build" style="display: none;">
                    🏗️ HAUS / HOTEL BAUEN
                </button>
                <button type="button" class="hood-btn-primary hood-btn-secondary" id="sheet-btn-mortgage" style="display: none;">
                    🏦 HYPOTHEK TOGGELN
                </button>
            </div>
        </div>
    </div>

    <!-- Lobby / Raum-Erstellungs-Modal -->
    <div class="hood-modal-backdrop" id="modal-lobby">
        <div class="hood-modal">
            
            <!-- Formular: Raum neu anlegen -->
            <div id="lobby-create-form">
                <div class="hood-modal-header">
                    <h2 class="hood-modal-title">🏎️ Under the Hood</h2>
                </div>

                <!-- Modus-Auswahl: Hot-Seat vs. Online -->
                <div style="display: flex; gap: 0.5rem; margin-bottom: 1rem;">
                    <button type="button" class="hood-btn-primary" id="mode-tab-hotseat" style="flex: 1; padding: 0.6rem; font-size: 0.85rem;">
                        🛋️ Hot-Seat (1 PC)
                    </button>
                    <button type="button" class="hood-btn-primary hood-btn-secondary" id="mode-tab-online" style="flex: 1; padding: 0.6rem; font-size: 0.85rem;">
                        🌐 Online (Codes)
                    </button>
                </div>

                <div class="hood-form-group">
                    <label class="hood-label" for="input-player-name">Fahrer 1 Name</label>
                    <input type="text" id="input-player-name" class="hood-input" placeholder="Dein Spielername" maxlength="20">
                </div>

                <div class="hood-form-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <label class="hood-label" for="input-pin" style="margin-bottom: 0;">Tisch-PIN (Host-Schutz)</label>
                        <span id="pin-saved-badge" class="hidden" style="font-size: 0.75rem; color: #22c55e; font-weight: 700;">✓ PIN gespeichert</span>
                    </div>
                    <input type="password" id="input-pin" class="hood-input" placeholder="PIN aus der Konfiguration" value="">
                </div>

                <div class="hood-form-group">
                    <label class="hood-label">Farbe deines Wagens &amp; Zäune</label>
                    <div class="hood-color-picker">
                        <?php foreach (HoodConfig::PLAYER_COLORS as $idx => $c): ?>
                            <div class="hood-color-swatch <?= $idx === 0 ? 'selected' : '' ?>" style="background-color: <?= $c ?>;" data-color="<?= $c ?>"></div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="hood-form-group">
                    <label class="hood-label">Fahrzeug-Klasse</label>
                    <div class="hood-vehicle-picker">
                        <?php foreach (HoodConfig::VEHICLES as $key => $v): ?>
                            <div class="hood-vehicle-card <?= $key === 'sportscar' ? 'selected' : '' ?>" data-vehicle="<?= $key ?>">
                                <div class="icon"><?= $v['icon'] ?></div>
                                <div class="name"><?= $v['name'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="button" class="hood-btn-primary" id="btn-create-room" style="margin-top: 1rem;">
                    🏁 SPIEL ERSTELLEN
                </button>
            </div>

            <!-- Warteraum / Vor dem Start -->
            <div id="lobby-waiting-room" style="display: none;">
                <div class="hood-modal-header">
                    <h2 class="hood-modal-title">🏎️ Startaufstellung</h2>
                    <div class="hood-badge" id="lobby-room-code">---</div>
                </div>
                <p style="font-size: 0.85rem; color: var(--hood-muted); margin-bottom: 1rem;" id="lobby-room-info">
                    Füge weitere Mitspieler oder KI-Fahrer hinzu.
                </p>

                <ul id="lobby-players-list" style="list-style: none; margin-bottom: 1.25rem;"></ul>

                <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                    <!-- Hot-Seat Spieler hinzufügen -->
                    <div id="hotseat-add-box" style="display: none; background: #1e293b; padding: 0.75rem; border-radius: 12px; margin-bottom: 0.5rem;">
                        <input type="text" id="input-local-name" class="hood-input" placeholder="Name Mitspieler (z.B. Anna)" style="margin-bottom: 0.5rem;">
                        <button type="button" class="hood-btn-primary hood-btn-secondary" id="btn-add-local" style="padding: 0.6rem;">
                            ➕ Weiteren lokalen Spieler hinzufügen
                        </button>
                    </div>

                    <button type="button" class="hood-btn-primary hood-btn-secondary" id="btn-add-bot">
                        🤖 + KI-FAHRER HINZUFÜGEN
                    </button>
                    <button type="button" class="hood-btn-primary hood-btn-gold" id="btn-start-game">
                        🚦 MOTOREN STARTEN!
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Sieger-Modal -->
    <div class="hood-modal-backdrop" id="modal-winner">
        <div class="hood-modal" style="text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 0.5rem;">🏆</div>
            <h2 class="hood-modal-title" style="margin-bottom: 0.5rem;">SIEG IN DER HOOD!</h2>
            <p id="winner-text" style="color: var(--hood-gold); font-size: 1.1rem; font-weight: 700; margin-bottom: 1.5rem;"></p>
            <button type="button" class="hood-btn-primary" id="btn-restart-game">
                🔄 NOCHMAL SPIELEN
            </button>
        </div>
    </div>

    <!-- Three.js (lokal bereitgestellt für strikte CSP) -->
    <script src="../js/three.min.js?v=<?= APP_VERSION ?>"></script>
    <script src="./hood.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>

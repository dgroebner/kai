<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Security\Auth;

// Auth-Check — immer zuerst (erfordert nur gültigen Login)
Auth::requirePage();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spiele &amp; Werkzeuge - Kai</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container games-container">
    <header class="page-header">
        <h1>🎲 Spiele &amp; Werkzeuge</h1>
        <div class="page-header-actions">
            <a href="../index.php" class="btn btn-outline">&larr; Dashboard</a>
        </div>
    </header>

    <!-- Tab-Navigation -->
    <nav class="period-switcher games-tab-nav" aria-label="Werkzeugauswahl">
        <button type="button" class="btn js-games-tab-btn" data-tool="coin" id="tab-btn-coin">
            🪙 Münzwurf
        </button>
        <button type="button" class="btn btn-outline js-games-tab-btn" data-tool="dice" id="tab-btn-dice">
            🎲 Würfelbecher
        </button>
        <button type="button" class="btn btn-outline js-games-tab-btn" data-tool="city-country" id="tab-btn-city-country">
            🏙️ Stadt-Land-Fluss
        </button>
        <button type="button" class="btn btn-outline js-games-tab-btn" data-tool="hangman" id="tab-btn-hangman">
            🔤 Galgenraten
        </button>
    </nav>

    <main class="games-main">
        <!-- ============================================================== -->
        <!-- WERKZEUG 1: MÜNZWURF                                           -->
        <!-- ============================================================== -->
        <section id="tool-pane-coin" class="card games-tool-pane" aria-labelledby="tab-btn-coin">
            <div class="games-tool-header">
                <h2>Münzwurf</h2>
                <p class="text-muted">Schnelle, faire 50:50-Entscheidung bei Unstimmigkeiten oder zur Ermittlung des Startspielers.</p>
            </div>

            <div class="games-coin-stage">
                <div class="games-coin-wrapper js-coin-flip-trigger" role="button" tabindex="0" aria-label="Münze werfen">
                    <div class="games-coin" id="games-coin">
                        <div class="games-coin-face games-coin-front">
                            <span class="games-coin-icon">👑</span>
                            <span class="games-coin-text">KOPF</span>
                        </div>
                        <div class="games-coin-face games-coin-back">
                            <span class="games-coin-icon">🔢</span>
                            <span class="games-coin-text">ZAHL</span>
                        </div>
                    </div>
                </div>
                <div class="games-coin-result-label js-coin-result-label">Tippe auf die Münze oder den Button</div>
            </div>

            <div class="games-tool-actions">
                <button type="button" class="btn btn-primary btn-lg js-coin-flip-btn">🪙 Münze werfen</button>
            </div>

            <div class="games-stats-bar">
                <div class="games-stat-item">
                    <span class="games-stat-label">👑 Kopf:</span>
                    <strong class="games-stat-value" id="coin-stat-heads">0</strong>
                </div>
                <div class="games-stat-divider">|</div>
                <div class="games-stat-item">
                    <span class="games-stat-label">🔢 Zahl:</span>
                    <strong class="games-stat-value" id="coin-stat-tails">0</strong>
                </div>
                <div class="games-stat-divider">|</div>
                <div class="games-stat-item">
                    <span class="games-stat-label">Gesamt:</span>
                    <strong class="games-stat-value" id="coin-stat-total">0</strong>
                </div>
                <button type="button" class="btn btn-sm btn-outline js-coin-reset-stats" title="Zähler zurücksetzen">Zurücksetzen</button>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- WERKZEUG 2: WÜRFELBECHER                                       -->
        <!-- ============================================================== -->
        <section id="tool-pane-dice" class="card games-tool-pane hidden" aria-labelledby="tab-btn-dice">
            <div class="games-tool-header">
                <h2>Universeller Würfelbecher</h2>
                <p class="text-muted">Ersatz für physische Spielwürfel unterwegs (Kniffel, Mensch ärgere dich nicht, Brettspiele).</p>
            </div>

            <div class="games-dice-controls">
                <label class="games-control-label" id="dice-count-label">Anzahl Würfel:</label>
                <div class="games-dice-count-selector" role="group" aria-labelledby="dice-count-label">
                    <button type="button" class="btn btn-sm js-dice-count-btn active" data-count="1">1</button>
                    <button type="button" class="btn btn-sm btn-outline js-dice-count-btn" data-count="2">2</button>
                    <button type="button" class="btn btn-sm btn-outline js-dice-count-btn" data-count="3">3</button>
                    <button type="button" class="btn btn-sm btn-outline js-dice-count-btn" data-count="4">4</button>
                    <button type="button" class="btn btn-sm btn-outline js-dice-count-btn" data-count="5">5</button>
                    <button type="button" class="btn btn-sm btn-outline js-dice-count-btn" data-count="6">6</button>
                </div>
            </div>

            <div class="games-dice-stage js-dice-stage" aria-label="Würfelbereich">
                <div class="games-dice-hint">Tippe auf einen Würfel, um ihn für die nächste Runde zu fixieren (Kniffel).</div>
                <div class="games-dice-grid" id="games-dice-grid">
                    <!-- Würfel werden dynamisch generiert -->
                </div>
                <div class="games-dice-sum-display" id="games-dice-sum-display">
                    Augensumme: <strong id="games-dice-sum-val">6</strong>
                </div>
            </div>

            <div class="games-tool-actions">
                <button type="button" class="btn btn-primary btn-lg js-dice-roll-btn">🎲 Würfeln</button>
                <button type="button" class="btn btn-outline js-dice-unfix-all-btn hidden" id="btn-dice-unfix-all" title="Alle Fixierungen aufheben">🔓 Alle lösen</button>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- WERKZEUG 3: STADT-LAND-FLUSS                                   -->
        <!-- ============================================================== -->
        <section id="tool-pane-city-country" class="card games-tool-pane hidden" aria-labelledby="tab-btn-city-country">
            <div class="games-tool-header">
                <h2>Stadt-Land-Fluss Buchstabenselektor</h2>
                <p class="text-muted">Gerechte, manipulationsfreie Buchstabenziehung ohne fehleranfälliges stummes Aufsagen des Alphabets.</p>
            </div>

            <div class="games-letter-settings">
                <label class="games-checkbox-label">
                    <input type="checkbox" id="chk-exclude-rare" class="js-letter-filter">
                    <span>Schwierige Buchstaben ausschließen (Q, X, Y)</span>
                </label>
            </div>

            <div class="games-letter-stage">
                <div class="games-letter-display" id="games-letter-display" aria-live="polite">
                    <span class="games-letter-hero" id="games-current-letter">?</span>
                </div>
                <div class="games-letter-status" id="games-letter-status">
                    Klicke auf „Nächster Buchstabe“, um die Runde zu starten.
                </div>
            </div>

            <div class="games-tool-actions">
                <button type="button" class="btn btn-primary btn-lg js-letter-next-btn" id="btn-letter-next">🔤 Nächster Buchstabe</button>
                <button type="button" class="btn btn-outline js-letter-reset-btn" id="btn-letter-reset">🔄 Runde neu starten</button>
            </div>

            <!-- Alphabet-Raster Historie -->
            <div class="games-alphabet-section">
                <div class="games-alphabet-header">
                    <div>
                        <h3>Alphabet-Übersicht</h3>
                        <div class="games-alphabet-subhint text-muted">Buchstaben anklicken, um sie für diese Runde auszuschließen</div>
                    </div>
                    <span class="text-muted" id="games-alphabet-counter">26 verbleibend</span>
                </div>
                <div class="games-alphabet-grid" id="games-alphabet-grid" aria-label="Alphabet-Raster">
                    <!-- A bis Z dynamisch markiert -->
                </div>
            </div>
        </section>

        <!-- ============================================================== -->
        <!-- WERKZEUG 4: GALGENRATEN (HANGMAN)                             -->
        <!-- ============================================================== -->
        <section id="tool-pane-hangman" class="card games-tool-pane hidden" aria-labelledby="tab-btn-hangman">
            <div class="games-tool-header">
                <h2>Galgenraten</h2>
                <p class="text-muted">Interaktives Wortratespiel für 2 bis 6 Spieler: Duell, kooperativ oder mit KI-Spielleiter.</p>
            </div>

            <!-- Modus-Auswahl & Spielerleiste -->
            <div class="hangman-top-bar">
                <div class="hangman-mode-switcher" role="group" aria-label="Spielmodus">
                    <button type="button" class="btn btn-sm btn-outline js-hangman-mode-btn" data-mode="pass_play" title="Ein Spieler tippt ein Wort verdeckt ein, die anderen raten">👥 Duell (Pass &amp; Play)</button>
                    <button type="button" class="btn btn-sm js-hangman-mode-btn active" data-mode="offline" title="Gruppe rät gemeinsam gegen über 200 kindgerechte Offline-Wörter">🎒 Kooperativ (Offline)</button>
                    <button type="button" class="btn btn-sm btn-outline js-hangman-mode-btn" data-mode="ai" title="Gemini erfindet kindgerechte Wörter samt Umschreibungen">🤖 KI-Spielleiter</button>
                </div>

                <div class="hangman-top-actions">
                    <button type="button" class="btn btn-sm btn-outline js-hangman-open-setup-btn" title="Spieler und Namen verwalten">⚙️ Spieler (<span id="hangman-players-count-label">Setup</span>)</button>
                    <button type="button" class="btn btn-sm btn-outline js-hangman-reset-scores-btn" title="Punktestände zurücksetzen">🔄 Punkte 0</button>
                </div>
            </div>

            <!-- Spieler-Scoreboard & Zug-Anzeige -->
            <div class="hangman-players-bar" id="hangman-players-bar" aria-label="Mitspieler und Punktestände">
                <!-- Dynamisch generiert -->
            </div>

            <!-- Kategorie-Badge & Status-Meldung -->
            <div class="hangman-meta-bar">
                <span class="badge badge-info hangman-category-badge" id="hangman-category-badge">Thema: Allgemein</span>
                <button type="button" class="btn btn-sm btn-warning hidden" id="btn-hangman-request-hint">💡 Tipp anfordern</button>
            </div>

            <!-- Tipp-Box -->
            <div class="hangman-hint-box hidden" id="hangman-hint-box" role="region" aria-live="polite">
                <span class="hangman-hint-icon">💡</span>
                <span id="hangman-hint-text" class="hangman-hint-text"></span>
            </div>

            <!-- SVG Galgen-Stage & Wort-Slots -->
            <div class="hangman-stage">
                <!-- Vektorbasierter SVG-Galgen mit 8 Stufen -->
                <div class="hangman-svg-container">
                    <svg viewBox="0 0 200 220" class="hangman-svg" aria-label="Galgen-Grafik">
                        <!-- Stufe 1: Boden / Hügel -->
                        <line x1="20" y1="200" x2="180" y2="200" stroke="#64748b" stroke-width="6" stroke-linecap="round" class="hangman-part" data-step="1" />
                        <!-- Stufe 2: Mast & Bodenstütze -->
                        <line x1="60" y1="200" x2="60" y2="30" stroke="#94a3b8" stroke-width="6" stroke-linecap="round" class="hangman-part" data-step="2" />
                        <line x1="40" y1="200" x2="60" y2="175" stroke="#94a3b8" stroke-width="4" stroke-linecap="round" class="hangman-part" data-step="2" />
                        <line x1="80" y1="200" x2="60" y2="175" stroke="#94a3b8" stroke-width="4" stroke-linecap="round" class="hangman-part" data-step="2" />
                        <!-- Stufe 3: Querbalken & Eckstrebe -->
                        <line x1="57" y1="30" x2="150" y2="30" stroke="#94a3b8" stroke-width="6" stroke-linecap="round" class="hangman-part" data-step="3" />
                        <line x1="60" y1="65" x2="95" y2="30" stroke="#94a3b8" stroke-width="4" stroke-linecap="round" class="hangman-part" data-step="3" />
                        <!-- Stufe 4: Seil / Schlinge -->
                        <line x1="145" y1="30" x2="145" y2="65" stroke="#f59e0b" stroke-width="3" stroke-dasharray="4,2" class="hangman-part" data-step="4" />
                        <!-- Stufe 5: Kopf -->
                        <circle cx="145" cy="80" r="15" stroke="#ef4444" stroke-width="3.5" fill="none" class="hangman-part" data-step="5" />
                        <!-- Stufe 6: Rumpf -->
                        <line x1="145" y1="95" x2="145" y2="140" stroke="#ef4444" stroke-width="3.5" stroke-linecap="round" class="hangman-part" data-step="6" />
                        <!-- Stufe 7: Arme -->
                        <line x1="145" y1="108" x2="125" y2="125" stroke="#ef4444" stroke-width="3.5" stroke-linecap="round" class="hangman-part" data-step="7" />
                        <line x1="145" y1="108" x2="165" y2="125" stroke="#ef4444" stroke-width="3.5" stroke-linecap="round" class="hangman-part" data-step="7" />
                        <!-- Stufe 8: Beine -->
                        <line x1="145" y1="140" x2="128" y2="175" stroke="#ef4444" stroke-width="3.5" stroke-linecap="round" class="hangman-part" data-step="8" />
                        <line x1="145" y1="140" x2="162" y2="175" stroke="#ef4444" stroke-width="3.5" stroke-linecap="round" class="hangman-part" data-step="8" />
                    </svg>
                </div>

                <!-- Wort-Buchstaben Slots -->
                <div class="hangman-word-slots" id="hangman-word-slots" aria-label="Gesuchtes Wort">
                    <!-- Dynamische Slots -->
                </div>
            </div>

            <!-- Status Banner (Gewonnen / Verloren) -->
            <div id="hangman-status-banner" class="hangman-status-banner hidden" role="alert"></div>

            <!-- Bildschirmtastatur -->
            <div class="hangman-keyboard" id="hangman-keyboard" aria-label="Buchstabentastatur">
                <!-- Tasten A bis Z -->
            </div>

            <div class="games-tool-actions">
                <button type="button" class="btn btn-primary btn-lg js-hangman-new-game-btn">🔄 Nächstes Wort / Neue Runde</button>
            </div>
        </section>
    </main>

    <!-- Modal für Bestätigung des Runden-Neustarts (Stadt-Land-Fluss) -->
    <div id="games-confirm-modal" class="games-modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="games-confirm-title">
        <div class="games-modal-dialog">
            <div class="games-modal-icon">🔄</div>
            <h3 id="games-confirm-title" class="games-modal-title">Runde neu starten?</h3>
            <p class="games-modal-desc">
                Der bisherige Fortschritt aller gezogenen Buchstaben wird zurückgesetzt und das Alphabet steht wieder vollständig zur Verfügung.
            </p>
            <div class="games-modal-checkbox">
                <label class="games-checkbox-label">
                    <input type="checkbox" id="chk-games-suppress-confirm">
                    <span>In dieser Sitzung nicht mehr nachfragen</span>
                </label>
            </div>
            <div class="games-modal-actions">
                <button type="button" class="btn btn-outline js-games-confirm-cancel">Abbrechen</button>
                <button type="button" class="btn btn-primary js-games-confirm-ok">Ja, neu starten</button>
            </div>
        </div>
    </div>

    <!-- Modal für Spieler-Setup (Hangman) -->
    <div id="hangman-setup-modal" class="games-modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="hangman-setup-title">
        <div class="games-modal-dialog">
            <div class="games-modal-icon">👥</div>
            <h3 id="hangman-setup-title" class="games-modal-title">Mitspieler einstellen</h3>
            <p class="games-modal-desc">Wähle die Anzahl der Spieler (2 bis 6) und vergib eigene Spielernamen.</p>
            
            <div class="hangman-setup-counts" role="group" aria-label="Spieleranzahl">
                <button type="button" class="btn btn-sm js-setup-count-btn active" data-count="2">2 Spieler</button>
                <button type="button" class="btn btn-sm btn-outline js-setup-count-btn" data-count="3">3 Spieler</button>
                <button type="button" class="btn btn-sm btn-outline js-setup-count-btn" data-count="4">4 Spieler</button>
                <button type="button" class="btn btn-sm btn-outline js-setup-count-btn" data-count="5">5 Spieler</button>
                <button type="button" class="btn btn-sm btn-outline js-setup-count-btn" data-count="6">6 Spieler</button>
            </div>

            <div class="hangman-setup-names-list" id="hangman-setup-names-list">
                <!-- Dynamische Inputs für Spielernamen -->
            </div>

            <div class="games-modal-actions">
                <button type="button" class="btn btn-outline js-setup-close-btn">Schließen</button>
                <button type="button" class="btn btn-primary js-setup-save-btn">Speichern</button>
            </div>
        </div>
    </div>

    <!-- Modal für verdeckte Worterfassung (Hangman Duell Pass-and-Play) -->
    <div id="hangman-word-input-modal" class="games-modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="hangman-giver-heading">
        <div class="games-modal-dialog">
            <div class="games-modal-icon">🤫</div>
            <h3 id="hangman-giver-heading" class="games-modal-title">Wort eingeben (<span id="hangman-giver-name">Spieler 1</span>)</h3>
            <p class="games-modal-desc">Gib verdeckt ein Wort ein, das die anderen erraten müssen (4 bis 14 Buchstaben).</p>

            <div class="hangman-word-form-group">
                <div class="hangman-password-wrap">
                    <input type="password" id="hangman-custom-word" class="form-control" placeholder="Geheimes Wort eingeben..." autocomplete="off" maxlength="14">
                    <button type="button" class="btn btn-sm btn-outline js-toggle-word-visibility" title="Wort anzeigen/verstecken">👁️</button>
                </div>
                <input type="text" id="hangman-custom-category" class="form-control" placeholder="Thema / Kategorie (z.B. Tier, Sport)..." maxlength="25">
            </div>

            <div class="games-modal-actions">
                <button type="button" class="btn btn-primary js-word-modal-ok">Fertig &amp; Wort sperren</button>
            </div>
        </div>
    </div>

    <footer class="app-footer">
        <div>kai v<?= APP_VERSION ?></div>
    </footer>
</div>

<script src="../js/http.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/games.js?v=<?= APP_VERSION ?>" defer></script>
<script src="../js/games-hangman.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>


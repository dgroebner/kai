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
    </main>

    <!-- Modal für Bestätigung des Runden-Neustarts -->
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

    <footer class="app-footer">
        <div>kai v<?= APP_VERSION ?></div>
    </footer>
</div>

<script src="../js/http.js?v=<?= APP_VERSION ?>"></script>
<script src="../js/games.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

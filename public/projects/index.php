<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Projects\ProjectsRepository;
use Kai\Tools\Projects\Security\ProjectsAuth;

// Authentifizierung erzwingen (leitet bei Fehlen nach login.php um)
ProjectsAuth::requirePage();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userId = ProjectsAuth::getUserId();
$userEmail = ProjectsAuth::getUserEmail();
$userName = $_SESSION['proj_user_name'] ?? '';
$userState = $_SESSION['proj_user_state'] ?? 'SN';

$repo = new ProjectsRepository();
$projects = $repo->getProjects($userId);
$currentDate = date('Y-m-d');
$defaultProject = $repo->getDefaultProjectForDate($userId, $currentDate);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="csrf-token" content="<?= ProjectsAuth::csrfToken() ?>">
    <title>Projects — Projektzeiterfassung</title>
    
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0284c7">
    <link rel="stylesheet" href="styles.css?v=<?= APP_VERSION ?>">

    <script src="http.js?v=<?= APP_VERSION ?>" defer></script>
    <script src="projects.js?v=<?= APP_VERSION ?>" defer></script>
</head>
<body>
    <!-- App Header -->
    <header class="proj-header">
        <a href="index.php" class="proj-brand">
            <span style="font-size: 20px;">⏱️</span>
            <span class="proj-brand-title">Projects</span>
            <span class="proj-brand-badge"><?= htmlspecialchars($userState) ?></span>
        </a>

        <div class="proj-header-actions">
            <!-- Hauptprojekt Switcher Button -->
            <button type="button" class="proj-btn proj-btn-ghost proj-btn-sm" data-open-modal="modal-default-project">
                <span>Haupt:</span>
                <strong style="color: <?= htmlspecialchars($defaultProject['color'] ?? '#0284c7') ?>;">
                    <?= htmlspecialchars($defaultProject['short_code'] ?? 'Keines') ?>
                </strong>
                <span>▾</span>
            </button>

            <!-- Projekte verwalten Button -->
            <button type="button" class="proj-btn proj-btn-ghost proj-btn-sm" id="btn-header-manage-projects" data-open-modal="modal-manage-projects" title="Projekte verwalten & bearbeiten">
                📁 Projekte
            </button>

            <!-- Profil Modal Button -->
            <button type="button" class="proj-btn proj-btn-ghost proj-btn-sm" data-open-modal="modal-profile" title="Profil & Bundesland">
                ⚙️ <?= htmlspecialchars($userName ?: explode('@', $userEmail)[0]) ?>
            </button>

            <?php if (ProjectsAuth::isSuperAdmin()): ?>
                <a href="admin.php" class="proj-btn proj-btn-ghost proj-btn-sm" title="Administration">
                    🛠️ Admin
                </a>
            <?php endif; ?>

            <!-- Logout Button -->
            <button type="button" id="btn-logout" class="proj-btn proj-btn-ghost proj-btn-sm" title="Abmelden">
                🚪
            </button>
        </div>
    </header>

    <main class="proj-container">
        <!-- HERO: QUICK COMMAND & LIVE TAGESBALKEN -->
        <section class="proj-card proj-hero">
            <div class="proj-card-header">
                <div>
                    <h2 class="proj-card-title">Tagesbuchung</h2>
                    <div id="day-status-title" style="font-size: 13px; color: var(--proj-text-muted); margin-top: 2px;">
                        Lade Tagesdaten...
                    </div>
                </div>

                <div style="display: flex; gap: 8px;">
                    <input type="date" id="selected-date-picker" value="<?= $currentDate ?>"
                           style="padding: 6px 10px; border-radius: var(--proj-radius); border: 1px solid var(--proj-border); background: var(--proj-card-bg); color: var(--proj-text);">
                    <button type="button" class="proj-btn proj-btn-secondary proj-btn-sm" data-open-modal="modal-absence">
                        🌴 Abwesenheit
                    </button>
                </div>
            </div>

            <!-- Quick Command Eingabezeile -->
            <div class="proj-quick-bar">
                <input type="text" id="quick-command" class="proj-quick-input" autofocus
                       placeholder="Schnelleingabe: KÜRZEL STUNDEN [KOMMENTAR] — z. B. SUP 1.5 Daily Standup">
                <button type="button" id="quick-submit-btn" class="proj-btn proj-btn-primary">
                    Buchen
                </button>
            </div>

            <!-- Live-Tagesbalken (8,0h) -->
            <div class="proj-bar-container">
                <div class="proj-bar-labels">
                    <span>Tagesverteilung (8,0h)</span>
                    <span id="bar-percentage-label"></span>
                </div>
                <div id="day-live-bar" class="proj-live-bar">
                    <!-- Segmente werden via JS dynamisch erzeugt -->
                </div>
            </div>

            <!-- Ausnahmen-Liste des gewählten Tages -->
            <div style="margin-top: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: var(--proj-text-muted); margin-bottom: 8px;">
                    Gebuchte Abweichungen heute:
                </div>
                <div id="day-exceptions-list">
                    <!-- Dynamisch via JS -->
                </div>
            </div>
        </section>

        <!-- MONATSMATRIX / WOCHENANSICHT -->
        <section class="proj-card">
            <div class="proj-card-header" style="flex-wrap: wrap; gap: 12px;">
                <div>
                    <h2 class="proj-card-title">Monatsübersicht</h2>
                    <div id="matrix-summary" style="font-size: 13px; color: var(--proj-text-muted); margin-top: 2px;">
                        Lade Matrix...
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <!-- Ansichts-Umschalter -->
                    <div style="display: inline-flex; background: rgba(0,0,0,0.25); padding: 2px; border-radius: 6px; border: 1px solid var(--proj-border);">
                        <button type="button" id="btn-view-week" class="proj-btn proj-btn-sm proj-view-btn active" style="border: none; padding: 4px 10px; font-size: 12px;">
                            📅 Wochen
                        </button>
                        <button type="button" id="btn-view-matrix" class="proj-btn proj-btn-sm proj-view-btn" style="border: none; padding: 4px 10px; font-size: 12px; background: transparent; color: var(--proj-text-muted);">
                            📊 31-Tage
                        </button>
                    </div>

                    <button type="button" id="matrix-prev-month" class="proj-btn proj-btn-secondary proj-btn-sm">◀</button>
                    <span id="matrix-title" style="font-weight: 700; font-size: 14px; min-width: 120px; text-align: center;"></span>
                    <button type="button" id="matrix-next-month" class="proj-btn proj-btn-secondary proj-btn-sm">▶</button>
                    <button type="button" class="proj-btn proj-btn-secondary proj-btn-sm" data-open-modal="modal-manage-projects" title="Projekte bearbeiten & archivieren">
                        📁 Projekte
                    </button>
                    <button type="button" class="proj-btn proj-btn-primary proj-btn-sm" id="btn-open-create-project" data-open-modal="modal-project-form">
                        + Projekt
                    </button>
                </div>
            </div>

            <!-- CONTAINER 1: WOCHENANSICHT (KOMPAKT) -->
            <div id="view-week-container">
                <div class="proj-week-wrapper">
                    <table id="week-table" class="proj-week-table">
                        <!-- Wird via JS gerendert -->
                    </table>
                </div>

                <!-- Projekt-Aufteilung des Monats -->
                <div style="margin-top: 16px;">
                    <div style="font-size: 13px; font-weight: 700; color: var(--proj-text-muted); margin-bottom: 8px;">
                        Projektverteilung im Monat:
                    </div>
                    <div id="project-summary-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px;">
                        <!-- Wird via JS gerendert -->
                    </div>
                </div>

                <div style="font-size: 12px; color: var(--proj-text-muted); margin-top: 12px;">
                    💡 <em>Klicke auf einen Tag im Kalender, um diesen oben als Tagesbuchung zu öffnen.</em>
                </div>
            </div>

            <!-- CONTAINER 2: 31-TAGE MATRIX -->
            <div id="view-matrix-container" style="display: none;">
                <div class="proj-matrix-wrapper">
                    <table id="matrix-table" class="proj-matrix-table">
                        <!-- Wird vollständig via JS gerendert -->
                    </table>
                </div>
                
                <div style="font-size: 12px; color: var(--proj-text-muted); margin-top: 10px;">
                    💡 <em>Klicke auf eine Zelle, um den exakten Stundenwert in die Zwischenablage zu kopieren.</em>
                </div>
            </div>
        </section>
    </main>

    <footer style="text-align: center; padding: 24px 0 16px; font-size: 11px; color: var(--proj-text-muted, #94a3b8);">
        Projects v<?= APP_VERSION ?>
    </footer>

    <!-- -------------------------------------------------------------
     * MODAL: HAUPTPROJEKT WECHSELN
     * ------------------------------------------------------------- -->
    <div id="modal-default-project" class="proj-modal-backdrop" style="display: none;">
        <div class="proj-modal-card">
            <div class="proj-modal-header">
                <h3 class="proj-modal-title">Hauptprojekt festlegen</h3>
                <button type="button" class="proj-modal-close" data-close-modal>✕</button>
            </div>
            <form id="form-default-project">
                <div class="proj-form-group">
                    <label for="def-project-id">Projekt</label>
                    <select id="def-project-id" name="project_id" required>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= ($defaultProject && (int)$defaultProject['project_id'] === (int)$p['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['short_code']) ?> — <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="proj-form-group">
                    <label for="def-mode">Gültigkeit</label>
                    <select id="def-mode" name="mode" required>
                        <option value="today">Nur am ausgewählten Tag</option>
                        <option value="permanent" selected>Dauerhaft (ab ausgewähltem Tag)</option>
                        <option value="until">Bis bestimmtes Datum</option>
                    </select>
                </div>

                <div class="proj-form-group" id="group-valid-to" style="display: none;">
                    <label for="def-valid-to">Gültig bis</label>
                    <input type="date" id="def-valid-to" name="valid_to" value="<?= $currentDate ?>">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="proj-btn proj-btn-secondary" data-close-modal>Abbrechen</button>
                    <button type="submit" class="proj-btn proj-btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- -------------------------------------------------------------
     * MODAL: ABWESENHEIT BUCHEN (URLAUB / KRANKHEIT)
     * ------------------------------------------------------------- -->
    <div id="modal-absence" class="proj-modal-backdrop" style="display: none;">
        <div class="proj-modal-card">
            <div class="proj-modal-header">
                <h3 class="proj-modal-title">Abwesenheit buchen</h3>
                <button type="button" class="proj-modal-close" data-close-modal>✕</button>
            </div>
            <form id="form-absence-range">
                <div class="proj-form-group">
                    <label for="abs-type">Art der Abwesenheit</label>
                    <select id="abs-type" name="type" required>
                        <option value="vacation" selected>🌴 Erholungsurlaub</option>
                        <option value="sick">🤒 Krankheit / AU</option>
                        <option value="special_leave">⭐ Sonderurlaub</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="proj-form-group">
                        <label for="abs-start">Von</label>
                        <input type="date" id="abs-start" name="start_date" value="<?= $currentDate ?>" required>
                    </div>
                    <div class="proj-form-group">
                        <label for="abs-end">Bis (inklusive)</label>
                        <input type="date" id="abs-end" name="end_date" value="<?= $currentDate ?>" required>
                    </div>
                </div>

                <div class="proj-form-group">
                    <label for="abs-hours">Tages-Stunden</label>
                    <select id="abs-hours" name="hours" required>
                        <option value="8.0" selected>Ganzer Tag (8,0 Stunden)</option>
                        <option value="4.0">Halber Tag (4,0 Stunden)</option>
                    </select>
                </div>

                <div class="proj-form-group">
                    <label for="abs-comment">Kommentar (optional)</label>
                    <input type="text" id="abs-comment" name="comment" placeholder="z. B. Sommerurlaub">
                </div>

                <p style="font-size: 12px; color: var(--proj-text-muted); margin-bottom: 16px;">
                    💡 Wochenenden und Feiertage in deinem Bundesland (<?= htmlspecialchars($userState) ?>) werden automatisch übersprungen.
                </p>

                <div style="display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="proj-btn proj-btn-secondary" data-close-modal>Abbrechen</button>
                    <button type="submit" class="proj-btn proj-btn-primary">Eintragen</button>
                </div>
            </form>
        </div>
    </div>

    <!-- -------------------------------------------------------------
     * MODAL: BENUTZERPROFIL & BUNDESLAND (SN / HH)
     * ------------------------------------------------------------- -->
    <div id="modal-profile" class="proj-modal-backdrop" style="display: none;">
        <div class="proj-modal-card">
            <div class="proj-modal-header">
                <h3 class="proj-modal-title">Benutzerprofil & Einstellungen</h3>
                <button type="button" class="proj-modal-close" data-close-modal>✕</button>
            </div>
            <form id="form-profile">
                <div class="proj-form-group">
                    <label for="prof-name">Anzeigename</label>
                    <input type="text" id="prof-name" name="name" value="<?= htmlspecialchars($userName) ?>" placeholder="Dein Name">
                </div>

                <div class="proj-form-group">
                    <label for="prof-state">Bundesland für gesetzliche Feiertage</label>
                    <select id="prof-state" name="state_code" required>
                        <option value="SN" <?= ($userState === 'SN') ? 'selected' : '' ?>>Sachsen (SN) — inkl. Buß- und Bettag</option>
                        <option value="HH" <?= ($userState === 'HH') ? 'selected' : '' ?>>Hamburg (HH) — ohne Buß- und Bettag</option>
                    </select>
                    <div style="font-size: 12px; color: var(--proj-text-muted); margin-top: 4px;">
                        Reformationstag (31.10.) gilt in Sachsen und Hamburg gleichermaßen als Feiertag.
                    </div>
                </div>

                <div class="proj-form-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="reminder_enabled" value="1" style="width: auto;">
                        <span>Feierabend-Push-Erinnerung aktivieren</span>
                    </label>
                </div>

                <div class="proj-form-group">
                    <label for="prof-time">Erinnerungs-Uhrzeit</label>
                    <input type="time" id="prof-time" name="reminder_time" value="16:30">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="proj-btn proj-btn-secondary" data-close-modal>Abbrechen</button>
                    <button type="submit" class="proj-btn proj-btn-primary">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- -------------------------------------------------------------
     * MODAL: PROJEKTE VERWALTEN (BEARBEITEN, ARCHIVIEREN)
     * ------------------------------------------------------------- -->
    <div id="modal-manage-projects" class="proj-modal-backdrop" style="display: none;">
        <div class="proj-modal-card" style="max-width: 680px;">
            <div class="proj-modal-header">
                <div>
                    <h3 class="proj-modal-title">📁 Projektverwaltung</h3>
                    <div style="font-size: 13px; color: var(--proj-text-muted); margin-top: 2px;">
                        Bestehende Projekte anpassen oder archivieren
                    </div>
                </div>
                <button type="button" class="proj-modal-close" data-close-modal>✕</button>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 10px; flex-wrap: wrap;">
                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; color: var(--proj-text-muted); user-select: none;">
                    <input type="checkbox" id="manage-show-archived" style="width: auto;">
                    <span>Archivierte Projekte anzeigen</span>
                </label>
                <button type="button" class="proj-btn proj-btn-primary proj-btn-sm" id="btn-manage-add-project">
                    + Neues Projekt anlegen
                </button>
            </div>

            <div id="manage-projects-list" style="max-height: 420px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                <!-- Wird via JS gerendert -->
            </div>

            <div style="display: flex; justify-content: flex-end; margin-top: 18px;">
                <button type="button" class="proj-btn proj-btn-secondary" data-close-modal>Schließen</button>
            </div>
        </div>
    </div>

    <!-- -------------------------------------------------------------
     * MODAL: PROJEKT ANLEGEN / BEARBEITEN
     * ------------------------------------------------------------- -->
    <div id="modal-project-form" class="proj-modal-backdrop" style="display: none;">
        <div class="proj-modal-card">
            <div class="proj-modal-header">
                <h3 class="proj-modal-title" id="project-form-title">Neues Projekt anlegen</h3>
                <button type="button" class="proj-modal-close" data-close-modal>✕</button>
            </div>
            <form id="form-project">
                <input type="hidden" id="p-id" name="id" value="">

                <div class="proj-form-group">
                    <label for="p-name">Projektname</label>
                    <input type="text" id="p-name" name="name" required placeholder="z. B. SU / WE Leasman Toolbox">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="proj-form-group">
                        <label for="p-short">Kürzel (Quick-Command)</label>
                        <input type="text" id="p-short" name="short_code" required maxlength="12" placeholder="z. B. LM">
                    </div>
                    <div class="proj-form-group">
                        <label for="p-color">Farbe</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="color" id="p-color" name="color" value="#0284c7" class="proj-color-input" style="width: 50px; flex-shrink: 0;">
                            <input type="text" id="p-color-hex" value="#0284c7" maxlength="7" style="font-family: monospace; text-transform: uppercase;">
                        </div>
                    </div>
                </div>

                <!-- Schnellauswahl beliebter Projektfarben -->
                <div style="margin-top: -6px; margin-bottom: 14px;">
                    <div style="font-size: 11px; color: var(--proj-text-muted); margin-bottom: 6px;">Farbpalette:</div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button type="button" class="proj-color-preset" data-color="#0284c7" style="background:#0284c7; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Blau"></button>
                        <button type="button" class="proj-color-preset" data-color="#10b981" style="background:#10b981; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Grün"></button>
                        <button type="button" class="proj-color-preset" data-color="#f59e0b" style="background:#f59e0b; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Orange"></button>
                        <button type="button" class="proj-color-preset" data-color="#ef4444" style="background:#ef4444; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Rot"></button>
                        <button type="button" class="proj-color-preset" data-color="#8b5cf6" style="background:#8b5cf6; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Lila"></button>
                        <button type="button" class="proj-color-preset" data-color="#ec4899" style="background:#ec4899; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Pink"></button>
                        <button type="button" class="proj-color-preset" data-color="#06b6d4" style="background:#06b6d4; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Türkis"></button>
                        <button type="button" class="proj-color-preset" data-color="#eab308" style="background:#eab308; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Gelb"></button>
                        <button type="button" class="proj-color-preset" data-color="#64748b" style="background:#64748b; width:22px; height:22px; border-radius:50%; border:2px solid rgba(255,255,255,0.2); cursor:pointer;" title="Schiefer"></button>
                    </div>
                </div>

                <div class="proj-form-group">
                    <label for="p-code">Projekt- / PSP-Code (optional)</label>
                    <input type="text" id="p-code" name="external_code" placeholder="z. B. PRJ-2026-004">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="proj-btn proj-btn-secondary" data-close-modal>Abbrechen</button>
                    <button type="submit" class="proj-btn proj-btn-primary" id="project-form-submit">Speichern</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

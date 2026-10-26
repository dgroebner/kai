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

        <!-- MONATSMATRIX -->
        <section class="proj-card">
            <div class="proj-card-header">
                <div>
                    <h2 class="proj-card-title">Monatsmatrix</h2>
                    <div id="matrix-summary" style="font-size: 13px; color: var(--proj-text-muted); margin-top: 2px;">
                        Lade Matrix...
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" id="matrix-prev-month" class="proj-btn proj-btn-secondary proj-btn-sm">◀ Vorheriger</button>
                    <span id="matrix-title" style="font-weight: 700; font-size: 14px; min-width: 120px; text-align: center;"></span>
                    <button type="button" id="matrix-next-month" class="proj-btn proj-btn-secondary proj-btn-sm">Nächster ▶</button>
                    <button type="button" class="proj-btn proj-btn-primary proj-btn-sm" data-open-modal="modal-new-project">
                        + Projekt
                    </button>
                </div>
            </div>

            <div class="proj-matrix-wrapper">
                <table id="matrix-table" class="proj-matrix-table">
                    <!-- Wird vollständig via JS gerendert -->
                </table>
            </div>
            
            <div style="font-size: 12px; color: var(--proj-text-muted); margin-top: 10px;">
                💡 <em>Klicke auf eine Zelle, um den exakten Stundenwert in die Zwischenablage zu kopieren.</em>
            </div>
        </section>
    </main>

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
     * MODAL: NEUES PROJEKT ANLEGEN
     * ------------------------------------------------------------- -->
    <div id="modal-new-project" class="proj-modal-backdrop" style="display: none;">
        <div class="proj-modal-card">
            <div class="proj-modal-header">
                <h3 class="proj-modal-title">Neues Projekt anlegen</h3>
                <button type="button" class="proj-modal-close" data-close-modal>✕</button>
            </div>
            <form id="form-new-project">
                <div class="proj-form-group">
                    <label for="new-p-name">Projektname</label>
                    <input type="text" id="new-p-name" name="name" required placeholder="z. B. Core Architecture">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                    <div class="proj-form-group">
                        <label for="new-p-short">Kürzel (Quick-Command)</label>
                        <input type="text" id="new-p-short" name="short_code" required maxlength="12" placeholder="z. B. CORE">
                    </div>
                    <div class="proj-form-group">
                        <label for="new-p-color">Farbe</label>
                        <input type="color" id="new-p-color" name="color" value="#0284c7">
                    </div>
                </div>

                <div class="proj-form-group">
                    <label for="new-p-planisware">Projekt- / PSP-Code (optional)</label>
                    <input type="text" id="new-p-code" name="external_code" placeholder="z. B. PRJ-2026-004">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" class="proj-btn proj-btn-secondary" data-close-modal>Abbrechen</button>
                    <button type="submit" class="proj-btn proj-btn-primary">Anlegen</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

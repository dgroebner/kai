<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\ActivityLogRepository;
use Kai\Tools\System\SystemSettingsRepository;
use Kai\Tools\System\UserProfileRepository;

Auth::requirePage();

$activityRepo = new ActivityLogRepository();
$settingsRepo = new SystemSettingsRepository();
$userProfileRepo = new UserProfileRepository();

$currentUserEmail = $_SESSION['user_email'] ?? '';
$tab = $_GET['tab'] ?? 'activity';
$successMessage = null;
$errorMessage = null;

// Handle POST request for updating settings or notification preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errorMessage = "Ungültiger CSRF-Token.";
    } else {
        if ($tab === 'settings' || isset($_POST['settings'])) {
            $tab = 'settings';
            $settingsData = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
            $knownKeys = array_column($settingsRepo->getAll(), 'setting_key');

            try {
                foreach ($settingsData as $key => $value) {
                    if (!in_array((string)$key, $knownKeys, true) || !is_scalar($value)) {
                        continue;
                    }
                    $settingsRepo->set((string)$key, trim((string)$value));
                }
                $successMessage = "Einstellungen erfolgreich gespeichert.";
            } catch (Throwable $e) {
                (new Logger())->error('system/index.php: Fehler beim Speichern der Einstellungen.', ['error' => $e->getMessage()]);
                $errorMessage = "Fehler beim Speichern der Einstellungen.";
            }
        } elseif ($tab === 'notifications' || isset($_POST['notifications'])) {
            $tab = 'notifications';
            $rawPreferences = $_POST['notifications'] ?? [];

            // Erlaubte Keys aus dem ActivityLogger / Defaults
            $currentPrefs = $userProfileRepo->getPreferences($currentUserEmail);
            $updatedPrefs = [];

            foreach ($currentPrefs as $key => $defaultValue) {
                // Checkbox gesetzt -> true, ansonsten false
                $updatedPrefs[$key] = isset($rawPreferences[$key]) && (string)$rawPreferences[$key] === '1';
            }

            try {
                $userProfileRepo->updatePreferences($currentUserEmail, $updatedPrefs);
                $successMessage = "Benachrichtigungseinstellungen erfolgreich gespeichert.";
            } catch (Throwable $e) {
                (new Logger())->error('system/index.php: Fehler beim Speichern der Benachrichtigungsprofile.', ['error' => $e->getMessage()]);
                $errorMessage = "Fehler beim Speichern der Benachrichtigungen.";
            }
        }
    }
}

// Daten für die jeweiligen Tabs laden
$settings = $settingsRepo->getAll();
$userPreferences = $userProfileRepo->getPreferences($currentUserEmail);

// Paginierung für Aktivitäten konfigurieren
$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$totalItems = $activityRepo->getTotalCount();
$totalPages = max(1, (int)ceil($totalItems / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $limit;
$activities = $activityRepo->getLatestActivities($limit, $offset);

$csrfToken = Auth::csrfToken();

/**
 * Mapping von Event-Typen zu Emojis & Lesbaren Bezeichnungen
 */
function getEventIcon(string $eventType): string
{
    return match ($eventType) {
        'car_telemetry_loaded' => '🚐',
        'pv_forecast_loaded' => '☀️',
        'receipt_created' => '🧾',
        'bank_data_imported' => '🏦',
        'creditcard_statement_created' => '💳',
        'battery_fully_charged' => '🔋',
        default => '📌',
    };
}

function getEventLabel(string $eventType): string
{
    return match ($eventType) {
        'receipt_created' => 'Neuer E-Bon erfasst',
        'creditcard_statement_created' => 'Neue Kreditkartenabrechnung erfasst',
        'bank_data_imported' => 'Neue Bankdaten importiert',
        'pv_forecast_loaded' => 'Neue PV-Prognose geladen',
        'car_telemetry_loaded' => 'Neue Fahrzeugdaten geladen',
        default => ucfirst(str_replace('_', ' ', $eventType)),
    };
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System & Aktivitäts-Log - KAI Tools</title>
    <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/../shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>⚙️ System & Verwaltung</h1>
        <a href="../index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
    </header>

    <!-- Tab-Switcher -->
    <div class="period-switcher" style="justify-content: flex-start; margin-bottom: 1.5rem;">
        <a href="index.php?tab=activity" class="btn <?= $tab === 'activity' ? '' : 'btn-outline' ?>">📋
            Aktivitäts-Log</a>
        <a href="index.php?tab=notifications" class="btn <?= $tab === 'notifications' ? '' : 'btn-outline' ?>">🔔
            Benachrichtigungen</a>
        <a href="index.php?tab=settings" class="btn <?= $tab === 'settings' ? '' : 'btn-outline' ?>">🛠️
            System-Einstellungen</a>
    </div>

    <main>
        <?php if ($successMessage): ?>
            <div class="alert alert-success"
                 style="margin-bottom: 1rem;"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger"
                 style="margin-bottom: 1rem;"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($tab === 'notifications'): ?>
            <!-- Tab: Benachrichtigungseinstellungen -->
            <section class="card">
                <h2>Web Push</h2>
                <p class="text-muted" style="margin-bottom: 1rem;">
                    Web Push ermöglicht native Benachrichtigungen – auch wenn die App gerade nicht geöffnet ist.
                    Die Aktivierung gilt nur für <strong>dieses Gerät / diesen Browser</strong>.
                </p>
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <button id="push-toggle-btn" class="btn" type="button">🔔 Web Push aktivieren</button>
                    <span id="push-status-text" class="text-muted" style="font-size: 0.9rem;"></span>
                </div>
            </section>

            <section class="card" style="margin-top: 1.5rem;">
                <h2>Benachrichtigungsklassen</h2>
                <p class="text-muted" style="margin-bottom: 1.5rem;">Legen Sie fest, für welche Aktivitäts-Kategorien
                    Sie Benachrichtigungen erhalten möchten.</p>

                <form action="index.php?tab=notifications" method="POST">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="table-responsive">
                        <table class="data-table stack-table">
                            <thead>
                            <tr>
                                <th>Kategorie / Event</th>
                                <th style="width: 120px; text-align: center;">Aktiviert</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($userPreferences as $eventType => $isEnabled): ?>
                                <tr>
                                    <td data-label="Kategorie">
                                        <span style="font-size: 1.2rem; margin-right: 0.5rem;"><?= getEventIcon($eventType) ?></span>
                                        <strong><?= htmlspecialchars(getEventLabel($eventType), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <br><small
                                                class="text-muted"><?= htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td data-label="Aktiviert" style="text-align: center;">
                                        <input type="checkbox"
                                               name="notifications[<?= htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8') ?>]"
                                               value="1"
                                                <?= $isEnabled ? 'checked' : '' ?>
                                               style="transform: scale(1.3);">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-actions" style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-save">💾 Benachrichtigungen speichern</button>
                    </div>
                </form>
            </section>

        <?php elseif ($tab === 'settings'): ?>
            <!-- Tab 2: System-Einstellungen -->
            <section class="card">
                <h2>System-Einstellungen konfigurieren</h2>
                <p class="text-muted" style="margin-bottom: 1.5rem;">Hier können globale Parameter angepasst werden.</p>

                <form action="index.php?tab=settings" method="POST">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="table-responsive">
                        <table class="data-table stack-table">
                            <thead>
                            <tr>
                                <th>Bezeichnung / Schlüssel</th>
                                <th>Wert</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($settings)): ?>
                                <tr>
                                    <td colspan="2" class="text-center">Keine Einstellungen gefunden.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($settings as $setting): ?>
                                    <tr>
                                        <td data-label="Bezeichnung">
                                            <strong><?= htmlspecialchars($setting['label'] ?? $setting['setting_key'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <br><small
                                                    class="text-muted"><?= htmlspecialchars($setting['setting_key'], ENT_QUOTES, 'UTF-8') ?></small>
                                        </td>
                                        <td data-label="Wert">
                                            <input type="text"
                                                   name="settings[<?= htmlspecialchars($setting['setting_key'], ENT_QUOTES, 'UTF-8') ?>]"
                                                   value="<?= htmlspecialchars($setting['setting_value'], ENT_QUOTES, 'UTF-8') ?>"
                                                   class="yield-input" style="width: 100%; max-width: 300px;">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-actions" style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-save">💾 Einstellungen speichern</button>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <!-- Tab 1: Aktivitäts-Log -->
            <section class="card">
                <h2>Letzte System-Aktivitäten</h2>
                <?php if (empty($activities)): ?>
                    <p class="text-muted">Noch keine Aktivitäten protokolliert.</p>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($activities as $activity): ?>
                            <li class="activity-item <?= $activity['is_read'] ? 'read' : 'unread' ?>">
                                <div class="activity-main">
                                        <span class="activity-message">
                                            <span class="activity-icon"><?= getEventIcon($activity['event_type']) ?></span>
                                            <?php if (!empty($activity['link_url'])): ?>
                                                <a href="<?= htmlspecialchars($activity['link_url']) ?>"
                                                   class="btn-link">
                                                    <?= htmlspecialchars($activity['message']) ?>
                                                </a>
                                            <?php else: ?>
                                                <?= htmlspecialchars($activity['message']) ?>
                                            <?php endif; ?>
                                        </span>
                                    <time class="activity-time" datetime="<?= $activity['created_at'] ?>">
                                        <?= date('d.m.Y H:i', strtotime($activity['created_at'])) ?> Uhr
                                    </time>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($totalPages > 1): ?>
                        <nav class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?tab=activity&page=<?= $page - 1 ?>" class="btn btn-outline">&laquo;
                                    Vorherige</a>
                            <?php else: ?>
                                <span class="btn btn-outline disabled">&laquo; Vorherige</span>
                            <?php endif; ?>

                            <span class="page-info">
                                    Seite <?= $page ?> von <?= $totalPages ?> (<?= $totalItems ?> Einträge)
                                </span>

                            <?php if ($page < $totalPages): ?>
                                <a href="?tab=activity&page=<?= $page + 1 ?>" class="btn btn-outline">Nächste
                                    &raquo;</a>
                            <?php else: ?>
                                <span class="btn btn-outline disabled">Nächste &raquo;</span>
                            <?php endif; ?>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/../shared/footer_scripts.php'; ?>
</body>
</html>
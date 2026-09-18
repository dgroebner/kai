<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\ActivityLogRepository;
use Kai\Tools\System\GroupRepository;
use Kai\Tools\System\PermissionService;
use Kai\Tools\System\SystemSettingsRepository;
use Kai\Tools\System\UserProfileRepository;

Auth::requirePage('system_read');

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
        if (isset($_POST['action']) && $_POST['action'] === 'save_roles' && Auth::hasPermission('system_write')) {
            $tab = 'roles';
            $groupRepo = new GroupRepository();

            try {
                // Update Group Permissions
                if (isset($_POST['group_permissions']) && is_array($_POST['group_permissions'])) {
                    foreach ($_POST['group_permissions'] as $groupId => $permissions) {
                        // Klick auf _write setzt automatisch _read. Check for writes:
                        $processedPermissions = [];
                        foreach ($permissions as $perm) {
                            $processedPermissions[] = $perm;
                            if (str_ends_with($perm, '_write')) {
                                $readPerm = str_replace('_write', '_read', $perm);
                                if (!in_array($readPerm, $processedPermissions, true)) {
                                    $processedPermissions[] = $readPerm;
                                }
                            }
                        }
                        $groupRepo->updateGroupPermissions((int)$groupId, array_unique($processedPermissions));
                    }
                }

                // Empty permissions for groups that are not in the POST array
                if (isset($_POST['all_group_ids']) && is_array($_POST['all_group_ids'])) {
                    foreach ($_POST['all_group_ids'] as $groupId) {
                        if (!isset($_POST['group_permissions'][$groupId])) {
                            $groupRepo->updateGroupPermissions((int)$groupId, []);
                        }
                    }
                }

                // Update User Groups
                if (isset($_POST['user_groups']) && is_array($_POST['user_groups'])) {
                    foreach ($_POST['user_groups'] as $email => $groupIds) {
                        // Validate IDs
                        $validIds = array_map('intval', $groupIds);
                        $groupRepo->updateUserGroups($email, $validIds);
                    }
                }

                // Empty user groups for users that are not in the POST array
                if (isset($_POST['all_user_emails']) && is_array($_POST['all_user_emails'])) {
                    foreach ($_POST['all_user_emails'] as $email) {
                        if (!isset($_POST['user_groups'][$email])) {
                            $groupRepo->updateUserGroups($email, []);
                        }
                    }
                }
                // Reload current user's permissions in session just in case they were modified
                $dbCon = \Kai\Tools\Shared\Db\Database::getInstance()->getConnection();
                if (!empty($_SESSION['temp_group_id'])) {
                    $stmt = $dbCon->prepare("SELECT permission FROM group_permissions WHERE group_id = :group_id");
                    $stmt->execute(["group_id" => $_SESSION['temp_group_id']]);
                } else {
                    $stmt = $dbCon->prepare("SELECT gp.permission FROM group_permissions gp JOIN user_groups ug ON gp.group_id = ug.group_id WHERE ug.user_email = :email");
                    $stmt->execute(["email" => $_SESSION['user_email']]);
                }
                $_SESSION['permissions'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);

                $successMessage = "Rollen & Rechte erfolgreich gespeichert.";
            } catch (Throwable $e) {
                new Logger()->error('system/index.php: Fehler beim Speichern der Rollen.', ['error' => $e->getMessage()]);
                $errorMessage = "Fehler beim Speichern der Rollen & Rechte.";
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'create_group' && Auth::hasPermission('system_write')) {
            $tab = 'roles';
            $groupRepo = new GroupRepository();
            $groupName = trim($_POST['new_group_name'] ?? '');
            if ($groupName !== '') {
                try {
                    $groupRepo->createGroup($groupName);
                    $successMessage = "Gruppe '$groupName' wurde erstellt.";
                } catch (Throwable $e) {
                    $errorMessage = "Fehler beim Erstellen der Gruppe.";
                }
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'switch_temp_group' && Auth::hasPermission('system_write')) {
            $tab = 'roles';
            $adminEmail = $_ENV['ADMIN_EMAIL'] ?? null;
            if ($adminEmail && strtolower($_SESSION['user_email'] ?? '') === strtolower($adminEmail)) {
                $tempGroupId = $_POST['temp_group_id'] ?? '';
                if ($tempGroupId === '') {
                    unset($_SESSION['temp_group_id']);
                    $successMessage = "Zurück zur Admin-Ansicht gewechselt.";
                } else {
                    $_SESSION['temp_group_id'] = (int)$tempGroupId;
                    $successMessage = "Temporär als Gruppe " . (int)$tempGroupId . " angemeldet.";
                }

                // Reload permissions in session
                $permissionService = new PermissionService();
                // We don't have the method public, so let's just do it manually here for temp switch
                $dbCon = Database::getInstance()->getConnection();
                if (!empty($_SESSION['temp_group_id'])) {
                    $stmt = $dbCon->prepare("SELECT permission FROM group_permissions WHERE group_id = :group_id");
                    $stmt->execute(["group_id" => $_SESSION['temp_group_id']]);
                } else {
                    $stmt = $dbCon->prepare("SELECT gp.permission FROM group_permissions gp JOIN user_groups ug ON gp.group_id = ug.group_id WHERE ug.user_email = :email");
                    $stmt->execute(["email" => $_SESSION['user_email']]);
                }
                $_SESSION['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } elseif ($tab === 'settings' || isset($_POST['settings'])) {
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
                new Logger()->error('system/index.php: Fehler beim Speichern der Einstellungen.', ['error' => $e->getMessage()]);
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
                new Logger()->error('system/index.php: Fehler beim Speichern der Benachrichtigungsprofile.', ['error' => $e->getMessage()]);
                $errorMessage = "Fehler beim Speichern der Benachrichtigungen.";
            }
        } elseif ($tab === 'school' || isset($_POST['action']) && in_array($_POST['action'], ['save_student', 'delete_student', 'save_school_settings', 'sync_school'], true)) {
            $tab = 'school';
            $studentRepo = new \Kai\Tools\School\SchoolStudentRepository();

            if (isset($_POST['action']) && $_POST['action'] === 'save_student' && Auth::hasPermission('system_write')) {
                try {
                    $studentId = !empty($_POST['student_id']) ? (int)$_POST['student_id'] : null;
                    $studentRepo->save([
                        'id' => $studentId,
                        'name' => trim($_POST['name'] ?? ''),
                        'class_name' => trim($_POST['class_name'] ?? ''),
                        'excluded_subjects' => !empty($_POST['excluded_subjects']) ? trim($_POST['excluded_subjects']) : null,
                        'user_email' => !empty($_POST['user_email']) ? trim($_POST['user_email']) : null,
                        'display_color' => trim($_POST['display_color'] ?? '#2563eb'),
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    ]);
                    $successMessage = "Schülerprofil erfolgreich gespeichert.";
                } catch (Throwable $e) {
                    $errorMessage = "Fehler beim Speichern des Schülers: " . $e->getMessage();
                }
            } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_student' && Auth::hasPermission('system_write')) {
                try {
                    $studentId = (int)($_POST['student_id'] ?? 0);
                    if ($studentId > 0) {
                        $studentRepo->delete($studentId);
                        $successMessage = "Schülerprofil gelöscht.";
                    }
                } catch (Throwable $e) {
                    $errorMessage = "Fehler beim Löschen des Schülers.";
                }
            } elseif (isset($_POST['action']) && $_POST['action'] === 'save_school_settings' && Auth::hasPermission('system_write')) {
                try {
                    $settingsRepo->set('school_number', trim($_POST['school_number'] ?? '10058903'), 'Stundenplan24 Schulnummer');
                    $settingsRepo->set('school_username', trim($_POST['school_username'] ?? ''), 'Stundenplan24 Benutzername');
                    if (!empty($_POST['school_password'])) {
                        $settingsRepo->set('school_password', trim($_POST['school_password']), 'Stundenplan24 Kennwort');
                    }
                    $successMessage = "Schuleinstellungen erfolgreich gespeichert.";
                } catch (Throwable $e) {
                    $errorMessage = "Fehler beim Speichern der Schuleinstellungen.";
                }
            } elseif (isset($_POST['action']) && $_POST['action'] === 'sync_school' && Auth::hasPermission('system_write')) {
                try {
                    $schoolService = new \Kai\Tools\School\SchoolService();
                    $res = $schoolService->syncTodayAndNext();
                    $successMessage = "Synchronisation erfolgreich durchgeführt (" . count($res) . " Tage geprüft).";
                } catch (Throwable $e) {
                    $errorMessage = "Fehler bei der Synchronisation: " . $e->getMessage();
                }
            }
        }
    }
}

// Daten für die jeweiligen Tabs laden
$settings = $settingsRepo->getAll();
$userPreferences = $userProfileRepo->getPreferences($currentUserEmail);

$schoolStudentRepo = new \Kai\Tools\School\SchoolStudentRepository();
$students = $schoolStudentRepo->getAll();
$groupRepoForUsers = new GroupRepository();
$systemUsers = $groupRepoForUsers->getAllUsers();

$groups = [];
$users = [];
$groupPermissions = [];
$userGroups = [];
if ($tab === 'roles' && Auth::hasPermission('system_write')) {
    $groupRepo = new GroupRepository();
    
    // Fallback: Aktuellen Benutzer in die DB aufnehmen, falls er durch eine alte Session noch nicht drin ist
    try {
        $permissionService = new \Kai\Tools\System\PermissionService();
        $permissionService->handleUserLogin($_SESSION['user_email'], $_SESSION['user_name'] ?? '');
    } catch (\Throwable $e) {}

    $groups = $groupRepo->getAllGroups();
    $users = $groupRepo->getAllUsers();

    foreach ($groups as $group) {
        $groupPermissions[$group['id']] = $groupRepo->getGroupPermissions($group['id']);
    }
    foreach ($users as $user) {
        $userGroups[$user['email']] = $groupRepo->getUserGroups($user['email']);
    }
}

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
        'school_plan_updated' => '🎒',
        'car_telemetry_loaded' => '🚐',
        'pv_forecast_loaded' => '☀️',
        'receipt_created' => '🧾',
        'bank_data_imported' => '🏦',
        'creditcard_statement_created' => '💳',
        'battery_fully_charged' => '🔋',
        'shopping_completed' => '🛒',
        default => '📌',
    };
}

function getEventLabel(string $eventType): string
{
    return match ($eventType) {
        'school_plan_updated' => 'Schul-Vertretungsplan aktualisiert',
        'receipt_created' => 'Neuer E-Bon erfasst',
        'creditcard_statement_created' => 'Neue Kreditkartenabrechnung erfasst',
        'bank_data_imported' => 'Neue Bankdaten importiert',
        'pv_forecast_loaded' => 'Neue PV-Prognose geladen',
        'car_telemetry_loaded' => 'Neue Fahrzeugdaten geladen',
        'shopping_completed' => 'Einkauf abgeschlossen',
        default => ucfirst(str_replace('_', ' ', $eventType)),
    };
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
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
        <a href="index.php?tab=school" class="btn <?= $tab === 'school' ? '' : 'btn-outline' ?>">🎒
            Schule</a>
        <?php if (Auth::hasPermission('system_write')): ?>
            <a href="index.php?tab=roles" class="btn <?= $tab === 'roles' ? '' : 'btn-outline' ?>">👥
                Rollen & Rechte</a>
        <?php endif; ?>
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
        <?php elseif ($tab === 'school'): ?>
            <!-- Tab: Schule & Schülerverwaltung -->
            <section class="card" style="margin-bottom: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                    <div>
                        <h2>🎒 Zugangsdaten Stundenplan24</h2>
                        <p class="text-muted">Zugangsdaten der Schule für den automatischen Abruf der Vertretungspläne via HTTP Basic Auth.</p>
                    </div>
                    <?php if (Auth::hasPermission('system_write')): ?>
                        <form action="index.php?tab=school" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="sync_school">
                            <button type="submit" class="btn btn-outline">🔄 Jetzt synchronisieren</button>
                        </form>
                    <?php endif; ?>
                </div>

                <form action="index.php?tab=school" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="save_school_settings">

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                        <div>
                            <label for="school_number" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Schulnummer:</label>
                            <input type="text" id="school_number" name="school_number" value="<?= htmlspecialchars($settingsRepo->get('school_number', '10058903'), ENT_QUOTES, 'UTF-8') ?>" class="yield-input" style="width: 100%;" required>
                        </div>
                        <div>
                            <label for="school_username" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Benutzername:</label>
                            <input type="text" id="school_username" name="school_username" value="<?= htmlspecialchars($settingsRepo->get('school_username', ''), ENT_QUOTES, 'UTF-8') ?>" class="yield-input" style="width: 100%;" placeholder="z. B. schueler">
                        </div>
                        <div>
                            <label for="school_password" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Kennwort:</label>
                            <input type="password" id="school_password" name="school_password" value="" class="yield-input" style="width: 100%;" placeholder="<?= $settingsRepo->get('school_password', '') !== '' ? '•••••••• (gespeichert)' : 'Kennwort eingeben' ?>">
                        </div>
                    </div>

                    <?php if (Auth::hasPermission('system_write')): ?>
                        <button type="submit" class="btn btn-save">💾 Schuleinstellungen speichern</button>
                    <?php endif; ?>
                </form>
            </section>

            <!-- Schüler-Profile verwalten -->
            <section class="card" style="margin-bottom: 2rem;">
                <h2>Kinder &amp; Klassen-Zuordnung</h2>
                <p class="text-muted" style="margin-bottom: 1.5rem;">
                    Hier werden die Kinder mit ihrer aktuellen Klasse und optional ihrer Google-E-Mail-Adresse verknüpft.
                    Wenn sich ein Kind anmeldet, filtert das Dashboard automatisch auf dessen Klasse.
                </p>

                <div class="table-responsive">
                    <table class="data-table stack-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Klasse</th>
                                <th>Abgewählte Fächer</th>
                                <th>Verknüpftes Google-Konto</th>
                                <th>Farbe</th>
                                <th style="text-align: center;">Status</th>
                                <th style="text-align: right;">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">Noch keine Schüler angelegt.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td data-label="Name">
                                            <strong><?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </td>
                                        <td data-label="Klasse">
                                            <span class="badge badge-outline"><?= htmlspecialchars($student['class_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                        <td data-label="Abgewählt">
                                            <?php if (!empty($student['excluded_subjects'])): ?>
                                                <small class="text-danger"><?= htmlspecialchars($student['excluded_subjects'], ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">–</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="E-Mail">
                                            <?php if (!empty($student['user_email'])): ?>
                                                <small><?= htmlspecialchars($student['user_email'], ENT_QUOTES, 'UTF-8') ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">–</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Farbe">
                                            <span style="display: inline-block; width: 14px; height: 14px; border-radius: 50%; background-color: <?= htmlspecialchars($student['display_color'], ENT_QUOTES, 'UTF-8') ?>; vertical-align: middle; margin-right: 0.35rem;"></span>
                                            <small class="text-muted"><?= htmlspecialchars($student['display_color'], ENT_QUOTES, 'UTF-8') ?></small>
                                        </td>
                                        <td data-label="Status" style="text-align: center;">
                                            <?= $student['is_active'] ? '<span class="text-success">Aktiv</span>' : '<span class="text-muted">Inaktiv</span>' ?>
                                        </td>
                                        <td data-label="Aktionen" style="text-align: right;">
                                            <?php if (Auth::hasPermission('system_write')): ?>
                                                <button type="button" 
                                                        class="btn btn-outline js-edit-student" 
                                                        style="padding: 0.2rem 0.5rem; font-size: 0.8rem; margin-right: 0.25rem;"
                                                        data-id="<?= (int)$student['id'] ?>"
                                                        data-name="<?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-class="<?= htmlspecialchars($student['class_name'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-email="<?= htmlspecialchars($student['user_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                        data-color="<?= htmlspecialchars($student['display_color'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-excluded="<?= htmlspecialchars($student['excluded_subjects'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                        data-active="<?= (int)$student['is_active'] ?>">
                                                    ✏️ Bearbeiten
                                                </button>
                                                <form action="index.php?tab=school" method="POST" style="display: inline;" class="js-confirm-delete" data-confirm-message="Schülerprofil wirklich löschen?">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="delete_student">
                                                    <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                                    <button type="submit" class="btn btn-outline" style="padding: 0.2rem 0.5rem; font-size: 0.8rem; color: var(--color-red);">Löschen</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if (Auth::hasPermission('system_write')): ?>
                <section class="card" id="student-form-card">
                    <h2 id="student-form-title">Neuen Schüler anlegen</h2>
                    <form action="index.php?tab=school" method="POST" id="student-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="save_student">
                        <input type="hidden" name="student_id" id="st_id" value="">

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                            <div>
                                <label for="st_name" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Name des Kindes:</label>
                                <input type="text" id="st_name" name="name" class="yield-input" style="width: 100%;" placeholder="z. B. Enya oder Zoé" required>
                            </div>
                            <div>
                                <label for="st_class" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Klasse:</label>
                                <input type="text" id="st_class" name="class_name" class="yield-input" style="width: 100%;" placeholder="z. B. 6A oder 8B" required>
                            </div>
                            <div>
                                <label for="st_email" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Google-Konto (E-Mail):</label>
                                <select id="st_email" name="user_email" class="yield-input" style="width: 100%;">
                                    <option value="">– Kein Google-Konto zugeordnet –</option>
                                    <?php foreach ($systemUsers as $u): ?>
                                        <option value="<?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($u['name'] ? "{$u['name']} ({$u['email']})" : $u['email'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="st_color" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Badge-Farbe:</label>
                                <input type="color" id="st_color" name="display_color" value="#0284c7" class="yield-input" style="width: 100%; height: 38px; padding: 2px;">
                            </div>
                            <div>
                                <label for="st_excluded" style="display: block; margin-bottom: 0.35rem; font-weight: 500;">Abgewählte Fächer (optional):</label>
                                <input type="text" id="st_excluded" name="excluded_subjects" class="yield-input" style="width: 100%;" placeholder="z. B. ETH, F (kommagetrennt)">
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem; padding-top: 1.5rem;">
                                <input type="checkbox" id="st_active" name="is_active" value="1" checked style="transform: scale(1.3);">
                                <label for="st_active">Profil aktiv</label>
                            </div>
                        </div>

                        <div style="display: flex; gap: 0.75rem; align-items: center;">
                            <button type="submit" id="st_submit_btn" class="btn btn-save">➕ Schülerprofil speichern</button>
                            <button type="button" id="st_cancel_btn" class="btn btn-outline" style="display: none;">Abbrechen</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        <?php elseif ($tab === 'roles' && Auth::hasPermission('system_write')): ?>
            <?php
            $adminEmail = $_ENV['ADMIN_EMAIL'] ?? null;
            $isAdmin = $adminEmail && strtolower($_SESSION['user_email'] ?? '') === strtolower($adminEmail);
            ?>
            <?php if ($isAdmin): ?>
                <section class="card" style="margin-bottom: 2rem;">
                    <h2>Temporärer Gruppenwechsel (Admin-Testing)</h2>
                    <p class="text-muted">Simuliere die Rechte einer anderen Gruppe. Beim nächsten Login wird dies
                        zurückgesetzt.</p>
                    <form action="index.php?tab=roles" method="POST"
                          style="display: flex; gap: 1rem; align-items: flex-end;">
                        <input type="hidden" name="csrf_token"
                               value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="switch_temp_group">
                        <div style="flex-grow: 1;">
                            <label for="temp_group_id" style="display: block; margin-bottom: 0.5rem;">Gruppe:</label>
                            <select name="temp_group_id" id="temp_group_id" class="yield-input"
                                    style="width: 100%; max-width: 300px;">
                                <option value="">(Admin / Normal)</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?= $group['id'] ?>" <?= ($_SESSION['temp_group_id'] ?? '') == $group['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($group['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-save">Wechseln</button>
                    </form>
                </section>
            <?php endif; ?>
            <section class="card" style="margin-bottom: 2rem;">
                <h2>Neue Gruppe erstellen</h2>
                <form action="index.php?tab=roles" method="POST"
                      style="display: flex; gap: 1rem; align-items: flex-end;">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="create_group">
                    <div style="flex-grow: 1;">
                        <label for="new_group_name" style="display: block; margin-bottom: 0.5rem;">Gruppenname:</label>
                        <input type="text" id="new_group_name" name="new_group_name" class="yield-input"
                               style="width: 100%; max-width: 300px;" required>
                    </div>
                    <button type=" submit" class="btn btn-save">Gruppe hinzufügen</button>
                </form>
            </section>

            <form action="index.php?tab=roles" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="save_roles">

                <section class="card" style="margin-bottom: 2rem;">
                    <h2>Rechte-Matrix (Gruppen)</h2>
                    <p class="text-muted">Hinweis: Wer Schreibrechte (_write) erhält, benötigt meist auch Leserechte
                        (_read).</p>
                    <div class="table-responsive">
                        <table class="data-table stack-table">
                            <thead>
                            <tr>
                                <th>Recht / Erlaubnis</th>
                                <?php foreach ($groups as $group): ?>
                                    <th style="text-align: center;">
                                        <?= htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8') ?>
                                        <input type="hidden" name="all_group_ids[]" value="<?= $group['id'] ?>">
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            // Standard-Rechte sammeln, damit das UI nicht leer ist
                            $availablePermissions = [
                                    // System & Finanzen
                                    'system_read', 'system_write',
                                    'finance_read', 'finance_write',
                                    'ebon_read', 'ebon_write',
                                    // PV / Fahrzeug / Wetter
                                    'pv_read', 'pv_write',
                                    'car_read', 'car_write',
                                    'weather_read', 'weather_write',
                                    // Einkaufsliste — Basiszugang
                                    'shopping_read', 'shopping_write', 'shopping_master',
                                    // Einkaufsliste — Vorschläge-Tab
                                    'suggestions_read', 'suggestions_write',
                                    // Einkaufsliste — Rezept & KI-Tab
                                    'recipe_read', 'recipe_write',
                                    // Einkaufsliste — Historie & E-Bons-Tab
                                    'history_read', 'history_write',
                                    // Schule & Vertretungsplan
                                    'school_read', 'school_write',
                            ];
                            ?>

                            <?php foreach ($availablePermissions as $perm): ?>
                                <tr>
                                    <td data-label="Recht"><strong><?= htmlspecialchars($perm) ?></strong></td>
                                    <?php foreach ($groups as $group): ?>
                                        <td data-label="<?= htmlspecialchars($group['name']) ?>"
                                            style="text-align: center;">
                                            <input type="checkbox" name="group_permissions[<?= $group['id'] ?>][]"
                                                   value="<?= htmlspecialchars($perm) ?>" <?= in_array($perm, $groupPermissions[$group['id']] ?? []) ? 'checked' : '' ?>
                                                   style="transform: scale(1.3);">
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="card">
                    <h2>Benutzer-Zuordnung</h2>
                    <div class="table-responsive">
                        <table class="data-table stack-table">
                            <thead>
                            <tr>
                                <th>Benutzer</th>
                                <th>Zugewiesene Gruppen</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td data-label="Benutzer">
                                        <strong><?= htmlspecialchars($user['name'] ?? $user['email']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                        <input type="hidden" name="all_user_emails[]"
                                               value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>">
                                    </td>
                                    <td data-label="Gruppen">
                                        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                                            <?php foreach ($groups as $group): ?>
                                                <label style="display: flex; align-items: center; gap: 0.5rem; white-space: nowrap;">
                                                    <input type="checkbox"
                                                           name="user_groups[<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>][]"
                                                           value="<?= $group['id'] ?>" <?= in_array($group['id'], $userGroups[$user['email']] ?? []) ? 'checked' : '' ?>>
                                                    <?= htmlspecialchars($group['name']) ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="form-actions" style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-save">💾 Rollen & Rechte speichern</button>
                    </div>
                </section>
            </form>
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


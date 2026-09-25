<?php
require_once __DIR__ . '/../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\UserProfileRepository;

Auth::requirePage(); // Keine speziellen Rechte nötig für das eigene Profil

$userProfileRepo = new UserProfileRepository();
$currentUserEmail = $_SESSION['user_email'] ?? '';
$successMessage = null;
$errorMessage = null;

$eventGroups = [
    'Schule' => [
        'permission' => 'school_read',
        'events' => [
            'school_plan_updated' => [
                'icon' => '📅',
                'label' => 'Vertretungsplan aktualisiert',
                'desc' => 'Änderungen im Stunden- und Vertretungsplan'
            ],
            'school_notes_updated' => [
                'icon' => '📝',
                'label' => 'Hausaufgaben & Tests',
                'desc' => 'Neue Hausaufgaben, Tests oder Unterrichtsnotizen'
            ],
            'school_grades_updated' => [
                'icon' => '🎓',
                'label' => 'Neue Noten',
                'desc' => 'Neu eingetragene Noten und Leistungsnachweise'
            ],
        ],
    ],
    'Geburtstage & Jahrestage' => [
        'permission' => 'calendar_read',
        'events' => [
            'calendar_reminder' => [
                'icon' => '🎉',
                'label' => 'Geburtstage & Jahrestage',
                'desc' => 'Erinnerungen an anstehende Geburtstage und Jubiläen'
            ],
        ],
    ],
    'Finanzen' => [
        'permission' => 'finance_read',
        'events' => [
            'financial_report_generated' => [
                'icon' => '📊',
                'label' => 'Finanzberichte (Monat/Jahr)',
                'desc' => 'Automatische KI-Finanzanalysen nach Monatsabschluss'
            ],
            'bank_data_imported' => [
                'icon' => '🏦',
                'label' => 'Bankumsätze importiert',
                'desc' => 'Neue Girokonto-Umsätze via API'
            ],
            'creditcard_statement_created' => [
                'icon' => '💳',
                'label' => 'Kreditkartenabrechnungen',
                'desc' => 'Neue Kreditkartenabrechnungen per E-Mail erfasst'
            ],
        ],
    ],
    'E-Bons' => [
        'permission' => 'ebon_read',
        'events' => [
            'receipt_created' => [
                'icon' => '🧾',
                'label' => 'Neuer Kassenbon',
                'desc' => 'E-Bons aus E-Mail-Postfächern digital erfasst'
            ],
        ],
    ],
    'Einkaufsliste' => [
        'permission' => 'shopping_read',
        'events' => [
            'shopping_completed' => [
                'icon' => '🛒',
                'label' => 'Einkauf abgeschlossen',
                'desc' => 'Benachrichtigung, wenn ein Einkauf als erledigt markiert wird'
            ],
        ],
    ],
    'Photovoltaik & Speicher' => [
        'permission' => 'pv_read',
        'events' => [
            'pv_forecast_loaded' => [
                'icon' => '☀️',
                'label' => 'PV-Ertragsprognose',
                'desc' => 'Tägliche Solarprognose für den Folgetag geladen'
            ],
            'battery_fully_charged' => [
                'icon' => '🔋',
                'label' => 'Hausspeicher voll geladen (100%)',
                'desc' => 'Benachrichtigung sobald der PV-Speicher 100% erreicht'
            ],
        ],
    ],
    'Elektrofahrzeug' => [
        'permission' => 'car_read',
        'events' => [
            'car_telemetry_loaded' => [
                'icon' => '🚐',
                'label' => 'Fahrzeug-Telemetrie',
                'desc' => 'Neue Fahrzeug- und Batteriedaten empfangen'
            ],
            'car_charge_captured' => [
                'icon' => '🔌',
                'label' => 'Ladevorgang erfasst',
                'desc' => 'Zusammenfassung eines abgeschlossenen Ladevorgangs'
            ],
        ],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errorMessage = "Ungültiger CSRF-Token.";
    } else {
        $rawPreferences = $_POST['notifications'] ?? [];
        $currentPrefs = $userProfileRepo->getPreferences($currentUserEmail);
        $updatedPrefs = $currentPrefs;

        foreach ($eventGroups as $group) {
            if (!Auth::hasPermission($group['permission'])) {
                continue;
            }
            foreach ($group['events'] as $eventType => $meta) {
                $updatedPrefs[$eventType] = isset($rawPreferences[$eventType]) && (string)$rawPreferences[$eventType] === '1';
            }
        }

        try {
            $userProfileRepo->updatePreferences($currentUserEmail, $updatedPrefs);
            $successMessage = "Benachrichtigungseinstellungen erfolgreich gespeichert.";
        } catch (Throwable $e) {
            new Logger()->error('profile.php: Fehler beim Speichern der Benachrichtigungsprofile.', ['error' => $e->getMessage()]);
            $errorMessage = "Fehler beim Speichern der Benachrichtigungen.";
        }
    }
}

$userPreferences = $userProfileRepo->getPreferences($currentUserEmail);
$csrfToken = Auth::csrfToken();

$visibleGroups = array_filter($eventGroups, function ($group) {
    return Auth::hasPermission($group['permission']);
});
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Mein Profil - KAI Tools</title>
    <link rel="stylesheet" href="css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/shared/head-pwa.php'; ?>
</head>
<?php include __DIR__ . '/shared/body-tag.php'; ?>
<div class="container">
    <header class="page-header">
        <h1>👤 Mein Profil</h1>
        <a href="index.php" class="btn btn-outline">&larr; Zurück zur Übersicht</a>
    </header>

    <main>
        <?php if ($successMessage): ?>
            <div class="alert alert-success"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="card">
            <h2>Web Push</h2>
            <p class="text-muted">
                Web Push ermöglicht native Benachrichtigungen – auch wenn die App gerade nicht geöffnet ist.
                Die Aktivierung gilt nur für <strong>dieses Gerät / diesen Browser</strong>.
            </p>
            <div class="profile-push-row">
                <button id="push-toggle-btn" class="btn" type="button">🔔 Web Push aktivieren</button>
                <span id="push-status-text" class="text-muted profile-push-status"></span>
            </div>
        </section>

        <section class="card profile-section-card">
            <h2>Benachrichtigungsklassen</h2>
            <p class="text-muted">Legen Sie fest, für welche Aktivitäts-Kategorien Sie Benachrichtigungen erhalten möchten.</p>

            <?php if (empty($visibleGroups)): ?>
                <p class="text-muted">Für Ihr Benutzerkonto sind derzeit keine konfigurierbaren Benachrichtigungsklassen freigeschaltet.</p>
            <?php else: ?>
                <form action="profile.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <?php foreach ($visibleGroups as $groupTitle => $group): ?>
                        <div class="profile-group-header">
                            <span class="profile-group-badge"><?= htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="data-table stack-table">
                                <thead>
                                <tr>
                                    <th>Ereignis</th>
                                    <th class="profile-checkbox-cell">Aktiviert</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($group['events'] as $eventType => $meta): ?>
                                    <?php $isEnabled = $userPreferences[$eventType] ?? true; ?>
                                    <tr>
                                        <td data-label="Ereignis">
                                            <span class="profile-event-icon"><?= $meta['icon'] ?></span>
                                            <strong><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($meta['desc'], ENT_QUOTES, 'UTF-8') ?></small>
                                        </td>
                                        <td data-label="Aktiviert" class="profile-checkbox-cell">
                                            <input type="checkbox" name="notifications[<?= htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $isEnabled ? 'checked' : '' ?> class="profile-checkbox">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-save">💾 Benachrichtigungen speichern</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php include __DIR__ . '/shared/footer_scripts.php'; ?>
<script src="js/http.js" defer></script>
<script src="js/push.js" defer></script>
</body>
</html>

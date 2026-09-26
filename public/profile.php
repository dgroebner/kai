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
        $savedAny = false;

        // 1. Benachrichtigungen speichern
        if (isset($_POST['save_notifications']) || isset($_POST['notifications'])) {
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
                $savedAny = true;
                $successMessage = "Benachrichtigungseinstellungen erfolgreich gespeichert.";
            } catch (Throwable $e) {
                new Logger()->error('profile.php: Fehler beim Speichern der Benachrichtigungsprofile.', ['error' => $e->getMessage()]);
                $errorMessage = "Fehler beim Speichern der Benachrichtigungen.";
            }
        }

        // 2. Start-Briefing speichern
        if (isset($_POST['save_briefing']) || isset($_POST['briefing'])) {
            $rawBriefing = $_POST['briefing'] ?? [];
            $currentBriefing = $userProfileRepo->getBriefingPreferences($currentUserEmail);
            $updatedBriefing = $currentBriefing;

            foreach (UserProfileRepository::BRIEFING_WIDGET_CONFIG as $widgetKey => $meta) {
                if (!empty($meta['permission']) && !Auth::hasPermission($meta['permission'])) {
                    continue;
                }
                $enabled = isset($rawBriefing[$widgetKey]['enabled']) && (string)$rawBriefing[$widgetKey]['enabled'] === '1';
                $order = isset($rawBriefing[$widgetKey]['order']) && is_numeric($rawBriefing[$widgetKey]['order'])
                    ? (int)$rawBriefing[$widgetKey]['order']
                    : ($currentBriefing[$widgetKey]['order'] ?? 50);

                $updatedBriefing[$widgetKey] = [
                    'enabled' => $enabled,
                    'order' => $order,
                ];
            }

            $popupEnabled = isset($_POST['briefing_popup_enabled']) && (string)$_POST['briefing_popup_enabled'] === '1';

            try {
                $userProfileRepo->updateBriefingPreferences($currentUserEmail, $updatedBriefing, $popupEnabled);
                $savedAny = true;
                $successMessage = $successMessage ? "Einstellungen erfolgreich gespeichert." : "Daily-Briefing-Einstellungen erfolgreich gespeichert.";
            } catch (Throwable $e) {
                new Logger()->error('profile.php: Fehler beim Speichern der Briefing-Einstellungen.', ['error' => $e->getMessage()]);
                $errorMessage = "Fehler beim Speichern des Briefings.";
            }
        }
    }
}

$userPreferences = $userProfileRepo->getPreferences($currentUserEmail);
$userBriefing = $userProfileRepo->getBriefingPreferences($currentUserEmail);
$isBriefingPopupEnabled = $userProfileRepo->isBriefingPopupEnabled($currentUserEmail);
$csrfToken = Auth::csrfToken();

$visibleGroups = array_filter($eventGroups, function ($group) {
    return Auth::hasPermission($group['permission']);
});

$visibleBriefingWidgets = array_filter(UserProfileRepository::BRIEFING_WIDGET_CONFIG, function ($meta) {
    return empty($meta['permission']) || Auth::hasPermission($meta['permission']);
});

$orderedBriefingWidgets = [];
foreach ($userBriefing as $widgetKey => $pref) {
    if (isset($visibleBriefingWidgets[$widgetKey])) {
        $orderedBriefingWidgets[$widgetKey] = $visibleBriefingWidgets[$widgetKey];
    }
}
foreach ($visibleBriefingWidgets as $widgetKey => $meta) {
    if (!isset($orderedBriefingWidgets[$widgetKey])) {
        $orderedBriefingWidgets[$widgetKey] = $meta;
    }
}
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
                        <button type="submit" name="save_notifications" value="1" class="btn btn-save">💾 Benachrichtigungen speichern</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="card profile-section-card">
            <h2>☀️ Start-Briefing (Daily Briefing)</h2>
            <p class="text-muted">
                Legen Sie fest, welche Informationskacheln beim Öffnen des Dashboards im Briefing-Popup angezeigt werden
                und in welcher Reihenfolge diese erscheinen sollen.
            </p>

            <?php if (empty($visibleBriefingWidgets)): ?>
                <p class="text-muted">Für Ihr Benutzerkonto sind derzeit keine Widgets freigeschaltet.</p>
            <?php else: ?>
                <form action="profile.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div style="margin-bottom: 1.25rem; padding: 0.9rem 1.1rem; background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 8px; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                        <div>
                            <strong style="font-size: 1rem; color: var(--text-main, #f8fafc);">Automatisches Popup beim Start öffnen</strong>
                            <br><small class="text-muted">Öffnet das Briefing automatisch einmal pro Sitzung beim Aufruf des Dashboards. Wenn deaktiviert, kann es jederzeit manuell über das Sonnen-Symbol im Dashboard geöffnet werden.</small>
                        </div>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; font-weight: 500;">
                            <input type="checkbox" name="briefing_popup_enabled" value="1" <?= $isBriefingPopupEnabled ? 'checked' : '' ?> class="profile-checkbox">
                            <span>Aktiviert</span>
                        </label>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table stack-table">
                            <thead>
                            <tr>
                                <th>Widget</th>
                                <th style="width: 140px; text-align: center;">Reihenfolge</th>
                                <th class="profile-checkbox-cell">Aktiviert</th>
                            </tr>
                            </thead>
                            <tbody class="js-briefing-sortable-tbody">
                            <?php
                            $posIndex = 1;
                            foreach ($orderedBriefingWidgets as $widgetKey => $meta):
                                $widgetPref = $userBriefing[$widgetKey] ?? ['enabled' => true, 'order' => 50];
                                $isEnabled = !empty($widgetPref['enabled']);
                                $orderVal = (int)($widgetPref['order'] ?? ($posIndex * 10));
                            ?>
                                <tr class="js-briefing-sortable-row">
                                    <td data-label="Widget">
                                        <span class="profile-event-icon"><?= $meta['icon'] ?></span>
                                        <strong><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($meta['desc'], ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td data-label="Reihenfolge" style="text-align: center; white-space: nowrap;">
                                        <input type="hidden"
                                               name="briefing[<?= htmlspecialchars($widgetKey, ENT_QUOTES, 'UTF-8') ?>][order]"
                                               value="<?= $orderVal ?>"
                                               class="js-briefing-order-input">
                                        <div style="display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;">
                                            <span class="badge js-briefing-pos-badge" style="min-width: 2.2rem; font-weight: bold; background: var(--bg-card, #1e293b); border: 1px solid var(--border-color, #334155); color: var(--text-muted, #94a3b8); border-radius: 4px; padding: 0.2rem 0.4rem; font-size: 0.85rem;">#<?= $posIndex ?></span>
                                            <button type="button" class="btn-icon js-move-briefing-up" title="Nach oben verschieben" aria-label="Nach oben verschieben">⬆️</button>
                                            <button type="button" class="btn-icon js-move-briefing-down" title="Nach unten verschieben" aria-label="Nach unten verschieben">⬇️</button>
                                        </div>
                                    </td>
                                    <td data-label="Aktiviert" class="profile-checkbox-cell">
                                        <input type="checkbox"
                                               name="briefing[<?= htmlspecialchars($widgetKey, ENT_QUOTES, 'UTF-8') ?>][enabled]"
                                               value="1"
                                               <?= $isEnabled ? 'checked' : '' ?>
                                               class="profile-checkbox">
                                    </td>
                                </tr>
                            <?php
                                $posIndex++;
                            endforeach;
                            ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_briefing" value="1" class="btn btn-save">💾 Briefing-Einstellungen speichern</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>
</div>
<?php include __DIR__ . '/shared/footer_scripts.php'; ?>
<script src="js/http.js" defer></script>
<script src="js/push.js" defer></script>
<script src="js/profile.js?v=<?= APP_VERSION ?>" defer></script>
</body>
</html>

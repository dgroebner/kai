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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errorMessage = "Ungültiger CSRF-Token.";
    } else {
        $rawPreferences = $_POST['notifications'] ?? [];
        $currentPrefs = $userProfileRepo->getPreferences($currentUserEmail);
        $updatedPrefs = [];

        foreach ($currentPrefs as $key => $defaultValue) {
            $updatedPrefs[$key] = isset($rawPreferences[$key]) && (string)$rawPreferences[$key] === '1';
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

function getEventIcon(string $eventType): string
{
    return match ($eventType) {
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
            <div class="alert alert-success" style="margin-bottom: 1rem;"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger" style="margin-bottom: 1rem;"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

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

            <form action="profile.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

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
                                    <br><small class="text-muted"><?= htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8') ?></small>
                                </td>
                                <td data-label="Aktiviert" style="text-align: center;">
                                    <input type="checkbox" name="notifications[<?= htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $isEnabled ? 'checked' : '' ?> style="transform: scale(1.3);">
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
    </main>
</div>
<?php include __DIR__ . '/shared/footer_scripts.php'; ?>
<script src="js/http.js" defer></script>
</body>
</html>

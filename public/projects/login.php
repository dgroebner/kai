<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\Projects\Db\ProjectsDatabase;
use Kai\Tools\Projects\Mail\ProjectsMailer;
use Kai\Tools\Projects\Security\ProjectsAuth;
use Kai\Tools\Shared\Log\Logger;

$logger = new Logger(14);
$errorMsg = null;
$infoMsg = null;
$step = 'email'; // 'email' oder 'otp'
$emailValue = '';

// Falls bereits eingeloggt, direkt zur Hauptseite weiterleiten
if (ProjectsAuth::isAuthenticated()) {
    header('Location: index.php');
    exit;
}

// Falls der Plattform-Admin via Google OAuth eingeloggt ist, direkt weiterleiten
if (ProjectsAuth::isSuperAdmin()) {
    $return = $_GET['return'] ?? 'admin.php';
    if ($return !== 'admin.php' && $return !== 'index.php') {
        $return = 'admin.php';
    }
    header('Location: ' . $return);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'request_otp') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $emailValue = $email;

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Bitte gib eine gültige E-Mail-Adresse ein.';
        } else {
            try {
                $db = ProjectsDatabase::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT id, name, status FROM proj_users WHERE email = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    $adminEmail = strtolower(trim((string)($_ENV['ADMIN_EMAIL'] ?? '')));
                    $allowed = array_map('trim', explode(',', strtolower((string)($_ENV['ALLOWED_USERS'] ?? ''))));
                    $firstAllowed = !empty($allowed) ? $allowed[0] : '';

                    if (($adminEmail !== '' && hash_equals($adminEmail, $email)) || ($firstAllowed !== '' && hash_equals($firstAllowed, $email))) {
                        $ins = $db->prepare("INSERT INTO proj_users (email, name, status, state_code) VALUES (:email, 'Administrator', 'active', 'SN')");
                        $ins->execute([':email' => $email]);
                        $newId = (int)$db->lastInsertId();
                        $user = ['id' => $newId, 'name' => 'Administrator', 'status' => 'active'];
                    }
                }

                if (!$user) {
                    // Privacy: Keine Auskunft darüber, ob die E-Mail existiert
                    $infoMsg = 'Falls ein aktives Konto unter dieser E-Mail existiert, haben wir dir einen 6-stelligen Anmeldecode gesendet.';
                    $step = 'otp';
                } elseif ($user['status'] === 'disabled') {
                    $errorMsg = 'Dieses Benutzerkonto ist derzeit deaktiviert. Bitte wende dich an den Administrator.';
                } else {
                    $otp = ProjectsAuth::createOtp((int)$user['id']);
                    $mailer = new ProjectsMailer($logger);
                    $mailer->sendOtpCode($email, $otp, $user['name'] ?? null);

                    $infoMsg = "Wir haben einen 6-stelligen Anmeldecode an {$email} gesendet. Bitte prüfe dein Postfach.";
                    $step = 'otp';
                }
            } catch (Throwable $e) {
                $logger->error("Projects login.php: Fehler bei OTP-Anforderung", ['error' => $e->getMessage()]);
                $errorMsg = 'Der Anmeldecode konnte momentan nicht gesendet werden. Bitte versuche es später erneut.';
            }
        }
    } elseif ($action === 'verify_otp') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $code = trim((string)($_POST['otp_code'] ?? ''));
        $emailValue = $email;
        $step = 'otp';

        if (empty($email) || empty($code)) {
            $errorMsg = 'Bitte gib deinen 6-stelligen Code ein.';
        } else {
            try {
                $db = ProjectsDatabase::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT id, email, name, status, state_code FROM proj_users WHERE email = :email LIMIT 1");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || $user['status'] === 'disabled') {
                    $errorMsg = 'Ungültige Anmeldedaten oder deaktiviertes Konto.';
                } else {
                    $result = ProjectsAuth::verifyOtp((int)$user['id'], $code);
                    if ($result['success']) {
                        // Bei Erst-Login Status von 'invited' auf 'active' setzen
                        if ($user['status'] === 'invited') {
                            $up = $db->prepare("UPDATE proj_users SET status = 'active' WHERE id = :id");
                            $up->execute([':id' => $user['id']]);
                            $user['status'] = 'active';
                        }

                        ProjectsAuth::loginUser($user, true);
                        header('Location: index.php');
                        exit;
                    } else {
                        $errorMsg = $result['message'];
                    }
                }
            } catch (Throwable $e) {
                $logger->error("Projects login.php: Fehler bei OTP-Verifikation", ['error' => $e->getMessage()]);
                $errorMsg = 'Fehler beim Verifizieren des Codes.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Anmelden — Projects (Projektzeiterfassung)</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0284c7">
    <link rel="stylesheet" href="styles.css?v=<?= APP_VERSION ?>">
</head>
<body class="proj-login-body">
    <div class="proj-login-card">
        <div class="proj-login-header">
            <div class="proj-logo-badge">⏱️</div>
            <h1>Projects</h1>
            <p class="proj-subtitle">Projektzeiterfassung</p>
        </div>

        <?php if ($errorMsg): ?>
            <div class="proj-alert proj-alert-danger"><?= htmlspecialchars($errorMsg) ?></div>
        <?php endif; ?>

        <?php if ($infoMsg): ?>
            <div class="proj-alert proj-alert-info"><?= htmlspecialchars($infoMsg) ?></div>
        <?php endif; ?>

        <?php if ($step === 'email'): ?>
            <form method="POST" action="login.php" class="proj-form">
                <input type="hidden" name="action" value="request_otp">
                
                <div class="proj-form-group">
                    <label for="email">E-Mail-Adresse</label>
                    <input type="email" id="email" name="email" required autofocus
                           placeholder="vorname.nachname@firma.de"
                           value="<?= htmlspecialchars($emailValue) ?>"
                           autocomplete="email">
                </div>

                <button type="submit" class="proj-btn proj-btn-primary proj-btn-block">
                    Anmeldecode anfordern
                </button>
            </form>

            <div style="margin: 24px 0 16px; display: flex; align-items: center; text-align: center; color: var(--proj-text-muted);">
                <div style="flex: 1; border-bottom: 1px solid var(--proj-border, #334155);"></div>
                <span style="padding: 0 10px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em;">Portal-Administrator</span>
                <div style="flex: 1; border-bottom: 1px solid var(--proj-border, #334155);"></div>
            </div>

            <a href="<?= rtrim($_ENV['APP_URL'] ?? 'https://kai.agent-smith.de', '/') ?>/login.php?redirect=https://projects.agent-smith.de/<?= htmlspecialchars($_GET['return'] ?? 'admin.php') ?>"
               class="proj-btn proj-btn-secondary proj-btn-block" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;">
                <span>🔑</span>
                <span>Mit Google anmelden (Admin)</span>
            </a>
        <?php else: ?>
            <form method="POST" action="login.php" class="proj-form">
                <input type="hidden" name="action" value="verify_otp">
                <input type="hidden" name="email" value="<?= htmlspecialchars($emailValue) ?>">

                <div class="proj-form-group">
                    <label for="otp_code">6-stelliger Zahlencode</label>
                    <input type="text" id="otp_code" name="otp_code" required autofocus
                           maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                           placeholder="123456" class="proj-otp-input"
                           autocomplete="one-time-code">
                </div>

                <button type="submit" class="proj-btn proj-btn-primary proj-btn-block">
                    Anmelden
                </button>

                <div class="proj-login-switch">
                    <a href="login.php" class="proj-link-subtle">← Andere E-Mail-Adresse verwenden</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

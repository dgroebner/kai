<?php
require_once __DIR__ . '/../bootstrap.php';

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;
use Kai\Tools\System\UserProfileRepository;

$logger = new Logger();
$client = getGoogleClient();

// Logout-Logik
if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Callback von Google verarbeiten
if (isset($_GET['code'])) {
    // State-Parameter prüfen (Schutz vor Login-CSRF / Authorization-Code-Injection)
    $expectedState = $_SESSION['oauth_state'] ?? '';
    $receivedState = (string)($_GET['state'] ?? '');
    unset($_SESSION['oauth_state']);

    if ($expectedState === '' || !hash_equals($expectedState, $receivedState)) {
        $logger->error('login.php: OAuth-State-Prüfung fehlgeschlagen.');
        http_response_code(400);
        exit('Ungültige Login-Anfrage. Bitte erneut versuchen.');
    }

    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

        if (isset($token['error'])) {
            throw new RuntimeException((string)$token['error']);
        }

        $client->setAccessToken($token['access_token']);
        $googleOauth = new Google\Service\Oauth2($client);
        $accountInfo = $googleOauth->userinfo->get();
        $email = (string)$accountInfo->email;
        $name = (string)$accountInfo->name;

        // Autorisierung prüfen — Allowlist aus der Umgebung, normalisiert
        $allowedUsers = array_filter(array_map(
                static fn($entry) => strtolower(trim($entry)),
                explode(',', (string)($_ENV['ALLOWED_USERS'] ?? ''))
        ));

        if (in_array(strtolower($email), $allowedUsers, true)) {
            session_regenerate_id(true);
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name'] = $name;

            // --- BENUTZERPROFIL PRÜFEN / ANLEGEN ÜBER DOMÄNEN-REPOSITORY ---
            try {
                $profileRepo = new UserProfileRepository();
                $profileRepo->ensureProfileExists($email);
            } catch (Throwable $e) {
                // Login nicht blockieren, aber Fehler ins System-Log schreiben
                $logger->error('login.php: Fehler beim Auto-Provisioning des Benutzerprofils.', ['error' => $e->getMessage()]);
            }
            // -------------------------------------------------------------

            // Frischen CSRF-Token für die neue Session erzeugen
            unset($_SESSION['csrf_token']);
            Auth::csrfToken();

            header('Location: ' . APP_URL . '/index.php');
            exit;
        }

        $logger->error('login.php: Zugriff für nicht autorisierte E-Mail-Adresse abgelehnt.');
        http_response_code(403);
        exit('Zugriff verweigert. E-Mail-Adresse nicht autorisiert.');
    } catch (Throwable $e) {
        $logger->error('login.php: Fehler beim OAuth-Callback.', ['error' => $e->getMessage()]);
        http_response_code(500);
        exit('Anmeldung fehlgeschlagen. Bitte erneut versuchen.');
    }
}

// Bereits angemeldet? Direkt zum Dashboard
if (Auth::isAuthenticated()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// Login-Button mit frischem State-Parameter anzeigen
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));
$client->setState($_SESSION['oauth_state']);
$loginUrl = $client->createAuthUrl();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Tool-Set</title>
    <link rel="stylesheet" href="css/style.css?v=<?= APP_VERSION ?>">
    <?php include __DIR__ . '/shared/head-pwa.php'; ?>
</head>
<body class="login-body">
<div class="card login-card">
    <h2>Privates Tool-Set</h2>
    <p>Bitte melde dich an, um fortzufahren.</p>
    <a href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn login-btn">Login mit Google</a>
</div>
<!-- Globaler App Footer -->
<footer class="app-footer">
    <div>kai v<?= APP_VERSION ?></div>
</footer>
</body>
</html>

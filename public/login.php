<?php
require_once __DIR__ . '/../bootstrap.php';

$client = getGoogleClient();

// Logout-Logik
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: https://kai.agent-smith.de/login.php');
    exit;
}

// Callback von Google verarbeiten
if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if (!isset($token['error'])) {
        $client->setAccessToken($token['access_token']);
        $google_oauth = new Google\Service\Oauth2($client);
        $google_account_info = $google_oauth->userinfo->get();
        $email =  $google_account_info->email;
        $name =  $google_account_info->name;

        // Autorisierung prüfen
        $allowed_users = explode(',', $_ENV['ALLOWED_USERS']);
        if (in_array($email, $allowed_users)) {
			session_regenerate_id(true);
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name'] = $name;
            header('Location: https://kai.agent-smith.de/index.php');
            exit;
        } else {
            die("Zugriff verweigert. E-Mail-Adresse nicht autorisiert.");
        }
    }
}

// Wenn nicht eingeloggt, zeige Login-Button
if (!isset($_SESSION['user_email'])) {
    $login_url = $client->createAuthUrl();
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login | Tool-Set</title>
        <link rel="stylesheet" href="css/style.css">
        <style>
            /* Spezifisches zentriertes Layout nur für den Login */
            body { display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .login-card { text-align: center; max-width: 400px; width: 100%; }
            .login-card h2 { margin-bottom: 1.5rem; }
        </style>
    </head>
    <body>
        <div class="card login-card">
            <h2>Privates Tool-Set</h2>
            <p style="margin-bottom: 2rem;">Bitte melde dich an, um fortzufahren.</p>
            <a href="<?= filter_var($login_url, FILTER_SANITIZE_URL) ?>" class="btn">Login mit Google</a>
        </div>
    </body>
    </html>
    <?php
    exit;
} else {
    header('Location: https://kai.agent-smith.de/index.php');
    exit;
}
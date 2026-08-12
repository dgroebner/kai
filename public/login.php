<?php
require_once __DIR__ . '/../bootstrap.php';

$client = getGoogleClient();

// Logout-Logik
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . APP_URL . '/login.php');
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
            header('Location: ' . APP_URL . '/index.php');
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
		<link rel="stylesheet" href="css/style.css?v=<?= APP_VERSION ?>">
    </head>
    <body class="login-body">
        <div class="card login-card">
            <h2>Privates Tool-Set</h2>
            <p>Bitte melde dich an, um fortzufahren.</p>
            <a href="<?= filter_var($login_url, FILTER_SANITIZE_URL) ?>" class="btn">Login mit Google</a>
        </div>
    </body>
    </html>
    <?php
    exit;
} else {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}
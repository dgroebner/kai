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
    echo "<div style='font-family: sans-serif; text-align: center; margin-top: 50px;'>";
    echo "<h2>Privates Tool-Set</h2>";
    echo "<a href='" . filter_var($login_url, FILTER_SANITIZE_URL) . "' style='padding: 10px 20px; background-color: #4285F4; color: white; text-decoration: none; border-radius: 5px;'>Login mit Google</a>";
    echo "</div>";
    exit;
} else {
    header('Location: https://kai.agent-smith.de/index.php');
    exit;
}
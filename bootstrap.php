<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Verhindert, dass JavaScript auf das Session-Cookie zugreifen kann (Schutz vor XSS)
ini_set('session.cookie_httponly', 1);

// Sendet das Session-Cookie nur über verschlüsselte HTTPS-Verbindungen
ini_set('session.cookie_secure', 1);

// Verhindert, dass das Cookie bei Cross-Site-Requests gesendet wird (Schutz vor CSRF)
ini_set('session.cookie_samesite', 'Strict');

session_start();

function getGoogleClient() {
    $client = new Google\Client();
    $client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
    $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
    $client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);
    $client->addScope("email");
    $client->addScope("profile");
    return $client;
}
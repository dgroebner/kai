<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

const APP_VERSION = '1.5.14';
define('APP_URL', rtrim($_ENV['APP_URL'] ?? 'https://kai.agent-smith.de', '/'));

// Verhindert, dass JavaScript auf das Session-Cookie zugreifen kann (Schutz vor XSS)
ini_set('session.cookie_httponly', 1);

// Sendet das Session-Cookie nur über verschlüsselte HTTPS-Verbindungen
ini_set('session.cookie_secure', 1);

// Verhindert, dass das Cookie bei Cross-Site-Requests gesendet wird.
// 'Lax' statt 'Strict', weil der OAuth-Callback von Google als Cross-Site-Navigation
// eintrifft und die Session (inkl. state-Parameter) dabei erhalten bleiben muss.
// Cross-Site-POSTs bleiben blockiert; state-verändernde Requests sind zusätzlich
// über CSRF-Token (Auth::requireCsrfToken) abgesichert.
ini_set('session.cookie_samesite', 'Lax');

// Verhindert, dass PHP eine vom Client vorgegebene, nicht initialisierte Session-ID übernimmt
ini_set('session.use_strict_mode', 1);

// Session-IDs ausschließlich über Cookies transportieren (keine URL-Parameter)
ini_set('session.use_only_cookies', 1);

// Interne Fehler dürfen im Produktivbetrieb niemals an den Browser gelangen
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

session_start();

/**
 * Globaler Fallback für nicht abgefangene Ausnahmen.
 * Der technische Fehler landet ausschließlich im internen Log, der Browser
 * erhält eine generische Meldung ohne Stacktrace oder Pfadangaben.
 */
set_exception_handler(function (Throwable $e): void {
    try {
        (new Kai\Tools\Shared\Log\Logger())->error('Unbehandelte Ausnahme.', [
            'endpoint' => $_SERVER['SCRIPT_NAME'] ?? 'cli',
            'error' => $e->getMessage(),
        ]);
    } catch (Throwable $ignored) {
        // Logging darf niemals selbst zu einer Ausgabe führen
    }

    if (headers_sent()) {
        return;
    }

    http_response_code(500);
    $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Interner Fehler']);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo 'Interner Fehler. Bitte versuche es sp&auml;ter erneut.';
});

function getGoogleClient()
{
    $client = new Google\Client();
    $client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
    $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
    $client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);
    $client->addScope("email");
    $client->addScope("profile");
    return $client;
}
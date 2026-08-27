<?php

namespace Kai\Tools\Shared\Security;

use JetBrains\PhpStorm\NoReturn;
use Kai\Tools\Shared\Log\Logger;
use Random\RandomException;

/**
 * Zentrale Zugriffskontrolle für alle Einstiegspunkte in public/.
 *
 * Bündelt Session-Prüfung, CSRF-Token-Handling und die Absicherung von
 * Cronjob-Endpunkten, damit jede Domain identische Sicherheitsgarantien besitzt.
 */
final class Auth
{

    private function __construct()
    {
    }

    /**
     * Schützt HTML-Seiten: leitet nicht angemeldete Besucher zum Login um.
     */
    public static function requirePage(): void
    {
        if (!self::isAuthenticated()) {
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }
    }

    /**
     * Prüft, ob eine authentifizierte Session existiert.
     */
    public static function isAuthenticated(): bool
    {
        return isset($_SESSION['user_email']) && $_SESSION['user_email'] !== '';
    }

    /**
     * Schützt JSON-Endpunkte: antwortet mit 401 statt einer Weiterleitung.
     */
    public static function requireApi(): void
    {
        if (!self::isAuthenticated()) {
            self::sendJsonError(401, 'Nicht angemeldet');
        }
    }

    /**
     * Beendet den Request mit einer generischen JSON-Fehlerantwort.
     */
    #[NoReturn]
    public static function sendJsonError(int $status, string $message): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => $message, 'error' => $message]);
        exit;
    }

    /**
     * Erzwingt eine bestimmte HTTP-Methode für einen JSON-Endpunkt.
     */
    public static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
            header('Allow: ' . strtoupper($method));
            self::sendJsonError(405, 'Method Not Allowed');
        }
    }

    /**
     * Liefert den CSRF-Token der aktuellen Session und erzeugt ihn bei Bedarf.
     * @throws RandomException
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Erzwingt einen gültigen CSRF-Token für state-verändernde JSON-Endpunkte.
     * Der Token wird aus dem Header X-CSRF-Token oder dem JSON-Body gelesen.
     */
    public static function requireCsrfToken(?array $payload = null): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if ($token === null && function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $name => $value) {
                if (strtolower((string)$name) === 'x-csrf-token' && is_string($value) && $value !== '') {
                    $token = $value;
                    break;
                }
            }
        }

        if ($token === null && is_array($payload)) {
            $token = $payload['csrf_token'] ?? null;
        }

        if (!self::isValidCsrfToken(is_string($token) ? $token : null)) {
            new Logger()->error('Auth: CSRF-Prüfung fehlgeschlagen.', [
                'endpoint' => $_SERVER['SCRIPT_NAME'] ?? 'unbekannt',
            ]);
            self::sendJsonError(403, 'Ungültiger CSRF-Token');
        }
    }

    /**
     * Prüft einen übergebenen CSRF-Token zeitkonstant gegen die Session.
     */
    public static function isValidCsrfToken(?string $token): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? '';

        return is_string($token)
            && $token !== ''
            && $sessionToken !== ''
            && hash_equals($sessionToken, $token);
    }

    /**
     * Schützt Cronjob-Endpunkte über den CRON_TOKEN aus der Umgebung.
     * Der Token darf per Query-Parameter, X-API-Key- oder Bearer-Header kommen.
     */
    public static function requireCronToken(?string $context = null): void
    {
        if (!self::cronTokenMatches()) {
            new Logger()->error('Auth: Unbefugter Zugriff auf geschützten Endpunkt.', [
                'endpoint' => $context ?? ($_SERVER['SCRIPT_NAME'] ?? 'unbekannt'),
            ]);
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo "Zugriff verweigert.\n";
            exit;
        }
    }

    /**
     * Prüft zeitkonstant, ob der übermittelte Cron-/API-Token gültig ist.
     *
     * @param bool $allowQueryParam Ob der Token auch als ?token=… akzeptiert wird.
     */
    public static function cronTokenMatches(bool $allowQueryParam = true): bool
    {
        $expected = (string)($_ENV['CRON_TOKEN'] ?? '');
        $received = self::extractCronToken($allowQueryParam);

        return $expected !== '' && $received !== null && hash_equals($expected, $received);
    }

    /**
     * Liest den Cron-Token aus Query-String oder den gängigen Auth-Headern aus.
     */
    private static function extractCronToken(bool $allowQueryParam = true): ?string
    {
        $candidates = [
            $_SERVER['HTTP_X_API_KEY'] ?? null,
        ];

        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        // Apache reicht den Authorization-Header ohne CGIPassAuth nicht an $_SERVER
        // weiter — deshalb zusätzlich die Roh-Header des Requests auswerten.
        if (function_exists('getallheaders')) {
            foreach ((array)getallheaders() as $name => $value) {
                $lowerName = strtolower((string)$name);
                if ($lowerName === 'x-api-key') {
                    $candidates[] = $value;
                } elseif ($lowerName === 'authorization' && $authHeader === '') {
                    $authHeader = (string)$value;
                }
            }
        }

        if ($authHeader !== '' && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches) === 1) {
            $candidates[] = $matches[1];
        }

        if ($allowQueryParam) {
            $candidates[] = $_GET['token'] ?? null;
        }

        return array_find($candidates, static fn($candidate): bool => is_string($candidate) && $candidate !== '');
    }
}

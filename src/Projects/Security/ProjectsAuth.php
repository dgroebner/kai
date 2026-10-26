<?php

namespace Kai\Tools\Projects\Security;

use JetBrains\PhpStorm\NoReturn;
use Kai\Tools\Projects\Db\ProjectsDatabase;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

/**
 * Isolierte Authentifizierung und Session-Verwaltung für das Projects-Modul.
 *
 * Garantiert strikte Trennung:
 * - Projects-Nutzer haben KEINEN Zugriff auf Kai-Kern-Domains.
 * - Nutzt $_SESSION['proj_user_id'] und $_SESSION['proj_user_email'].
 * - Unterstützt 30-Tage gehärtete Remember-Cookies (proj_remember).
 */
final class ProjectsAuth
{
    private const string COOKIE_NAME = 'proj_remember';

    private function __construct()
    {
    }

    /**
     * Schützt HTML-Seiten des Projects-Moduls.
     * Leitet nicht angemeldete Besucher zum Projects-Login um.
     */
    public static function requirePage(): void
    {
        self::tryRestoreSessionFromCookie();

        if (!self::isAuthenticated() && self::isSuperAdmin()) {
            self::ensureSuperAdminProjectsSession();
        }

        if (!self::isAuthenticated()) {
            $redirectUrl = self::getAppUrl() . '/login.php';
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    /**
     * Schützt JSON-Endpunkte des Projects-Moduls (antwortet mit 401).
     */
    public static function requireApi(): void
    {
        self::tryRestoreSessionFromCookie();

        if (!self::isAuthenticated()) {
            self::sendJsonError(401, 'Nicht angemeldet bei Projects');
        }
    }

    /**
     * Prüft, ob eine aktive Projects-Session vorliegt.
     */
    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['proj_user_id']) && !empty($_SESSION['proj_user_email']);
    }

    /**
     * Gibt die ID des aktuell angemeldeten Projects-Nutzers zurück.
     */
    public static function getUserId(): int
    {
        return (int)($_SESSION['proj_user_id'] ?? 0);
    }

    /**
     * Gibt die E-Mail-Adresse des aktuell angemeldeten Projects-Nutzers zurück.
     */
    public static function getUserEmail(): string
    {
        return (string)($_SESSION['proj_user_email'] ?? '');
    }

    /**
     * Erzwingt eine bestimmte HTTP-Methode.
     */
    public static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
            header('Allow: ' . strtoupper($method));
            self::sendJsonError(405, 'Method Not Allowed');
        }
    }

    /**
     * CSRF-Token für Formulare & Meta-Tag.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['proj_csrf_token'])) {
            $_SESSION['proj_csrf_token'] = bin2hex(random_bytes(32));
        }
        // Mit Kai-Hauptsession synchronisieren für nahtlose Kompatibilität
        $_SESSION['csrf_token'] = $_SESSION['proj_csrf_token'];
        return $_SESSION['proj_csrf_token'];
    }

    /**
     * Prüft das CSRF-Token.
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

        $sessionToken = $_SESSION['proj_csrf_token'] ?? $_SESSION['csrf_token'] ?? '';
        $valid = (is_string($token) && $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token));

        if (!$valid) {
            (new Logger(14))->error('Projects CSRF-Mismatch', [
                'endpoint' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
                'token_received' => is_string($token) ? substr($token, 0, 8) . '...' : 'NULL',
                'session_token' => $sessionToken !== '' ? substr($sessionToken, 0, 8) . '...' : 'EMPTY',
                'session_id' => session_id(),
                'session_name' => session_name(),
                'cookie_names' => array_keys($_COOKIE),
            ]);
            self::sendJsonError(403, 'Ungültiger CSRF-Token');
        }
    }

    /**
     * Setzt die Session nach erfolgreicher Authentifizierung.
     */
    public static function loginUser(array $user, bool $setRememberCookie = true): void
    {
        session_regenerate_id(true);

        $_SESSION['proj_user_id'] = (int)$user['id'];
        $_SESSION['proj_user_email'] = (string)$user['email'];
        $_SESSION['proj_user_name'] = (string)($user['name'] ?? '');
        $_SESSION['proj_user_state'] = (string)($user['state_code'] ?? 'SN');
        if (empty($_SESSION['proj_csrf_token'])) {
            $_SESSION['proj_csrf_token'] = bin2hex(random_bytes(32));
        }
        if (empty($_SESSION['proj_csrf_token'])) {
            $_SESSION['proj_csrf_token'] = bin2hex(random_bytes(32));
        }

        if ($setRememberCookie) {
            self::createRememberToken((int)$user['id']);
        }
    }

    /**
     * Beendet die Projects-Session und löscht das Remember-Cookie.
     */
    public static function logout(): void
    {
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            self::deleteRememberToken($_COOKIE[self::COOKIE_NAME]);
            setcookie(self::COOKIE_NAME, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        unset(
            $_SESSION['proj_user_id'],
            $_SESSION['proj_user_email'],
            $_SESSION['proj_user_name'],
            $_SESSION['proj_user_state'],
            $_SESSION['proj_csrf_token']
        );
    }

    /**
     * Prüft, ob der angemeldete Nutzer der Super-Admin ist (für /projects/admin.php).
     * Unterstützt sowohl die eigenständige Projects-OTP-Session (proj_user_email)
     * als auch die übergeordnete Kai-OAuth-Session (user_email).
     */
    /**
     * Stellt sicher, dass für einen über Google OAuth angemeldeten Plattform-Administrator
     * automatisch ein Datensatz in proj_users existiert und die Projects-Session gesetzt ist.
     */
    public static function ensureSuperAdminProjectsSession(): void
    {
        $adminEmail = strtolower(trim((string)($_SESSION['user_email'] ?? '')));
        if ($adminEmail === '') {
            return;
        }

        try {
            $db = ProjectsDatabase::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT id, email, name, status, state_code FROM proj_users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $adminEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $name = (string)($_SESSION['user_name'] ?? 'Administrator');
                $ins = $db->prepare("INSERT INTO proj_users (email, name, status, state_code) VALUES (:email, :name, 'active', 'SN')");
                $ins->execute([':email' => $adminEmail, ':name' => $name]);
                $user = [
                    'id' => (int)$db->lastInsertId(),
                    'email' => $adminEmail,
                    'name' => $name,
                    'status' => 'active',
                    'state_code' => 'SN',
                ];
            }

            self::loginUser($user, false);
        } catch (Throwable) {
            // Leise ignorieren
        }
    }

        /**
     * Erzeugt einen kurzlebigen (60s) kryptografisch signierten SSO-Token
     * für den nahtlosen Übergang von kai.agent-smith.de zu projects.agent-smith.de.
     */
    public static function createSsoToken(string $email): string
    {
        $secret = (string)($_ENV['PROJECTS_OTP_PEPPER'] ?? $_ENV['CRON_TOKEN'] ?? 'projects-sso-salt-2026');
        $payload = json_encode([
            'email' => strtolower(trim($email)),
            'time' => time(),
            'nonce' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        $sig = hash_hmac('sha256', $payload, $secret);
        return rtrim(strtr(base64_encode($payload . ':::' . $sig), '+/', '-_'), '=');
    }

    /**
     * Verifiziert einen SSO-Token und liefert die E-Mail-Adresse zurück,
     * sofern Signatur gültig, maximal 60 Sekunden alt und Admin-berechtigt.
     */
    public static function verifySsoToken(string $token): ?string
    {
        $secret = (string)($_ENV['PROJECTS_OTP_PEPPER'] ?? $_ENV['CRON_TOKEN'] ?? 'projects-sso-salt-2026');
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false || !str_contains($decoded, ':::')) {
            return null;
        }

        [$payload, $sig] = explode(':::' , $decoded, 2);
        $expectedSig = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSig, $sig)) {
            return null;
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $time = (int)($data['time'] ?? 0);
        if (abs(time() - $time) > 60) {
            return null;
        }

        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $adminEmail = strtolower(trim((string)($_ENV['ADMIN_EMAIL'] ?? '')));
        $allowed = array_map('trim', explode(',', strtolower((string)($_ENV['ALLOWED_USERS'] ?? ''))));

        if (($adminEmail !== '' && hash_equals($adminEmail, $email)) || (!empty($allowed) && $allowed[0] === $email)) {
            return $email;
        }

        return null;
    }

    public static function isSuperAdmin(): bool
    {
        $adminEmail = strtolower(trim((string)($_ENV['ADMIN_EMAIL'] ?? '')));
        $projUser = strtolower(trim((string)($_SESSION['proj_user_email'] ?? '')));
        $kaiUser = strtolower(trim((string)($_SESSION['user_email'] ?? '')));

        if ($adminEmail !== '') {
            if ($projUser !== '' && hash_equals($adminEmail, $projUser)) {
                return true;
            }
            if ($kaiUser !== '' && hash_equals($adminEmail, $kaiUser)) {
                return true;
            }
        }

        // Fallback: Ist in ALLOWED_USERS an erster Stelle
        $allowed = array_map('trim', explode(',', strtolower((string)($_ENV['ALLOWED_USERS'] ?? ''))));
        $firstAllowed = $allowed[0] ?? '';
        if ($firstAllowed !== '') {
            if ($projUser !== '' && $firstAllowed === $projUser) {
                return true;
            }
            if ($kaiUser !== '' && $firstAllowed === $kaiUser) {
                return true;
            }
        }

        return false;
    }

    /**
     * Schützt Super-Admin-Endpunkte (Prüft Projects- oder Kai-Session gegen ADMIN_EMAIL).
     */
    public static function requireSuperAdminPage(): void
    {
        self::tryRestoreSessionFromCookie();

        if (empty($_SESSION['proj_user_email']) && empty($_SESSION['user_email'])) {
            header('Location: login.php?return=admin.php');
            exit;
        }

        if (!self::isSuperAdmin()) {
            http_response_code(403);
            echo "Zugriff verweigert: Diese Seite ist ausschließlich für den Plattform-Administrator reserviert.";
            exit;
        }
    }

    /**
     * Schützt Super-Admin-API-Endpunkte.
     */
    public static function requireSuperAdminApi(): void
    {
        self::tryRestoreSessionFromCookie();

        if (empty($_SESSION['proj_user_email']) && empty($_SESSION['user_email'])) {
            self::sendJsonError(401, 'Nicht angemeldet');
        }

        if (!self::isSuperAdmin()) {
            self::sendJsonError(403, 'Ausschließlich für den Administrator zugänglich');
        }
    }

    /**
     * Erstellt einen gehashten OTP-Code und speichert ihn in proj_auth_tokens.
     */
    public static function createOtp(int $userId): string
    {
        $db = ProjectsDatabase::getInstance()->getConnection();

        // 6-stelligen numerischen Code generieren
        $code = sprintf('%06d', random_int(100000, 999999));
        $hash = self::hashOtp($code);

        // Vorherige ungenutzte OTPs dieses Users entwerten
        $delStmt = $db->prepare("DELETE FROM proj_auth_tokens WHERE user_id = :uid AND token_type = 'otp'");
        $delStmt->execute([':uid' => $userId]);

        $insStmt = $db->prepare("
            INSERT INTO proj_auth_tokens (user_id, token_type, token_hash, attempts, expires_at, created_at)
            VALUES (:uid, 'otp', :hash, 0, DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW())
        ");
        $insStmt->execute([
            ':uid' => $userId,
            ':hash' => $hash,
        ]);

        return $code;
    }

    /**
     * Verifiziert einen eingegebenen 6-stelligen Code.
     *
     * @return array{success: bool, message: string}
     */
    public static function verifyOtp(int $userId, string $enteredCode): array
    {
        $cleanCode = preg_replace('/\D/', '', $enteredCode);
        if (strlen($cleanCode) !== 6) {
            return ['success' => false, 'message' => 'Bitte gib einen 6-stelligen Zahlencode ein.'];
        }

        $db = ProjectsDatabase::getInstance()->getConnection();

        $stmt = $db->prepare("
            SELECT id, token_hash, attempts, expires_at
            FROM proj_auth_tokens
            WHERE user_id = :uid AND token_type = 'otp'
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenRow) {
            return ['success' => false, 'message' => 'Kein aktiver Anmeldecode gefunden. Bitte fordere einen neuen an.'];
        }

        if (strtotime($tokenRow['expires_at']) < time()) {
            $delStmt = $db->prepare("DELETE FROM proj_auth_tokens WHERE id = :id");
            $delStmt->execute([':id' => $tokenRow['id']]);
            return ['success' => false, 'message' => 'Der Anmeldecode ist abgelaufen. Bitte fordere einen neuen an.'];
        }

        if ((int)$tokenRow['attempts'] >= 5) {
            $delStmt = $db->prepare("DELETE FROM proj_auth_tokens WHERE id = :id");
            $delStmt->execute([':id' => $tokenRow['id']]);
            return ['success' => false, 'message' => 'Zu viele Fehlversuche. Der Code wurde gesperrt. Bitte fordere einen neuen an.'];
        }

        $expectedHash = $tokenRow['token_hash'];
        $actualHash = self::hashOtp($cleanCode);

        if (!hash_equals($expectedHash, $actualHash)) {
            // Fehlversuch hochzählen
            $upStmt = $db->prepare("UPDATE proj_auth_tokens SET attempts = attempts + 1 WHERE id = :id");
            $upStmt->execute([':id' => $tokenRow['id']]);
            return ['success' => false, 'message' => 'Ungültiger Anmeldecode. Bitte überprüfe deine Eingabe.'];
        }

        // Code erfolgreich eingelöst -> Token löschen
        $delStmt = $db->prepare("DELETE FROM proj_auth_tokens WHERE id = :id");
        $delStmt->execute([':id' => $tokenRow['id']]);

        return ['success' => true, 'message' => 'Code erfolgreich verifiziert.'];
    }

    /**
     * Erstellt ein langlebiges Remember-Token (30 Tage) und setzt das Cookie.
     */
    private static function createRememberToken(int $userId): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $rawToken);

        $db = ProjectsDatabase::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO proj_auth_tokens (user_id, token_type, token_hash, attempts, expires_at, created_at)
            VALUES (:uid, 'session', :hash, 0, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())
        ");
        $stmt->execute([
            ':uid' => $userId,
            ':hash' => $hash,
        ]);

        $cookieLifetime = 30 * 86400;
        setcookie(self::COOKIE_NAME, $rawToken, [
            'expires' => time() + $cookieLifetime,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Stellt die Session aus dem Remember-Cookie wieder her, falls vorhanden.
     */
    private static function tryRestoreSessionFromCookie(): void
    {
        if (self::isAuthenticated() || empty($_COOKIE[self::COOKIE_NAME])) {
            return;
        }

        $rawToken = (string)$_COOKIE[self::COOKIE_NAME];
        if (strlen($rawToken) < 32) {
            return;
        }

        $hash = hash('sha256', $rawToken);

        try {
            $db = ProjectsDatabase::getInstance()->getConnection();
            $stmt = $db->prepare("
                SELECT t.id, t.user_id, u.email, u.name, u.status, u.state_code
                FROM proj_auth_tokens t
                JOIN proj_users u ON u.id = t.user_id
                WHERE t.token_type = 'session'
                  AND t.token_hash = :hash
                  AND t.expires_at > NOW()
                  AND u.status = 'active'
                LIMIT 1
            ");
            $stmt->execute([':hash' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $_SESSION['proj_user_id'] = (int)$row['user_id'];
                $_SESSION['proj_user_email'] = (string)$row['email'];
                $_SESSION['proj_user_name'] = (string)($row['name'] ?? '');
                $_SESSION['proj_user_state'] = (string)($row['state_code'] ?? 'SN');
                if (empty($_SESSION['proj_csrf_token'])) {
                    $_SESSION['proj_csrf_token'] = bin2hex(random_bytes(32));
                }
                if (empty($_SESSION['proj_csrf_token'])) {
                    $_SESSION['proj_csrf_token'] = bin2hex(random_bytes(32));
                }
            } else {
                // Ungültiges Cookie verwerfen
                setcookie(self::COOKIE_NAME, '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
        } catch (Throwable) {
            // Bei DB-Ausfall leise fehlschlagen
        }
    }

    private static function deleteRememberToken(string $rawToken): void
    {
        try {
            $hash = hash('sha256', $rawToken);
            $db = ProjectsDatabase::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM proj_auth_tokens WHERE token_type = 'session' AND token_hash = :hash");
            $stmt->execute([':hash' => $hash]);
        } catch (Throwable) {}
    }

    private static function hashOtp(string $code): string
    {
        $pepper = (string)($_ENV['PROJECTS_OTP_PEPPER'] ?? 'projects-default-salt-pepper-2026');
        return hash_hmac('sha256', $code, $pepper);
    }

    public static function getAppUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (str_starts_with($host, 'projects.')) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return "{$proto}://{$host}";
        }
        return rtrim($_ENV['APP_URL'] ?? 'https://kai.agent-smith.de', '/') . '/projects';
    }

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
}

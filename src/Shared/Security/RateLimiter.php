<?php

declare(strict_types=1);

namespace Kai\Tools\Shared\Security;

use Kai\Tools\Shared\Db\Database;
use PDO;

class RateLimiter
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /**
     * Ermittelt einen sicheren SHA-256-Hash der Client-IP (DSGVO-konform, nicht rückrechenbar).
     */
    public function getAnonymizedIpHash(): string
    {
        $rawIp = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';

        // Bei mehreren kommagetrennten IPs die erste (Client-IP) nehmen
        if (str_contains($rawIp, ',')) {
            $parts = explode(',', $rawIp);
            $rawIp = trim($parts[0]);
        }

        $salt = $_ENV['BANK_ENCRYPTION_KEY'] ?? $_ENV['CRON_TOKEN'] ?? 'kai_games_salt_2026';
        return hash('sha256', $rawIp . ':' . $salt);
    }

    /**
     * Prüft, ob die IP für eine bestimmte Aktion aktuell gesperrt ist.
     * Gibt true zurück, wenn die Anfrage blockiert werden MUSS.
     */
    public function isBlocked(string $actionKey): bool
    {
        $ipHash = $this->getAnonymizedIpHash();

        $stmt = $this->pdo->prepare('
            SELECT locked_until
            FROM games_hub_rate_limits
            WHERE action_key = :action_key AND ip_hash = :ip_hash
        ');
        $stmt->execute([
            ':action_key' => $actionKey,
            ':ip_hash' => $ipHash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['locked_until'])) {
            return false;
        }

        $lockedUntil = strtotime((string)$row['locked_until']);
        return $lockedUntil > time();
    }

    /**
     * Liefert die verbleibende Sperrzeit in Sekunden (oder 0, falls nicht gesperrt).
     */
    public function getRemainingLockSeconds(string $actionKey): int
    {
        $ipHash = $this->getAnonymizedIpHash();

        $stmt = $this->pdo->prepare('
            SELECT locked_until
            FROM games_hub_rate_limits
            WHERE action_key = :action_key AND ip_hash = :ip_hash
        ');
        $stmt->execute([
            ':action_key' => $actionKey,
            ':ip_hash' => $ipHash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['locked_until'])) {
            return 0;
        }

        $diff = strtotime((string)$row['locked_until']) - time();
        return max(0, $diff);
    }

    /**
     * Registriert einen Fehlversuch. Sperrt bei Überschreiten des Limits progressiv:
     * - Ab $maxAttempts: 15 Minuten Sperre
     * - Bei jedem weiteren Versuch: Verdopplung / 60 Min / 24h
     */
    public function recordFailure(string $actionKey, int $maxAttempts = 5, int $initialLockMinutes = 15): void
    {
        $ipHash = $this->getAnonymizedIpHash();

        $stmt = $this->pdo->prepare('
            SELECT failed_attempts
            FROM games_hub_rate_limits
            WHERE action_key = :action_key AND ip_hash = :ip_hash
        ');
        $stmt->execute([
            ':action_key' => $actionKey,
            ':ip_hash' => $ipHash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $attempts = ($row ? (int)$row['failed_attempts'] : 0) + 1;

        $lockedUntilSql = null;
        if ($attempts >= $maxAttempts) {
            $multiplier = max(1, $attempts - $maxAttempts + 1);
            $lockMinutes = min(1440, $initialLockMinutes * $multiplier); // max. 24h
            $lockedUntilSql = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
        }

        $upsert = $this->pdo->prepare('
            INSERT INTO games_hub_rate_limits (action_key, ip_hash, failed_attempts, locked_until, first_attempt_at, last_attempt_at)
            VALUES (:action_key, :ip_hash, :attempts, :locked_until, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                failed_attempts = :attempts_up,
                locked_until = COALESCE(:locked_until_up, locked_until),
                last_attempt_at = NOW()
        ');
        $upsert->execute([
            ':action_key' => $actionKey,
            ':ip_hash' => $ipHash,
            ':attempts' => $attempts,
            ':locked_until' => $lockedUntilSql,
            ':attempts_up' => $attempts,
            ':locked_until_up' => $lockedUntilSql,
        ]);

        // Künstliche Tarpit-Verzögerung gegen automatisierte Skripte (800ms)
        usleep(800000);
    }

    /**
     * Setzt die Zähler nach erfolgreicher Autorisierung zurück.
     */
    public function recordSuccess(string $actionKey): void
    {
        $ipHash = $this->getAnonymizedIpHash();
        $stmt = $this->pdo->prepare('
            DELETE FROM games_hub_rate_limits
            WHERE action_key = :action_key AND ip_hash = :ip_hash
        ');
        $stmt->execute([
            ':action_key' => $actionKey,
            ':ip_hash' => $ipHash,
        ]);
    }
}

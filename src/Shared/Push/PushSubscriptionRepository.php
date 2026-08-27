<?php

namespace Kai\Tools\Shared\Push;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Verwaltet Web-Push-Subscriptions in der Tabelle push_subscriptions.
 */
class PushSubscriptionRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Speichert eine neue Subscription oder aktualisiert eine bestehende (anhand des Endpoints).
     */
    public function upsert(string $userEmail, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (user_email, endpoint, p256dh, auth, user_agent, created_at)
            VALUES (:user_email, :endpoint, :p256dh, :auth, :user_agent, NOW())
            ON DUPLICATE KEY UPDATE
                user_email = VALUES(user_email),
                p256dh     = VALUES(p256dh),
                auth       = VALUES(auth),
                user_agent = VALUES(user_agent)
        ");
        $stmt->execute([
            'user_email' => $userEmail,
            'endpoint'   => $endpoint,
            'p256dh'     => $p256dh,
            'auth'       => $auth,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Löscht eine Subscription anhand des Endpoints.
     */
    public function deleteByEndpoint(string $endpoint): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = :endpoint");
        $stmt->execute(['endpoint' => $endpoint]);
    }

    /**
     * Gibt alle Subscriptions eines Benutzers zurück.
     *
     * @return array<array{id: int, endpoint: string, p256dh: string, auth: string}>
     */
    public function findByEmail(string $userEmail): array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            SELECT id, endpoint, p256dh, auth
            FROM push_subscriptions
            WHERE user_email = :user_email
        ");
        $stmt->execute(['user_email' => $userEmail]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Prüft, ob ein Benutzer mindestens eine aktive Push-Subscription hat.
     */
    public function hasSubscription(string $userEmail): bool
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM push_subscriptions WHERE user_email = :user_email
        ");
        $stmt->execute(['user_email' => $userEmail]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Gibt alle Benutzer-E-Mails zurück, die Push-Subscriptions haben.
     *
     * @return string[]
     */
    public function findAllSubscribedEmails(): array
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->query("SELECT DISTINCT user_email FROM push_subscriptions");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

<?php

namespace Kai\Tools\Projects;

use DateTimeImmutable;
use Kai\Tools\Projects\Db\ProjectsDatabase;
use Kai\Tools\Shared\Log\Logger;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use PDO;
use Throwable;

/**
 * Dedizierter Web-Push-Service für das Projects-Modul.
 * Verwendet ausschließlich die Tabelle proj_push_subscriptions in der zweiten Datenbank.
 */
class ProjectsPushService
{
    private PDO $pdo;
    private Logger $logger;
    private ProjectsEngine $engine;

    public function __construct(?PDO $pdo = null, ?Logger $logger = null)
    {
        $this->pdo = $pdo ?? ProjectsDatabase::getInstance()->getConnection();
        $this->logger = $logger ?? new Logger(14);
        $this->engine = new ProjectsEngine(new ProjectsRepository($this->pdo));
    }

    public function saveSubscription(int $userId, string $endpoint, string $p256dh, string $auth, ?string $userAgent = null): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO proj_push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at)
            VALUES (:uid, :endpoint, :p256dh, :auth, :ua, NOW())
            ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                user_agent = VALUES(user_agent)
        ");
        return $stmt->execute([
            ':uid' => $userId,
            ':endpoint' => $endpoint,
            ':p256dh' => $p256dh,
            ':auth' => $auth,
            ':ua' => $userAgent,
        ]);
    }

    public function deleteSubscription(string $endpoint): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM proj_push_subscriptions WHERE endpoint = :endpoint");
        return $stmt->execute([':endpoint' => $endpoint]);
    }

    /**
     * Prüft alle fälligen Feierabend-Reminder und sendet sie ab.
     *
     * @return int Anzahl versendeter Benachrichtigungen
     */
    public function processDueReminders(): int
    {
        $today = date('Y-m-d');
        $currentTime = date('H:i:00');

        $stmt = $this->pdo->prepare("
            SELECT id, email, name, state_code, reminder_time
            FROM proj_users
            WHERE status = 'active'
              AND reminder_enabled = 1
              AND (last_reminder_sent_date IS NULL OR last_reminder_sent_date < :today)
              AND reminder_time <= :curtime
        ");
        $stmt->execute([
            ':today' => $today,
            ':curtime' => $currentTime,
        ]);
        $dueUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($dueUsers)) {
            return 0;
        }

        $sentCount = 0;

        foreach ($dueUsers as $user) {
            $userId = (int)$user['id'];
            $stateCode = $user['state_code'] ?? 'SN';

            // Tagessituation prüfen (Wochenende oder Feiertag = kein Reminder)
            $dayDetails = $this->engine->getDayDetails($userId, $today, $stateCode);
            if ($dayDetails['is_weekend'] || $dayDetails['is_holiday']) {
                continue;
            }

            // Push-Subscriptions für diesen User laden
            $subStmt = $this->pdo->prepare("SELECT endpoint, p256dh, auth FROM proj_push_subscriptions WHERE user_id = :uid");
            $subStmt->execute([':uid' => $userId]);
            $subs = $subStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($subs)) {
                continue;
            }

            $defProjectName = $dayDetails['default_project']['name'] ?? 'Hauptprojekt';
            $defHours = number_format($dayDetails['default_project_hours'], 1, ',', '');
            $title = "Projects: Feierabend-Check";
            $body = "Heute gebucht: {$defHours}h auf {$defProjectName}";

            if (!empty($dayDetails['exceptions'])) {
                $body .= " + " . count($dayDetails['exceptions']) . " Ausnahme(n)";
            }

            $success = $this->sendNotificationToSubscriptions($subs, $title, $body, '/');

            if ($success) {
                $upStmt = $this->pdo->prepare("UPDATE proj_users SET last_reminder_sent_date = :today WHERE id = :uid");
                $upStmt->execute([':today' => $today, ':uid' => $userId]);
                $sentCount++;
            }
        }

        return $sentCount;
    }

    private function sendNotificationToSubscriptions(array $subscriptions, string $title, string $body, string $targetUrl): bool
    {
        $pubKey = $_ENV['VAPID_PUBLIC_KEY'] ?? '';
        $privKey = $_ENV['VAPID_PRIVATE_KEY'] ?? '';

        if (empty($pubKey) || empty($privKey)) {
            return false;
        }

        $auth = [
            'VAPID' => [
                'subject' => $_ENV['VAPID_SUBJECT'] ?? 'mailto:projects@agent-smith.de',
                'publicKey' => $pubKey,
                'privateKey' => $privKey,
            ]
        ];

        try {
            $webPush = new WebPush($auth);
            $webPush->setReuseVAPIDHeaders(true);

            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'icon' => '/projects/android-chrome-192x192.png',
                'badge' => '/projects/android-chrome-192x192.png',
                'url' => $targetUrl,
            ], JSON_UNESCAPED_UNICODE);

            $expiredEndpoints = [];

            foreach ($subscriptions as $sub) {
                $subscription = Subscription::create([
                    'endpoint' => $sub['endpoint'],
                    'keys' => [
                        'p256dh' => $sub['p256dh'],
                        'auth' => $sub['auth'],
                    ]
                ]);
                $webPush->queueNotification($subscription, $payload);
            }

            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess()) {
                    $code = $report->getResponse()?->getStatusCode();
                    if ($code === 404 || $code === 410) {
                        $expiredEndpoints[] = $report->getRequest()->getUri()->__toString();
                    }
                }
            }

            foreach ($expiredEndpoints as $ep) {
                $this->deleteSubscription($ep);
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->warn("ProjectsPushService: Fehler beim Push-Versand: " . $e->getMessage());
            return false;
        }
    }
}

<?php

namespace Kai\Tools\Shared\Push;

use ErrorException;
use Kai\Tools\Shared\Log\Logger;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sendet Web-Push-Benachrichtigungen via VAPID (RFC 8292).
 *
 * Abhängigkeiten: minishlink/web-push (Composer-Paket)
 * VAPID-Keys werden aus $_ENV gelesen (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT).
 */
class WebPushService
{
    private WebPush $webPush;
    private PushSubscriptionRepository $subscriptionRepo;
    private Logger $logger;

    /**
     * @throws ErrorException
     */
    public function __construct(
        ?PushSubscriptionRepository $subscriptionRepo = null,
        ?Logger                     $logger = null
    )
    {
        $this->subscriptionRepo = $subscriptionRepo ?? new PushSubscriptionRepository();
        $this->logger = $logger ?? new Logger();

        $auth = [
            'VAPID' => [
                'subject' => $_ENV['VAPID_SUBJECT'] ?? ('mailto:' . ($_ENV['ALLOWED_USERS'] ?? 'admin@localhost')),
                'publicKey' => $_ENV['VAPID_PUBLIC_KEY'] ?? '',
                'privateKey' => $_ENV['VAPID_PRIVATE_KEY'] ?? '',
            ],
        ];

        $this->webPush = new WebPush($auth);
        // Benachrichtigungen werden gesammelt und erst bei flush() versendet
        $this->webPush->setReuseVAPIDHeaders(true);
    }

    /**
     * Sendet eine Push-Benachrichtigung an alle Geräte eines Benutzers.
     *
     * @param string $userEmail Empfänger-E-Mail (muss push_subscriptions-Eintrag haben)
     * @param string $title Benachrichtigungstitel
     * @param string $body Benachrichtigungstext
     * @param string|null $url Optionaler Link, der beim Klick geöffnet wird
     */
    public function sendToUser(string $userEmail, string $title, string $body, ?string $url = null): void
    {
        if (empty($_ENV['VAPID_PUBLIC_KEY']) || empty($_ENV['VAPID_PRIVATE_KEY'])) {
            // VAPID nicht konfiguriert — kein Push
            return;
        }

        $subscriptions = $this->subscriptionRepo->findByEmail($userEmail);
        if (empty($subscriptions)) {
            return;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/android-chrome-192x192.png',
            'badge' => '/android-chrome-192x192.png',
            'url' => $url ?? '/',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $expiredEndpoints = [];

        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => [
                    'p256dh' => $sub['p256dh'],
                    'auth' => $sub['auth'],
                ],
            ]);

            $this->webPush->queueNotification($subscription, $payload);
        }

        foreach ($this->webPush->flush() as $report) {
            if (!$report->isSuccess()) {
                $statusCode = $report->getResponse()?->getStatusCode();
                $endpoint = $report->getRequest()->getUri()->__toString();

                // 404/410: Subscription ist abgelaufen, aus DB entfernen
                if ($statusCode === 404 || $statusCode === 410) {
                    $expiredEndpoints[] = $endpoint;
                } else {
                    $this->logger->warn('WebPushService: Push fehlgeschlagen.', [
                        'endpoint' => substr($endpoint, 0, 100),
                        'status_code' => $statusCode,
                        'reason' => $report->getReason(),
                    ]);
                }
            }
        }

        foreach ($expiredEndpoints as $endpoint) {
            $this->subscriptionRepo->deleteByEndpoint($endpoint);
        }
    }
}

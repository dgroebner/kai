<?php

namespace Kai\Tools\Shared\Mail;

use Exception;
use Kai\Tools\Shared\Log\Logger;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

class ImapClient
{
    private Client $client;
    private array $allowedSenders;
    private Logger $logger;

    /**
     * @throws Exception
     */
    public function __construct(string $username, string $password)
    {
        $this->logger = new Logger(14);
        $this->allowedSenders = $this->getAllowedSenders();

        // Hier übergeben wir die Konfiguration explizit im Array, 
        // damit der ClientManager nicht nach externen Dateien sucht.
        $cm = new ClientManager([
            'accounts' => [
                'default' => [
                    'host' => $_ENV['IMAP_HOST'],
                    'port' => $_ENV['IMAP_PORT'],
                    'encryption' => $_ENV['IMAP_ENCRYPTION'],
                    'validate_cert' => true,
                    'username' => $username,
                    'password' => $password,
                    'protocol' => 'imap'
                ]
            ]
        ]);

        try {
            $this->client = $cm->account('default');
            $this->client->connect();
            $this->logger->info("ImapClient: Erfolgreich mit Postfach $username verbunden.");
        } catch (Exception $e) {
            $this->logger->error("ImapClient: Verbindungsaufbau fehlgeschlagen!", ['error' => $e->getMessage()]);
            throw new Exception("Konnte keine IMAP-Verbindung herstellen: " . $e->getMessage());
        }
    }

    /**
     * Führt ALLOWED_USERS und IMAP_ALLOWED_SENDERS zu einem bereinigten Array zusammen.
     *
     * @return array<string>
     */
    private function getAllowedSenders(): array
    {
        $allowedUsers = $_ENV['ALLOWED_USERS'] ?? '';
        $allowedSenders = $_ENV['IMAP_ALLOWED_SENDERS'] ?? '';

        $list1 = array_map('trim', explode(',', $allowedUsers));
        $list2 = array_map('trim', explode(',', $allowedSenders));

        return array_unique(array_filter(array_merge($list1, $list2)));
    }

    /**
     * @throws Exception
     */
    public function getVerifiedMails(): array
    {
        $this->logger->info("ImapClient: Prüfe INBOX auf neue Nachrichten...");

        try {
            $folder = $this->client->getFolder('INBOX');

            // DEBUG: Zähle alle Mails, um zu sehen, ob der Ordner leer ist
            $totalCount = $folder->query()->all()->count();
            $this->logger->info("ImapClient DEBUG: Anzahl aller Nachrichten in Inbox: $totalCount");

            $messages = $folder->query()->unseen()->get();
        } catch (Exception $e) {
            $this->logger->error("ImapClient: Fehler beim Abrufen der Mails", ['error' => $e->getMessage()]);
            throw new Exception("Mails konnten nicht abgerufen werden: " . $e->getMessage());
        }

        $verifiedMessages = [];
        foreach ($messages as $message) {
            $fromAddress = $message->getFrom()[0]->mail ?? 'unknown';

            if (in_array($fromAddress, $this->allowedSenders)) {
                $this->logger->info("ImapClient: Absender autorisiert ($fromAddress).");
                $verifiedMessages[] = $message;
            } else {
                $this->logger->info("ImapClient: [GUARD] Unautorisierter Absender ($fromAddress). Verschiebe in Spam-Ordner.");
                $this->moveMail($message, 'Spam');
            }
        }
        return $verifiedMessages;
    }

    /**
     * @throws Exception
     */
    public function moveMail($message, string $targetFolderName): void
    {
        try {
            $message->move($targetFolderName);
            $this->logger->info("ImapClient: Mail in '$targetFolderName' verschoben.");
        } catch (Exception $e) {
            $this->logger->error("ImapClient: Fehler beim Verschieben.", ['error' => $e->getMessage()]);
            throw new Exception("Mail konnte nicht verschoben werden.");
        }
    }

    public function disconnect(): void
    {
        try {
            $this->client->disconnect();
        } catch (Exception $e) {
            $this->logger->error("ImapClient: Fehler beim Trennen.", ['error' => $e->getMessage()]);
        }
    }
}
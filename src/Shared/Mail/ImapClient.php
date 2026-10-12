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

    private string $username;

    /**
     * @throws Exception
     */
    public function __construct(string $username, string $password)
    {
        $this->username = $username;
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

    public function markAsUnseen($message): void
    {
        try {
            $message->unsetFlag('Seen');
            $this->logger->info("ImapClient: Mail als ungelesen (unseen) markiert.");
        } catch (\Throwable $e) {
            $this->logger->warn("ImapClient: Konnte Seen-Flag nicht entfernen: " . $e->getMessage());
        }
    }

    public function ensureConnected(): void
    {
        try {
            if (!$this->client->isConnected()) {
                $this->client->connect();
                $this->logger->info("ImapClient: Reconnect erfolgreich.");
            }
        } catch (\Throwable $e) {
            $this->logger->warn("ImapClient: Reconnect fehlgeschlagen: " . $e->getMessage());
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

    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Legt eine gesendete E-Mail im IMAP-Ordner "Gesendete Objekte" (Sent) ab.
     */
    public function appendSentMessage(string $rawMessage): bool
    {
        try {
            $this->ensureConnected();
            $candidateNames = ['INBOX.Sent', 'Sent', 'INBOX.Gesendete Objekte', 'Gesendete Objekte', 'Sent Items'];
            $sentFolder = null;
            foreach ($candidateNames as $name) {
                try {
                    $sentFolder = $this->client->getFolderByPath($name);
                    if ($sentFolder) {
                        break;
                    }
                } catch (\Throwable) {}
            }

            if (!$sentFolder) {
                try {
                    $folders = $this->client->getFolders();
                    foreach ($folders as $folder) {
                        $fn = strtolower($folder->name);
                        if ($fn === 'sent' || $fn === 'gesendete objekte' || str_contains($fn, 'sent')) {
                            $sentFolder = $folder;
                            break;
                        }
                    }
                } catch (\Throwable) {}
            }

            if ($sentFolder) {
                $sentFolder->appendMessage($rawMessage, ['\Seen']);
                $this->logger->info("ImapClient: Gesendete Mail erfolgreich in '{$sentFolder->path}' hinterlegt.");
                return true;
            }

            $this->logger->warn("ImapClient: Kein Gesendet-Ordner (Sent) gefunden.");
            return false;
        } catch (\Throwable $e) {
            $this->logger->warn("ImapClient: Konnte Mail nicht in Sent-Ordner ablegen: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ruft Nachrichten aus einem beliebigen IMAP-Ordner (z.B. Archive) ab.
     *
     * @return array
     */
    public function getMailsFromFolder(string $folderName = 'Archive', int $limit = 20): array
    {
        try {
            $this->ensureConnected();
            $folder = null;
            try {
                $folder = $this->client->getFolder($folderName);
            } catch (\Throwable) {}

            if (!$folder) {
                try {
                    $folder = $this->client->getFolderByPath('INBOX.' . $folderName);
                } catch (\Throwable) {}
            }

            if (!$folder) {
                try {
                    $folders = $this->client->getFolders();
                    foreach ($folders as $f) {
                        $fn = strtolower($f->name);
                        if ($fn === strtolower($folderName) || str_contains($fn, strtolower($folderName))) {
                            $folder = $f;
                            break;
                        }
                    }
                } catch (\Throwable) {}
            }

            if (!$folder) {
                return [];
            }

            $messages = $folder->query()->all()->limit($limit)->get();
            return $messages ? $messages->all() : [];
        } catch (\Throwable $e) {
            $this->logger->warn("ImapClient: Konnte Mails aus '$folderName' nicht abrufen: " . $e->getMessage());
            return [];
        }
    }
}
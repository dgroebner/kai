<?php
namespace Kai\Tools\Shared\Mail;

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;
use Kai\Tools\Shared\Log\Logger;
use Exception;

class ImapClient {
    private Client $client;
    private array $allowedSenders;
    private Logger $logger;

    public function __construct(string $username, string $password) {
        $this->logger = new Logger(14);
        
        // Sichert ab, dass das Skript nicht stirbt, falls ALLOWED_USERS in der .env fehlt
        $this->allowedSenders = explode(',', $_ENV['ALLOWED_USERS'] ?? '');

        try {
            $cm = new ClientManager();
            $this->client = $cm->make([
                'host'          => $_ENV['IMAP_HOST'],
                'port'          => $_ENV['IMAP_PORT'],
                'encryption'    => $_ENV['IMAP_ENCRYPTION'],
                'validate_cert' => true,
                'username'      => $username,
                'password'      => $password,
                'protocol'      => 'imap'
            ]);

            $this->client->connect();
            $this->logger->info("ImapClient: Erfolgreich mit Postfach {$username} verbunden.");
            
        } catch (Exception $e) {
            $this->logger->error("ImapClient: Verbindungsaufbau fehlgeschlagen!", ['error' => $e->getMessage()]);
            throw new Exception("Konnte keine IMAP-Verbindung herstellen: " . $e->getMessage());
        }
    }

    /**
     * Holt ungelesene Mails und filtert Spam/unberechtigte Absender direkt aus
     */
    public function getVerifiedMails(): array {
        $this->logger->info("ImapClient: Prüfe INBOX auf neue Nachrichten...");
        
        try {
            $folder = $this->client->getFolder('INBOX');
            $messages = $folder->query()->unseen()->get();
        } catch (Exception $e) {
            $this->logger->error("ImapClient: Fehler beim Abrufen der Mails", ['error' => $e->getMessage()]);
            throw new Exception("Mails konnten nicht abgerufen werden: " . $e->getMessage());
        }

        $verifiedMessages = [];
        $count = count($messages);
        
        if ($count > 0) {
            $this->logger->info("ImapClient: {$count} ungelesene Nachricht(en) gefunden.");
        }

        foreach ($messages as $message) {
            $fromAddress = $message->getFrom()[0]->mail ?? 'unknown';

            if (in_array($fromAddress, $this->allowedSenders)) {
                $this->logger->info("ImapClient: Absender autorisiert ({$fromAddress}).");
                $verifiedMessages[] = $message;
            }
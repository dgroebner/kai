<?php
namespace Kai\Tools\Shared\Mail;

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;

class ImapClient {
    private Client $client;
    private array $allowedSenders;

    public function __construct(string $username, string $password) {
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

        // Whitelist direkt aus der zentralen .env Konfiguration laden
        $this->allowedSenders = explode(',', $_ENV['ALLOWED_USERS']);
    }

    /**
     * Holt ungelesene Mails und filtert Spam/unberechtigte Absender direkt aus
     */
    public function getVerifiedMails(): array {
        $folder = $this->client->getFolder('INBOX');
        $messages = $folder->query()->unseen()->get();
        $verifiedMessages = [];

        foreach ($messages as $message) {
            $fromAddress = $message->getFrom()[0]->mail;

            if (in_array($fromAddress, $this->allowedSenders)) {
                // Berechtigter Absender -> Zur Verarbeitung freigeben
                $verifiedMessages[] = $message;
            } else {
                // Guard schlägt an -> Mail direkt in den Strato-Spam-Ordner verschieben
                echo "[GUARD] Unautorisierter Absender ({$fromAddress}). Verschiebe in Spam-Ordner.<br>";
                $this->moveMail($message, 'Spam');
            }
        }

        return $verifiedMessages;
    }

    /**
     * Verschiebt eine Mail in einen anderen Ordner
     */
    public function moveMail($message, string $targetFolderName) {
        $message->move($targetFolderName);
    }
    
    public function disconnect() {
        $this->client->disconnect();
    }
}
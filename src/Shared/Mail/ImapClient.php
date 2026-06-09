<?php
namespace Kai\Tools\Shared\Mail;

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;

class ImapClient {
    private Client $client;

    public function __construct(string $username, string $password) {
        $cm = new ClientManager();
        
        // Wir holen die allgemeinen Server-Daten direkt aus der .env, 
        // da sie für alle Strato-Postfächer gleich sind.
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
    }

    /**
     * Holt alle ungelesenen E-Mails aus dem Posteingang (INBOX)
     */
    public function getUnreadMails() {
        $folder = $this->client->getFolder('INBOX');
        // Hole alle ungelesenen Mails
        return $folder->query()->unseen()->get();
    }

    /**
     * Verschiebt eine Mail in einen anderen Ordner (z.B. "Erledigt")
     */
    public function moveMail($message, string $targetFolderName) {
        $message->move($targetFolderName);
    }
    
    public function disconnect() {
        $this->client->disconnect();
    }
}
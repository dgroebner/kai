<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Mail\ImapClient;
use Kai\Tools\Shared\Log\Logger;
use Exception;

class ScannerTask {
    private Logger $logger;

    public function __construct() {
        $this->logger = new Logger(14);
    }
    
    public function run() {
        $this->logger->info("ScannerTask: Starte Verarbeitungslauf...");

        try {
            $user = $_ENV['IMAP_USER_KASSENBON'];
            $pass = $_ENV['IMAP_PASS_KASSENBON'];

            $mailClient = new ImapClient($user, $pass);
            $analyzer = new ReceiptAnalyzer();

            $messages = $mailClient->getVerifiedMails();

            if (empty($messages)) {
                $this->logger->info("ScannerTask: Keine verifizierten Kassenbons zur Verarbeitung gefunden.");
                $mailClient->disconnect();
                return;
            }

            foreach ($messages as $message) {
                $subject = $message->getSubject()[0] ?? 'Kein Betreff';
                $this->logger->info("ScannerTask: Verarbeite Nachricht '{$subject}'...");

                try {
                    $this->processSingleMessage($message, $analyzer, $mailClient);
                } catch (Exception $e) {
                    // Schlägt ein Bon fehl, loggen wir das, aber die Schleife läuft für die restlichen Mails weiter!
                    $this->logger->error("ScannerTask: Fehler bei der Verarbeitung der Nachricht '{$subject}'.", ['error' => $e->getMessage()]);
                }
            }

            $mailClient->disconnect();
            $this->logger->info("ScannerTask: Verarbeitungslauf erfolgreich beendet.");

        } catch (Exception $e) {
            // Fängt fatale Fehler beim initialen Verbindungsaufbau ab
            $this->logger->error("ScannerTask: Kritischer Systemfehler im Hauptlauf!", ['error' => $e->getMessage()]);
            throw $e; 
        }
    }

    /**
     * Kapselt die Logik für eine einzelne E-Mail
     */
    private function processSingleMessage($message, ReceiptAnalyzer $analyzer, ImapClient $mailClient) {
        $attachments = $message->getAttachments();
        $processed = false;

        foreach ($attachments as $attachment) {
            $mimeType = strtolower($attachment->getMimeType()); 
            
            if (strpos($mimeType, 'pdf') !== false || strpos($mimeType, 'image') !== false) {
                $this->logger->info("ScannerTask: Valider Anhang gefunden", ['name' => $attachment->getName()]);
                
                $base64Data = base64_encode($attachment->getContent());
                $receiptData = $analyzer->analyze($mimeType, $base64Data);
                
                if ($receiptData) {
                    // TODO: Hier rufen wir im nächsten Schritt unsere zukünftige Datenbank-Klasse auf
                    $this->logger->info("ScannerTask: Dummy-Datenbank-Insert erfolgreich.");
                    $processed = true;
                }
                
                break; // Wir verarbeiten pro Mail nur den ersten gefundenen Kassenbon
            }
        }

        if ($processed) {
            $mailClient->moveMail($message, 'Archive');
        } else {
            $this->logger->info("ScannerTask: Kein verwertbarer Kassenbon in der Mail gefunden.");
            // Optional: Unverwertbare Mails in einen "Review" Ordner schieben
        }
    }
}
<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Mail\ImapClient;

class ScannerTask {
    
    public function run() {
        $user = $_ENV['IMAP_USER_KASSENBON'];
        $pass = $_ENV['IMAP_PASS_KASSENBON'];

        $mailClient = new ImapClient($user, $pass);
        $analyzer = new ReceiptAnalyzer();

        // Nutzt jetzt die gefilterte Methode aus dem Shared Service
        $messages = $mailClient->getVerifiedMails();

        if (empty($messages)) {
            echo "Keine neuen, verifizierten Kassenbons gefunden.<br>";
            $mailClient->disconnect();
            return;
        }

        foreach ($messages as $message) {
            echo "Verarbeite Kassenbon von: " . $message->getFrom()[0]->mail . "<br>";

            $attachments = $message->getAttachments();
            $processed = false;

            foreach ($attachments as $attachment) {
                $mimeType = strtolower($attachment->getMimeType()); 
                
                if (strpos($mimeType, 'pdf') !== false || strpos($mimeType, 'image') !== false) {
                    $base64Data = base64_encode($attachment->getContent());
                    
                    echo "- Sende an Gemini API...<br>";
                    $receiptData = $analyzer->analyze($mimeType, $base64Data);
                    
                    if ($receiptData) {
                        echo "- Auswertung erfolgreich!<br>";
                        echo "<pre>" . print_r($receiptData, true) . "</pre>";
                        
                        // TODO: In kb_* Tabellen wegschreiben
                        $processed = true;
                    }
                    break; 
                }
            }

            if ($processed) {
                $mailClient->moveMail($message, 'Archive');
                echo "- Mail erfolgreich archiviert.<hr>";
            }
        }

        $mailClient->disconnect();
    }
}
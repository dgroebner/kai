<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Mail\ImapClient;

class ScannerTask {
    
    public function run() {
        $user = $_ENV['IMAP_USER_KASSENBON'];
        $pass = $_ENV['IMAP_PASS_KASSENBON'];

        $mailClient = new ImapClient($user, $pass);
        $analyzer = new ReceiptAnalyzer();

        $messages = $mailClient->getUnreadMails();

        if (empty($messages)) {
            echo "Keine neuen Kassenbons gefunden.<br>";
            return;
        }

        foreach ($messages as $message) {
            echo "Verarbeite Mail: " . $message->getSubject() . "<br>";

            $attachments = $message->getAttachments();
            $processed = false;

            foreach ($attachments as $attachment) {
                $mimeType = strtolower($attachment->getMimeType()); 
                
                // Wir filtern auf PDFs und gängige Bildformate (z.B. Edeka-Screenshots)
                if (strpos($mimeType, 'pdf') !== false || strpos($mimeType, 'image') !== false) {
                    echo "- Valider Anhang gefunden: " . $attachment->getName() . "<br>";
                    
                    // Extrahiere Binärdaten und kodiere sie für die KI
                    $base64Data = base64_encode($attachment->getContent());
                    
                    echo "- Sende Daten an Gemini...<br>";
                    $receiptData = $analyzer->analyze($mimeType, $base64Data);
                    
                    if ($receiptData) {
                        echo "- Auswertung erfolgreich!<br>";
                        echo "<pre>" . print_r($receiptData, true) . "</pre>";
                        
                        // HIER folgt später das Einfügen in die MySQL-Datenbank
                        
                        $processed = true;
                    } else {
                        echo "- [FEHLER] KI konnte die Daten nicht lesen oder formatieren.<br>";
                    }
                    
                    // Wir verarbeiten pro Mail erstmal nur den ersten relevanten Anhang
                    break; 
                }
            }

            // Aufräumen: Wenn alles geklappt hat, ab ins Archiv
            if ($processed) {
                $mailClient->moveMail($message, 'Archive');
                echo "- Mail wurde ins Archiv verschoben.<hr>";
            }
        }

        $mailClient->disconnect();
    }
}
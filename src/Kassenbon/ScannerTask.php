<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Mail\ImapClient;
use Kai\Tools\Kassenbon\ReceiptAnalyzer;
use Kai\Tools\Kassenbon\ReceiptRepository;

class ScannerTask {
    private Logger $logger;

    public function __construct() {
        $this->logger = new Logger(14);
    }

    public function run() {
        $this->logger->info("ScannerTask: Starte Verarbeitungslauf...");

        try {
            $mailClient = new ImapClient($_ENV['IMAP_USER_KASSENBON'], $_ENV['IMAP_PASS_KASSENBON']);
            $analyzer = new ReceiptAnalyzer();
            $repository = new ReceiptRepository(); 

            $messages = $mailClient->getVerifiedMails();

            if (empty($messages)) {
                $this->logger->info("ScannerTask: Keine verifizierten Kassenbons zur Verarbeitung gefunden.");
                $mailClient->disconnect();
                return;
            }

            // Hole bekannte Kategorien vor der Verarbeitung
            $knownCategories = $repository->getKnownCategories();

            foreach ($messages as $message) {
                // HIER WAR DER FEHLER: Wir müssen die Anhänge der Mail durchlaufen
                $attachments = $message->getAttachments(); 

				if (empty($attachments)) {
                    $this->logger->info("ScannerTask: Mail besitzt keine Anhänge. Verschiebe ins Archiv.");
                    
                    // WICHTIG: Auch unbrauchbare Mails müssen aus der INBOX verschwinden!
                    $mailClient->moveMail($message, 'Archive'); 
                    
                    continue;
                }

                $mailArchived = false;

                foreach ($attachments as $attachment) {
                    $mimeType = strtolower($attachment->getMimeType()); 
                    
                    // Wir filtern auf PDFs und gängige Bildformate (z.B. Edeka-Screenshots)
                    if (strpos($mimeType, 'pdf') !== false || strpos($mimeType, 'image') !== false) {
                        $this->logger->info("ScannerTask: Valider Anhang gefunden: {$attachment->getName()}");
                        
                        // Extrahiere Binärdaten und kodiere sie für die KI
                        $base64Data = base64_encode($attachment->getContent());

                        if ($base64Data) {
                            
                            // Hash aus den rohen Base64-Daten generieren
                            $fileHash = hash('sha256', $base64Data);

                            // Datenbank fragen, ob dieser Dateihash existiert
                            if ($repository->receiptExists($fileHash)) {
                                $this->logger->info("ScannerTask: Bon übersprungen. Hash {$fileHash} existiert bereits.");
                                // Nächsten Anhang prüfen – Mail erst am Ende archivieren
                                $mailArchived = false;
                                continue;
                            }

                            $this->logger->info("ScannerTask: Neuer Bon erkannt. Sende Daten an KI zur Analyse...");
                            
                            // Bekannte Kategorien an den Analyzer übergeben
                            $receiptData = $analyzer->analyze($mimeType, $base64Data, $knownCategories);

                            if ($receiptData && isset($receiptData['items'])) {

                                // Alle Artikelnamen sammeln und Kategorien per Batch-Query laden
                                $productNames = array_column($receiptData['items'], 'name');
                                $historicalCategories = $repository->getKnownCategoriesForProducts($productNames);

                                foreach ($receiptData['items'] as &$item) {
                                    if (isset($historicalCategories[$item['name']])) {
                                        $this->logger->info("Gedächtnis-Korrektur: '{$item['name']}' ist '{$historicalCategories[$item['name']]}' (KI dachte: '{$item['category']}').");
                                        $item['category'] = $historicalCategories[$item['name']];
                                    }
                                }
                                unset($item);
                                
                                // Dem Repository beim Speichern zusätzlich den Datei-Hash mitgeben
                                $repository->saveReceipt($receiptData, $fileHash);
                                $mailArchived = true;
                                $this->logger->info("ScannerTask: Bon gespeichert.");
                            } else {
                                $this->logger->error("ScannerTask: KI lieferte leeres oder ungültiges Ergebnis.");
                            }
                        }
                    }
                }

                // Mail nach Verarbeitung aller Anhänge archivieren
                if ($mailArchived !== false) {
                    $mailClient->moveMail($message, 'Archive');
                    $this->logger->info("ScannerTask: Mail erfolgreich ins Archiv verschoben.");
                }
            }

            $mailClient->disconnect();
            $this->logger->info("ScannerTask: Verarbeitungslauf beendet.");

        } catch (\Throwable $e) {
            $this->logger->error("ScannerTask: Kritischer Fehler im Task!", ['error' => $e->getMessage()]);
        }
    }
}
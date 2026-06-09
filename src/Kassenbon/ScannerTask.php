<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Mail\ImapClient;

class ScannerTask {
    private Logger $logger;

    public function __construct() {
        $this->logger = new Logger(14);
    }

    public function run() {
        $this->logger->info("ScannerTask: Starte Verarbeitungslauf...");

        try {
            $mailClient = new \Kai\Tools\Shared\Mail\ImapClient($_ENV['IMAP_USER_KASSENBON'], $_ENV['IMAP_PASS_KASSENBON']);
            $analyzer = new \Kai\Tools\Kassenbon\ReceiptAnalyzer();
            $repository = new \Kai\Tools\Kassenbon\ReceiptRepository(); 

            $messages = $mailClient->getVerifiedMails();

            if (empty($messages)) {
                $this->logger->info("ScannerTask: Keine verifizierten Kassenbons zur Verarbeitung gefunden.");
                $mailClient->disconnect();
                return;
            }

            // Hole bekannte Kategorien vor der Verarbeitung
            $knownCategories = $repository->getKnownCategories();

            foreach ($messages as $message) {
                $mimeType = strtolower($attachment->getMimeType()); 
                
                // Wir filtern auf PDFs und gängige Bildformate (z.B. Edeka-Screenshots)
                if (strpos($mimeType, 'pdf') !== false || strpos($mimeType, 'image') !== false) {
					$this->logger->info("ScannerTask: Valider Anhang gefunden: {$attachment->getName()}");
                    
                    // Extrahiere Binärdaten und kodiere sie für die KI
                    $base64Data = base64_encode($attachment->getContent());

					if ($base64Data) {
						
						// =========================================================
						// NATIVE TRENNUNG / DUPLIKATS-SCHUTZ START
						// =========================================================
						
						// 1. Hash aus den rohen Base64-Daten generieren
						$fileHash = hash('sha256', $base64Data);

						// 2. Datenbank fragen, ob dieser Dateihash existiert
						if ($repository->receiptExists($fileHash)) {
							$this->logger->info("ScannerTask: Bon übersprungen. Hash {$fileHash} existiert bereits.");
							
							// Wichtig: Mail trotzdem archivieren, damit sie beim nächsten Cronjob nicht wieder blockiert
							$mailClient->moveMail($message, 'Archive'); 
							continue; // Schleife abbrechen, direkt zur nächsten Mail springen
						}
						
						// =========================================================
						// DUPLIKATS-SCHUTZ ENDE
						// =========================================================

						$this->logger->info("ScannerTask: Neuer Bon erkannt. Sende PDF an KI zur Analyse...");
						
						// Bekannte Kategorien an den Analyzer übergeben
						$receiptData = $analyzer->analyze($mimeType, $base64Data, $knownCategories);

						if ($receiptData && isset($receiptData['items'])) {
							
							// Dem Repository beim Speichern zusätzlich den Datei-Hash mitgeben
							$repository->saveReceipt($receiptData, $fileHash);

							$mailClient->moveMail($message, 'Archive');
							$this->logger->info("ScannerTask: Mail erfolgreich ins Archiv verschoben.");
						} else {
							$this->logger->error("ScannerTask: KI lieferte leeres oder ungültiges Ergebnis.");
						}
					}
                }
            }

            $mailClient->disconnect();
            $this->logger->info("ScannerTask: Verarbeitungslauf beendet.");

        } catch (\Throwable $e) {
            $this->logger->error("ScannerTask: Kritischer Fehler im Task!", ['error' => $e->getMessage()]);
        }
    }
}
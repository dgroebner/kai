<?php
namespace Kai\Tools\Kassenbon;

use Kai\Tools\Shared\Log\Logger;
// ... deine anderen use-Statements ...

class ScannerTask {
    private Logger $logger;

    public function __construct() {
        // Diese Zeile hat gefehlt: Wir müssen den Logger erst erschaffen, 
        // bevor wir ihn in run() benutzen können!
        $this->logger = new Logger(14);
    }

    public function run() {
        $this->logger->info("ScannerTask: Starte Verarbeitungslauf...");

        try {
            $mailClient = new \Kai\Tools\Shared\Mail\ImapClient($_ENV['IMAP_USER_KASSENBON'], $_ENV['IMAP_PASS_KASSENBON']);
            $analyzer = new \Kai\Tools\Kassenbon\ReceiptAnalyzer();
            $repository = new \Kai\Tools\Kassenbon\ReceiptRepository(); // Neues Repo instanziieren

            $messages = $mailClient->getVerifiedMails();

            if (empty($messages)) {
                $this->logger->info("ScannerTask: Keine verifizierten Kassenbons zur Verarbeitung gefunden.");
                $mailClient->disconnect();
                return;
            }

            // Hole bekannte Kategorien vor der Verarbeitung
            $knownCategories = $repository->getKnownCategories();

            foreach ($messages as $message) {
                // ... (Hier bleibt dein bisheriger Code zur PDF-Extraktion in $base64Data)
                // Angenommen, du hast hier die Variablen $mimeType und $base64Data

                if ($base64Data) {
                    $this->logger->info("ScannerTask: Sende PDF an KI zur Analyse...");
                    
                    // WICHTIG: Kategorien als Kontext übergeben!
                    $receiptData = $analyzer->analyze($mimeType, $base64Data, $knownCategories);

                    if ($receiptData && isset($receiptData['items'])) {
                        // In Datenbank speichern
                        $repository->saveReceipt($receiptData);

                        // Wenn alles geklappt hat, die E-Mail ins Archiv verschieben (damit sie nicht beim nächsten Lauf wieder gelesen wird)
                        $mailClient->moveMail($message, 'Archive'); // Oder 'Erledigt' – je nachdem wie der Ordner bei Strato heißt
                        $this->logger->info("ScannerTask: Mail erfolgreich ins Archiv verschoben.");
                    } else {
                        $this->logger->error("ScannerTask: KI lieferte leeres oder ungültiges Ergebnis.");
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
<?php

namespace Kai\Tools\Shared\Mail;

use Kai\Tools\Bank\CreditCardService;
use Kai\Tools\Kassenbon\ReceiptAnalyzer;
use Kai\Tools\Kassenbon\ReceiptRepository;
use Kai\Tools\Shared\Log\Logger;
use Exception;

class MailDispatcher
{
    private ImapClient $imapClient;
    private CreditCardService $creditCardService;
    private ReceiptAnalyzer $receiptAnalyzer;
    private ReceiptRepository $receiptRepository;
    private Logger $logger;

    public function __construct(
        ImapClient $imapClient,
        CreditCardService $creditCardService,
        ReceiptAnalyzer $receiptAnalyzer,
        ReceiptRepository $receiptRepository
    ) {
        $this->imapClient = $imapClient;
        $this->creditCardService = $creditCardService;
        $this->receiptAnalyzer = $receiptAnalyzer;
        $this->receiptRepository = $receiptRepository;
        $this->logger = new Logger(14);
    }

    public function dispatch(): void
    {
        $this->logger->info("MailDispatcher: Starte E-Mail-Prüfung...");

        $messages = $this->imapClient->getVerifiedMails();

        if (empty($messages)) {
            $this->logger->info("MailDispatcher: Keine neuen verifizierten Mails vorhanden.");
            $this->imapClient->disconnect();
            return;
        }

        $knownCategories = $this->receiptRepository->getKnownCategories();

        foreach ($messages as $message) {
            $attachments = $message->getAttachments();

            if (empty($attachments)) {
                $this->logger->info("MailDispatcher: Mail ohne Anhänge. Verschiebe ins Archiv.");
                $this->imapClient->moveMail($message, 'Archive');
                continue;
            }

            foreach ($attachments as $attachment) {
                $fileName = $attachment->getName();
                $mimeType = strtolower($attachment->getMimeType());
                $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $content = $attachment->getContent();

                if (empty($content)) {
                    continue;
                }

                // 1. KREDITKARTEN-PDF (Bank-Modul)
                if ($extension === 'pdf' && $this->isCreditCardStatement($content, $fileName)) {
                    $this->logger->info("MailDispatcher: Kreditkartenabrechnung erkannt ({$fileName}).");
                    
                    // Temp-Datei für den Parser anlegen
                    $tmpFilePath = sys_get_temp_dir() . '/' . uniqid('visa_') . '.pdf';
                    file_put_contents($tmpFilePath, $content);

                    try {
                        $this->creditCardService->importStatementPdf($tmpFilePath, $fileName);
                        $this->logger->info("MailDispatcher: Kreditkartenabrechnung erfolgreich importiert.");
                    } catch (Exception $e) {
                        $this->logger->error("MailDispatcher: Fehler bei Kreditkarten-Import: " . $e->getMessage());
                    } finally {
                        if (file_exists($tmpFilePath)) {
                            @unlink($tmpFilePath);
                        }
                    }
                    continue;
                }

                // 2. CSV-DATEIEN (Späteres Bank-Modul)
                if ($extension === 'csv' || str_contains($mimeType, 'csv')) {
                    $this->logger->info("MailDispatcher: CSV-Bankdatei erkannt ({$fileName}).");
                    continue;
                }

                // 3. E-BONS & BELEGE (Kassenbon-Modul)
                if (str_contains($mimeType, 'pdf') || str_contains($mimeType, 'image')) {
                    $this->logger->info("MailDispatcher: E-Bon/Beleg erkannt ({$fileName}).");
                    $base64Data = base64_encode($content);

                    if ($base64Data) {
                        $fileHash = hash('sha256', $base64Data);

                        if ($this->receiptRepository->receiptExists($fileHash)) {
                            $this->logger->info("MailDispatcher: Bon-Hash {$fileHash} existiert bereits.");
                            continue;
                        }

                        $receiptData = $this->receiptAnalyzer->analyze($mimeType, $base64Data, $knownCategories);

                        if ($receiptData && isset($receiptData['items'])) {
                            $productNames = array_column($receiptData['items'], 'name');
                            $historicalCategories = $this->receiptRepository->getKnownCategoriesForProducts($productNames);

                            foreach ($receiptData['items'] as &$item) {
                                if (isset($historicalCategories[$item['name']])) {
                                    $item['category'] = $historicalCategories[$item['name']];
                                }
                            }
                            unset($item);

                            $this->receiptRepository->saveReceipt($receiptData, $fileHash);
                            $this->logger->info("MailDispatcher: E-Bon erfolgreich verarbeitet und gespeichert.");
                        }
                    }
                }
            }

            // Mail ins Archiv verschieben
            $this->imapClient->moveMail($message, 'Archive');
            $this->logger->info("MailDispatcher: Mail verarbeitet/bereinigt und ins Archiv verschoben.");
        }

        $this->imapClient->disconnect();
        $this->logger->info("MailDispatcher: Verarbeitung erfolgreich beendet.");
    }

    private function isCreditCardStatement(string $content, string $filename): bool
    {
        $lowerFilename = strtolower($filename);
        if (str_contains($lowerFilename, 'kreditkarte') || str_contains($lowerFilename, 'kartenabrechnung')) {
            return true;
        }

        // Erste 2KB der Binärdaten prüfen
        $headerChunk = substr($content, 0, 2048);
        if (str_contains($headerChunk, 'ADAC') || str_contains($headerChunk, 'Solaris') || str_contains($headerChunk, 'Kartenabrechnung')) {
            return true;
        }

        return false;
    }
}
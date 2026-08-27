<?php

namespace Kai\Tools\Shared\Mail;

use Exception;
use Kai\Tools\Bank\CreditCardService;
use Kai\Tools\Kassenbon\ReceiptAnalyzer;
use Kai\Tools\Kassenbon\ReceiptMatcher;
use Kai\Tools\Kassenbon\ReceiptRepository;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use Smalot\PdfParser\Parser;
use Throwable;

class MailDispatcher
{
    private ImapClient $imapClient;
    private CreditCardService $creditCardService;
    private ReceiptAnalyzer $receiptAnalyzer;
    private ReceiptRepository $receiptRepository;
    private Logger $logger;

    public function __construct(
        ImapClient        $imapClient,
        CreditCardService $creditCardService,
        ReceiptAnalyzer   $receiptAnalyzer,
        ReceiptRepository $receiptRepository
    )
    {
        $this->imapClient = $imapClient;
        $this->creditCardService = $creditCardService;
        $this->receiptAnalyzer = $receiptAnalyzer;
        $this->receiptRepository = $receiptRepository;
        $this->logger = new Logger(14);
    }

    /**
     * @throws Exception
     */
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
                    $this->logger->info("MailDispatcher: Kreditkartenabrechnung erkannt ($fileName).");

                    $tmpFilePath = sys_get_temp_dir() . '/' . uniqid('visa_') . '.pdf';
                    file_put_contents($tmpFilePath, $content);

                    try {
                        $statementId = $this->creditCardService->importStatementPdf($tmpFilePath, $fileName);
                        $this->creditCardService->applyHistoricalCategories($statementId);
                        $this->logger->info("MailDispatcher: Kreditkartenabrechnung erfolgreich importiert und Historie angewendet.");
                    } catch (Exception $e) {
                        $this->logger->error("MailDispatcher: Fehler bei Kreditkarten-Import: " . $e->getMessage());
                    } finally {
                        if (file_exists($tmpFilePath)) {
                            @unlink($tmpFilePath);
                        }
                    }
                    continue;
                }

                // 3. E-BONS & BELEGE (Kassenbon-Modul)
                if (str_contains($mimeType, 'pdf') || str_contains($mimeType, 'image')) {
                    $this->logger->info("MailDispatcher: E-Bon/Beleg erkannt ($fileName).");
                    $base64Data = base64_encode($content);

                    if ($base64Data) {
                        $fileHash = hash('sha256', $base64Data);

                        if ($this->receiptRepository->receiptExists($fileHash)) {
                            $this->logger->info("MailDispatcher: Bon-Hash $fileHash existiert bereits.");
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

                            $receiptId = $this->receiptRepository->saveReceipt($receiptData, $fileHash);

                            $activityLogger = new ActivityLogger(Database::getInstance());
                            $activityLogger->logReceipt($receiptId, $receiptData['store']);

                            $matcher = new ReceiptMatcher();
                            $matcher->syncUnlinkedReceipts();

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
        $keywords = array_filter(array_map(
            static fn(string $keyword): string => strtolower(trim($keyword)),
            explode(',', (string)($_ENV['MAIL_BANK_KEYWORDS'] ?? ''))
        ));

        // Wenn keine Keywords definiert sind, greft standardmäßig kein Match
        if (empty($keywords)) {
            return false;
        }

        // PDF temporär speichern, um sie mit dem Parser zu lesen
        $tmpPdf = sys_get_temp_dir() . '/' . uniqid('check_') . '.pdf';
        file_put_contents($tmpPdf, $content);

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($tmpPdf);

            // Wir prüfen primär die erste Seite (Kopfbereich)
            $pages = $pdf->getPages();
            $text = !empty($pages) ? $pages[0]->getText() : $pdf->getText();
            $lowerText = strtolower($text);

            // Auch den Dateinamen einbeziehen, falls dort Keywords stehen
            $lowerFilename = strtolower($filename);
            $combinedSearchSpace = $lowerText . ' ' . $lowerFilename;

            // Prüfen, ob ALLE Keywords im Text oder Dateinamen enthalten sind (AND-Verknüpfung)
            // Sobald ein Keyword fehlt, ist es kein Treffer
            return array_all($keywords, fn($kw) => str_contains($combinedSearchSpace, $kw));

            // Alle Keywords wurden gefunden

        } catch (Throwable $e) {
            $this->logger->error("Fehler beim Parsen der PDF-Vorschau: " . $e->getMessage());
            return false;
        } finally {
            if (file_exists($tmpPdf)) {
                @unlink($tmpPdf);
            }
        }
    }
}
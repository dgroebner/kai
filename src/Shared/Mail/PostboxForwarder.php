<?php

namespace Kai\Tools\Shared\Mail;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

class PostboxForwarder
{
    private PDO $db;
    private Logger $logger;
    private ?ImapClient $imapClient;

    public function __construct(?PDO $db = null, ?Logger $logger = null, ?ImapClient $imapClient = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->logger = $logger ?? new Logger(14);
        $this->imapClient = $imapClient;
    }

    /**
     * Prüft, ob ein Beleg an die d.velop Postbox weitergeleitet werden soll,
     * und führt den Versand inklusive Duplikatschutz durch.
     */
    public function forwardIfEligible(
        string $pdfContent,
        string $originalFilename,
        string $fileHash,
        array $receiptData,
        bool $force = false
    ): bool {
        $targetEmail = trim((string)($_ENV['DVELOP_POSTBOX_EMAIL'] ?? ''));
        if ($targetEmail === '') {
            $this->logger->info("PostboxForwarder: DVELOP_POSTBOX_EMAIL nicht konfiguriert. Versand übersprungen.");
            return false;
        }

        // Tag und Zieldateiname ermitteln
        $tag = $this->determinePostboxTag($receiptData, $originalFilename);
        if ($tag === null) {
            $storeName = (string)($receiptData['store'] ?? 'unbekannt');
            $this->logger->debug("PostboxForwarder: Kein passender Postbox-Tag für Beleg '{$originalFilename}' (Händler: '{$storeName}') gefunden.");
            return false;
        }

        // Duplikatschutz prüfen
        if (!$force && $this->alreadyDispatched($fileHash, $tag)) {
            $this->logger->info("PostboxForwarder: Beleg ($fileHash) wurde bereits mit Tag '$tag' an d.velop versendet.");
            return true;
        }

        $docDate = $receiptData['date'] ?? ($receiptData['purchase_date'] ?? date('Y-m-d'));
        $store = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($receiptData['store'] ?? 'Telekom'));
        $typeLabel = ($tag === '#festnetzrechnung') ? 'Festnetz' : 'Mobilfunk';
        $dispatchedFilename = "{$docDate}_{$store}_{$typeLabel}.pdf";

        $billingPeriod = !empty($receiptData['billing_period']) ? ' ' . trim((string)$receiptData['billing_period']) : '';
        // Das Tag MUSS am Anfang stehen und darf keine Leerzeichen enthalten:
        $subject = "{$tag} {$receiptData['store']} {$typeLabel}-Rechnung{$billingPeriod}";

        return $this->sendMail($targetEmail, $subject, $tag, $pdfContent, $originalFilename, $dispatchedFilename, $fileHash);
    }

    /**
     * Ermittelt den passenden Tag für d.velop.
     * Unterstützt #mobilfunkrechnung und #festnetzrechnung.
     */
    public function determinePostboxTag(array $receiptData, string $filename): ?string
    {
        $docType = $receiptData['document_type'] ?? '';
        $store = strtolower((string)($receiptData['store'] ?? ''));
        $lowerFile = strtolower($filename);
        $billingPeriod = strtolower((string)($receiptData['billing_period'] ?? ''));

        // 1. Festnetz-Erkennung
        if ($docType === 'landline_bill'
            || str_contains($lowerFile, 'festnetz')
            || str_contains($billingPeriod, 'festnetz')
            || str_contains($store, 'festnetz')
        ) {
            return '#festnetzrechnung';
        }

        if (!empty($receiptData['items']) && is_array($receiptData['items'])) {
            foreach ($receiptData['items'] as $item) {
                $itemName = strtolower((string)($item['name'] ?? ''));
                if (str_contains($itemName, 'magentazuhause') || str_contains($itemName, 'festnetz') || str_contains($itemName, 'dsl') || str_contains($itemName, 'glasfaser')) {
                    return '#festnetzrechnung';
                }
            }
        }

        // 2. Mobilfunk-Erkennung
        if ($docType === 'mobile_bill'
            || str_contains($lowerFile, 'mobilfunk')
            || str_contains($billingPeriod, 'mobilfunk')
            || str_contains($store, 'mobilfunk')
            || !empty($receiptData['meta']['phone_profiles'])
            || !empty($receiptData['phone_profiles'])
        ) {
            return '#mobilfunkrechnung';
        }

        if (!empty($receiptData['items']) && is_array($receiptData['items'])) {
            foreach ($receiptData['items'] as $item) {
                $itemName = strtolower((string)($item['name'] ?? ''));
                if (str_contains($itemName, 'magentamobil') || str_contains($itemName, 'pluskarte') || str_contains($itemName, 'mobilfunk')) {
                    return '#mobilfunkrechnung';
                }
            }
        }

        // 3. Allgemeiner Telekom-Fallback
        if (str_contains($store, 'telekom') || str_contains($lowerFile, 'telekom')) {
            return '#mobilfunkrechnung';
        }

        return null;
    }

    /**
     * Prüft in postbox_sync_log, ob das Dokument bereits erfolgreich versendet wurde.
     */
    public function alreadyDispatched(string $fileHash, string $tag): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM postbox_sync_log 
                WHERE file_hash = :hash AND postbox_tag = :tag AND status = 'SUCCESS'
            ");
            $stmt->execute([':hash' => $fileHash, ':tag' => $tag]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            $this->logger->warn("PostboxForwarder: Duplikatsprüfung fehlgeschlagen: " . $e->getMessage());
            return false;
        }
    }

    public function isAlreadyDispatched(string $fileHash, ?string $tag = null): bool
    {
        try {
            if ($tag !== null) {
                return $this->alreadyDispatched($fileHash, $tag);
            }
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM postbox_sync_log WHERE file_hash = :hash AND status = 'SUCCESS'");
            $stmt->execute([':hash' => $fileHash]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Versendet die E-Mail mit PDF-Anhang und protokolliert das Ergebnis.
     */
    private function sendMail(
        string $toEmail,
        string $subject,
        string $tag,
        string $pdfContent,
        string $originalFilename,
        string $dispatchedFilename,
        string $fileHash
    ): bool {
        $fromEmail = trim((string)(
            $_ENV['IMAP_USER_KASSENBON']
            ?? $_ENV['MAIL_FROM']
            ?? $_ENV['MAIL_USERNAME']
            ?? ''
        ));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'kai@agent-smith.de';
        }

        $boundary = '=_kai_postbox_' . md5(uniqid((string)mt_rand(), true));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $fullHeaders = [
            'Date: ' . date('r'),
            'From: Kai System <' . $fromEmail . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
            'X-Mailer: Kai-Toolset/1.20',
        ];

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= "Automatische Weiterleitung an die d.velop Postbox via Kai Toolset.\r\n";
        $body .= "Tag: {$tag}\r\n";
        $body .= "Originaldatei: {$originalFilename}\r\n\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/pdf; name=\"{$dispatchedFilename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$dispatchedFilename}\"\r\n\r\n";
        $body .= chunk_split(base64_encode($pdfContent)) . "\r\n";
        $body .= "--{$boundary}--";

        $rawMimeMessage = implode("\r\n", $fullHeaders) . "\r\n\r\n" . $body;

        $sent = false;
        $status = 'FAILED';
        $errorMessage = null;

        // 1. Primär: Authentifizierter SMTP-Versand via Strato (Port 465 SSL)
        try {
            $smtpClient = new SmtpClient(logger: $this->logger);
            $sent = $smtpClient->send($toEmail, $subject, $rawMimeMessage, $fromEmail);
        } catch (Throwable $smtpErr) {
            $this->logger->warn("PostboxForwarder: SMTP-Versand fehlgeschlagen (" . $smtpErr->getMessage() . "), teste PHP mail()...");
            $errorMessage = $smtpErr->getMessage();
        }

        // 2. Fallback: PHP mail() mit Envelope-Sender -f
        if (!$sent) {
            try {
                $extraParams = "-f" . escapeshellarg($fromEmail);
                $mailHeaders = [
                    'From: Kai System <' . $fromEmail . '>',
                    'Reply-To: ' . $fromEmail,
                    'MIME-Version: 1.0',
                    'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
                    'X-Mailer: Kai-Toolset/1.20',
                ];
                $sent = @mail($toEmail, $encodedSubject, $body, implode("\r\n", $mailHeaders), $extraParams);
                if ($sent) {
                    $errorMessage = null;
                } else {
                    $errorMessage = ($errorMessage ? $errorMessage . ' | ' : '') . 'mail() mit Envelope-Sender lieferte false.';
                }
            } catch (Throwable $mailErr) {
                $errorMessage = ($errorMessage ? $errorMessage . ' | ' : '') . $mailErr->getMessage();
            }
        }

        if ($sent) {
            $status = 'SUCCESS';
            $this->logger->info("PostboxForwarder: Rechnung erfolgreich an d.velop versendet ({$dispatchedFilename} mit Betreff '{$subject}').");

            // 3. Kopie in "Gesendte Objekte" auf dem IMAP-Server ablegen
            try {
                if ($this->imapClient) {
                    $this->imapClient->appendSentMessage($rawMimeMessage);
                } elseif (!empty($_ENV['IMAP_USER_KASSENBON']) && !empty($_ENV['IMAP_PASS_KASSENBON'])) {
                    $imap = new ImapClient($_ENV['IMAP_USER_KASSENBON'], $_ENV['IMAP_PASS_KASSENBON']);
                    $imap->appendSentMessage($rawMimeMessage);
                    $imap->disconnect();
                }
            } catch (Throwable $imapErr) {
                $this->logger->warn("PostboxForwarder: Kopie in Gesendete Objekte konnte nicht abgelegt werden: " . $imapErr->getMessage());
            }

            try {
                $activityLogger = new ActivityLogger(Database::getInstance());
                $activityLogger->log('postbox', "Rechnung weitergeleitet an d.velop Postbox: {$dispatchedFilename} ({$tag})");
            } catch (Throwable) {}

            $this->logDispatch($fileHash, $originalFilename, $dispatchedFilename, $tag, $status, null);
            return true;
        }

        $this->logger->warn("PostboxForwarder: Versand an {$toEmail} fehlgeschlagen ($errorMessage).");
        $this->logDispatch($fileHash, $originalFilename, $dispatchedFilename, $tag, $status, $errorMessage);
        return false;
    }

    /**
     * Schreibt den Versandversuch in die Tabelle postbox_sync_log.
     */
    private function logDispatch(
        string $fileHash,
        string $originalFilename,
        string $dispatchedFilename,
        string $tag,
        string $status,
        ?string $errorMessage
    ): void {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO postbox_sync_log (
                    file_hash, source_channel, original_filename,
                    dispatched_filename, postbox_tag, dispatched_at, status, error_message
                ) VALUES (
                    :hash, 'IMAP', :orig, :disp, :tag, NOW(), :status, :err
                )
            ");
            $stmt->execute([
                ':hash' => $fileHash,
                ':orig' => $originalFilename,
                ':disp' => $dispatchedFilename,
                ':tag' => $tag,
                ':status' => $status,
                ':err' => $errorMessage
            ]);
        } catch (Throwable $e) {
            $this->logger->warn("PostboxForwarder: Konnte postbox_sync_log nicht schreiben: " . $e->getMessage());
        }
    }
}

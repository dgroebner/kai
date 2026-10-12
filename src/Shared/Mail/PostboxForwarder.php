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

    public function __construct(?PDO $db = null, ?Logger $logger = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Prüft, ob ein Beleg an die d.velop Postbox weitergeleitet werden soll,
     * und führt den Versand inklusive Duplikatschutz durch.
     */
    public function forwardIfEligible(
        string $pdfContent,
        string $originalFilename,
        string $fileHash,
        array $receiptData
    ): bool {
        $targetEmail = trim((string)($_ENV['DVELOP_POSTBOX_EMAIL'] ?? ''));
        if ($targetEmail === '') {
            $this->logger->info("PostboxForwarder: DVELOP_POSTBOX_EMAIL nicht konfiguriert. Versand übersprungen.");
            return false;
        }

        // Tag und Zieldateiname ermitteln
        $tag = $this->determinePostboxTag($receiptData, $originalFilename);
        if ($tag === null) {
            $this->logger->debug("PostboxForwarder: Kein passender Postbox-Tag für Beleg gefunden.");
            return false;
        }

        // Duplikatschutz prüfen
        if ($this->alreadyDispatched($fileHash, $tag)) {
            $this->logger->info("PostboxForwarder: Beleg ($fileHash) wurde bereits mit Tag '$tag' an d.velop versendet.");
            return true;
        }

        $docDate = $receiptData['date'] ?? date('Y-m-d');
        $store = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($receiptData['store'] ?? 'Telekom'));
        $dispatchedFilename = "{$docDate}_{$store}_Mobilfunk.pdf";

        $billingPeriod = !empty($receiptData['billing_period']) ? ' ' . trim((string)$receiptData['billing_period']) : '';
        // Das Tag MUSS am Anfang stehen und darf keine Leerzeichen enthalten:
        $subject = "{$tag} {$receiptData['store']} Mobilfunk-Rechnung{$billingPeriod}";

        return $this->sendMail($targetEmail, $subject, $tag, $pdfContent, $originalFilename, $dispatchedFilename, $fileHash);
    }

    /**
     * Ermittelt den passenden Tag für d.velop.
     * Aktuell: #mobilfunkrechnung für Mobilfunkbelege.
     */
    private function determinePostboxTag(array $receiptData, string $filename): ?string
    {
        $docType = $receiptData['document_type'] ?? '';
        $store = strtolower((string)($receiptData['store'] ?? ''));
        $lowerFile = strtolower($filename);

        if ($docType === 'mobile_bill') {
            return '#mobilfunkrechnung';
        }

        if (str_contains($store, 'telekom') || str_contains($lowerFile, 'mobilfunk') || str_contains($lowerFile, 'telekom')) {
            return '#mobilfunkrechnung';
        }

        return null;
    }

    /**
     * Prüft in postbox_sync_log, ob das Dokument bereits erfolgreich versendet wurde.
     */
    private function alreadyDispatched(string $fileHash, string $tag): bool
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
        $fromEmail = trim((string)($_ENV['MAIL_USERNAME'] ?? 'kai@system.local'));
        $boundary = '=_kai_postbox_' . md5(uniqid((string)mt_rand(), true));

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $headers = [
            'From: Kai System <' . $fromEmail . '>',
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

        $status = 'FAILED';
        $errorMessage = null;

        try {
            $sent = @mail($toEmail, $encodedSubject, $body, implode("\r\n", $headers));
            if ($sent) {
                $status = 'SUCCESS';
                $this->logger->info("PostboxForwarder: Rechnung erfolgreich an d.velop versendet ({$dispatchedFilename} mit Betreff '{$subject}').");

                try {
                    $activityLogger = new ActivityLogger(Database::getInstance());
                    $activityLogger->log('postbox', "Rechnung weitergeleitet an d.velop Postbox: {$dispatchedFilename} ({$tag})");
                } catch (Throwable) {}

                $this->logDispatch($fileHash, $originalFilename, $dispatchedFilename, $tag, $status, null);
                return true;
            }

            $errorMessage = 'mail() Rückgabewert war false.';
            $this->logger->warn("PostboxForwarder: Versand an {$toEmail} fehlgeschlagen.");
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            $this->logger->error("PostboxForwarder: Ausnahme beim Versand: " . $errorMessage);
        }

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

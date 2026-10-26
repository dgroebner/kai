<?php

namespace Kai\Tools\Projects\Mail;

use Exception;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Mail\SmtpClient;
use Throwable;

/**
 * Dedizierter E-Mail-Mailer für Projects.
 * Verwendet strikt das getrennte Postfach (z. B. projects@agent-smith.de)
 * und legt bewusst KEINE Kopien im Sent-Ordner ab.
 */
class ProjectsMailer
{
    private SmtpClient $smtpClient;
    private string $fromEmail;
    private string $fromName;
    private Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger ?? new Logger(14);

        $host = $_ENV['PROJECTS_SMTP_HOST'] ?? 'smtp.strato.de';
        $port = (int)($_ENV['PROJECTS_SMTP_PORT'] ?? 465);
        $user = $_ENV['PROJECTS_MAIL_USER'] ?? '';
        $pass = $_ENV['PROJECTS_MAIL_PASS'] ?? '';

        $this->fromEmail = trim((string)($_ENV['PROJECTS_MAIL_FROM'] ?? $user));
        $this->fromName = trim((string)($_ENV['PROJECTS_MAIL_FROM_NAME'] ?? 'Projects Companion'));

        $this->smtpClient = new SmtpClient(
            host: $host,
            port: $port,
            username: $user,
            password: $pass,
            logger: $this->logger
        );
    }

    /**
     * Sendet den 6-stelligen OTP-Code für das passwortlose Login.
     *
     * @throws Exception
     */
    public function sendOtpCode(string $recipientEmail, string $otpCode, ?string $recipientName = null): bool
    {
        $salutation = !empty($recipientName) ? "Hallo " . htmlspecialchars($recipientName) . "," : "Hallo,";
        $subject = "Dein Anmeldecode für Projects: {$otpCode}";

        $textBody = "{$salutation}\n\n"
            . "Dein persönlicher Einmalcode für den Login bei Projects lautet:\n\n"
            . "   >>> {$otpCode} <<<\n\n"
            . "Dieser Code ist 10 Minuten gültig.\n\n"
            . "Falls du diesen Code nicht angefordert hast, kannst du diese Nachricht einfach ignorieren.\n\n"
            . "Viele Grüße,\nDein Projects-Team";

        $htmlBody = "<div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 24px; color: #1e293b; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px;\">\n"
            . "  <div style=\"font-size: 20px; font-weight: 700; color: #0284c7; margin-bottom: 16px;\">Projects Companion</div>\n"
            . "  <p style=\"font-size: 15px; line-height: 1.5;\">{$salutation}</p>\n"
            . "  <p style=\"font-size: 15px; line-height: 1.5;\">Dein persönlicher Einmalcode für den Login lautet:</p>\n"
            . "  <div style=\"font-size: 32px; font-weight: 800; letter-spacing: 6px; color: #0f172a; background: #f1f5f9; padding: 16px; text-align: center; border-radius: 8px; margin: 24px 0;\">{$otpCode}</div>\n"
            . "  <p style=\"font-size: 13px; color: #64748b; line-height: 1.4;\">Der Code ist <strong>10 Minuten</strong> gültig und verfällt danach automatisch.</p>\n"
            . "  <hr style=\"border: none; border-top: 1px solid #e2e8f0; margin: 24px 0;\">\n"
            . "  <p style=\"font-size: 12px; color: #94a3b8;\">Falls du keinen Login angefordert hast, kannst du diese E-Mail ignorieren.</p>\n"
            . "</div>";

        return $this->sendMimeMessage($recipientEmail, $subject, $textBody, $htmlBody);
    }

    /**
     * Sendet eine Einladung an einen neuen Mitarbeiter.
     *
     * @throws Exception
     */
    public function sendInvitation(string $recipientEmail, string $appUrl, ?string $recipientName = null): bool
    {
        $salutation = !empty($recipientName) ? "Hallo " . htmlspecialchars($recipientName) . "," : "Hallo,";
        $subject = "Du wurdest zu Projects eingeladen";

        $textBody = "{$salutation}\n\n"
            . "Du wurdest zur Projektzeiterfassung in Projects freigeschaltet.\n\n"
            . "Du kannst dich ab sofort passwortlos mit deiner E-Mail-Adresse anmelden unter:\n"
            . "{$appUrl}\n\n"
            . "Beim Login erhältst du jeweils einen sicheren 6-stelligen Bestätigungscode per E-Mail.\n\n"
            . "Viele Grüße,\nDein Projects-Team";

        $htmlBody = "<div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 24px; color: #1e293b; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px;\">\n"
            . "  <div style=\"font-size: 20px; font-weight: 700; color: #0284c7; margin-bottom: 16px;\">Projects Companion</div>\n"
            . "  <p style=\"font-size: 15px; line-height: 1.5;\">{$salutation}</p>\n"
            . "  <p style=\"font-size: 15px; line-height: 1.5;\">Du wurdest zur Zeiterfassung in <strong>Projects</strong> freigeschaltet.</p>\n"
            . "  <div style=\"margin: 24px 0; text-align: center;\">\n"
            . "    <a href=\"{$appUrl}\" style=\"background: #0284c7; color: #ffffff; text-decoration: none; padding: 12px 24px; font-weight: 600; font-size: 15px; border-radius: 8px; display: inline-block;\">Jetzt anmelden</a>\n"
            . "  </div>\n"
            . "  <p style=\"font-size: 13px; color: #64748b; line-height: 1.4;\">Der Login funktioniert vollkommen passwortlos über sichere Einmalcodes per E-Mail.</p>\n"
            . "</div>";

        return $this->sendMimeMessage($recipientEmail, $subject, $textBody, $htmlBody);
    }

    /**
     * Baut eine saubere multipart/alternative MIME-Nachricht zusammen und versendet sie via SSL-SMTP.
     */
    private function sendMimeMessage(string $toEmail, string $subject, string $textBody, string $htmlBody): bool
    {
        $boundary = '=_proj_' . md5(uniqid((string)mt_rand(), true));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedFromName = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';

        $headers = [
            'Date: ' . date('r'),
            "From: {$encodedFromName} <{$this->fromEmail}>",
            "To: <{$toEmail}>",
            "Subject: {$encodedSubject}",
            "Reply-To: {$this->fromEmail}",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
            'X-Mailer: Projects-Companion/1.0',
        ];

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--{$boundary}--";

        $rawMessage = implode("\r\n", $headers) . "\r\n\r\n" . $body;

        try {
            return $this->smtpClient->send($toEmail, $subject, $rawMessage, $this->fromEmail);
        } catch (Throwable $e) {
            $this->logger->error("ProjectsMailer: Fehler beim E-Mail-Versand an {$toEmail}", ['error' => $e->getMessage()]);
            throw new Exception("E-Mail konnte nicht versendet werden: " . $e->getMessage());
        }
    }
}

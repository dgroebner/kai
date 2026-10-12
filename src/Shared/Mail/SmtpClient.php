<?php

namespace Kai\Tools\Shared\Mail;

use Exception;
use Kai\Tools\Shared\Log\Logger;
use Throwable;

class SmtpClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private Logger $logger;

    public function __construct(
        ?string $host = null,
        ?int $port = null,
        ?string $username = null,
        ?string $password = null,
        ?Logger $logger = null
    ) {
        $imapHost = (string)($_ENV['IMAP_HOST'] ?? 'imap.strato.de');
        $defaultSmtpHost = str_starts_with($imapHost, 'imap.')
            ? 'smtp.' . substr($imapHost, 5)
            : 'smtp.strato.de';

        $this->host = $host ?? ($_ENV['SMTP_HOST'] ?? $defaultSmtpHost);
        $this->port = $port ?? (int)($_ENV['SMTP_PORT'] ?? 465);
        $this->username = $username ?? ($_ENV['SMTP_USER'] ?? ($_ENV['IMAP_USER_KASSENBON'] ?? ''));
        $this->password = $password ?? ($_ENV['SMTP_PASS'] ?? ($_ENV['IMAP_PASS_KASSENBON'] ?? ''));
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Sendet eine E-Mail via SSL-SMTP (Port 465).
     *
     * @throws Exception
     */
    public function send(string $toEmail, string $subject, string $rawMessage, string $fromEmail): bool
    {
        if ($this->username === '' || $this->password === '') {
            throw new Exception("SMTP: Keine Benutzerdaten konfiguriert.");
        }

        $protocol = ($this->port === 465) ? 'ssl://' : '';
        $remoteSocket = $protocol . $this->host . ':' . $this->port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ]
        ]);

        $socket = @stream_socket_client(
            $remoteSocket,
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            throw new Exception("SMTP-Verbindung zu {$remoteSocket} fehlgeschlagen: $errstr ($errno)");
        }

        stream_set_timeout($socket, 15);

        try {
            $this->readExpected($socket, 220);

            $this->sendCommand($socket, "EHLO " . gethostname());
            $this->readExpected($socket, 250);

            // AUTH LOGIN
            $this->sendCommand($socket, "AUTH LOGIN");
            $this->readExpected($socket, 334);

            $this->sendCommand($socket, base64_encode($this->username));
            $this->readExpected($socket, 334);

            $this->sendCommand($socket, base64_encode($this->password));
            $this->readExpected($socket, 235);

            // MAIL FROM & RCPT TO
            $this->sendCommand($socket, "MAIL FROM:<{$fromEmail}>");
            $this->readExpected($socket, 250);

            $this->sendCommand($socket, "RCPT TO:<{$toEmail}>");
            $this->readExpected($socket, 250);

            // DATA
            $this->sendCommand($socket, "DATA");
            $this->readExpected($socket, 354);

            // Nachricht senden
            $this->sendCommand($socket, $rawMessage . "\r\n.");
            $this->readExpected($socket, 250);

            // QUIT
            $this->sendCommand($socket, "QUIT");
            try {
                $this->readExpected($socket, 221);
            } catch (Throwable) {}

            $this->logger->info("SmtpClient: E-Mail via SMTP an {$toEmail} erfolgreich versendet.");
            return true;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function sendCommand($socket, string $cmd): void
    {
        fwrite($socket, $cmd . "\r\n");
    }

    private function readExpected($socket, int $expectedCode): string
    {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // Wenn nach dem Statuscode ein Leerzeichen folgt (z.B. "250 OK"), ist die Antwort abgeschlossen
            if (preg_match('/^(\d{3})\s/', $line, $matches)) {
                $code = (int)$matches[1];
                if ($code !== $expectedCode) {
                    throw new Exception("SMTP-Fehler: Erwartet {$expectedCode}, erhalten: " . trim($response));
                }
                return $response;
            }
        }

        if ($response === '') {
            throw new Exception("SMTP-Fehler: Keine Antwort vom Server erhalten.");
        }

        return $response;
    }
}

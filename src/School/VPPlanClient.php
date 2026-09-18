<?php

namespace Kai\Tools\School;

use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\System\SystemSettingsRepository;

/**
 * HTTP Basic Auth Client zum Abruf der VPPlan24 XML-Dateien von stundenplan24.de.
 */
class VPPlanClient
{
    private Logger $logger;
    private SystemSettingsRepository $settingsRepo;

    public function __construct(
        ?Logger $logger = null,
        ?SystemSettingsRepository $settingsRepo = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->settingsRepo = $settingsRepo ?? new SystemSettingsRepository();
    }

    /**
     * Ermittelt die konfigurierte Schulnummer.
     */
    public function getSchoolNumber(): string
    {
        $fromEnv = (string)($_ENV['STUNDENPLAN_SCHOOL_NUMBER'] ?? '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return (string)$this->settingsRepo->get('school_number', '10058903');
    }

    /**
     * Ermittelt den konfigurierten Benutzernamen.
     */
    public function getUsername(): string
    {
        $fromEnv = (string)($_ENV['STUNDENPLAN_USER'] ?? '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return (string)$this->settingsRepo->get('school_username', '');
    }

    /**
     * Ermittelt das konfigurierte Kennwort.
     */
    public function getPassword(): string
    {
        $fromEnv = (string)($_ENV['STUNDENPLAN_PASSWORD'] ?? '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return (string)$this->settingsRepo->get('school_password', '');
    }

    /**
     * Ruft die XML-Daten für ein bestimmtes Datum ab.
     *
     * @param string $date Datum im Format YYYYMMDD oder YYYY-MM-DD
     * @return array{status: int, content: ?string, error: ?string}
     */
    public function fetch(string $date): array
    {
        $dateFormatted = str_replace('-', '', $date);
        $schoolNumber = $this->getSchoolNumber();
        $username = $this->getUsername();
        $password = $this->getPassword();

        if ($username === '' || $password === '') {
            return [
                'status' => 0,
                'content' => null,
                'error' => 'Keine Zugangsdaten für Stundenplan24 hinterlegt (.env oder System-Einstellungen).',
            ];
        }

        $url = "https://www.stundenplan24.de/{$schoolNumber}/mobil/mobdaten/PlanKl{$dateFormatted}.xml";

        $disableSsl = filter_var($_ENV['STUNDENPLAN_DISABLE_SSL'] ?? $_ENV['GEMINI_DISABLE_SSL'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => "{$username}:{$password}",
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => !$disableSsl,
            CURLOPT_SSL_VERIFYHOST => $disableSsl ? 0 : 2,
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Kai-School-Client/1.0',
                'Accept: application/xml, text/xml, */*',
            ],
        ]);

        $content = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        // Automatischer Fallback bei fehlendem lokalen CA-Zertifikatsbundle (z. B. lokale Entwicklung oder Windows-Host)
        if (($curlErrno === 60 || str_contains($curlError, 'certificate')) && !$disableSsl) {
            $this->logger->warn('VPPlanClient: Lokales SSL-CA-Zertifikat fehlt oder ungültig. Führe Fallback-Anfrage ohne Peer-Verifikation durch.', [
                'url' => $url,
                'original_error' => $curlError,
            ]);

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $content = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
        }

        curl_close($ch);

        if ($httpCode === 200 && is_string($content) && $content !== '') {
            return [
                'status' => 200,
                'content' => $content,
                'error' => null,
            ];
        }

        if ($httpCode === 404) {
            // Plan für diesen Tag noch nicht von der Schule hochgeladen
            return [
                'status' => 404,
                'content' => null,
                'error' => "Für das Datum {$date} liegt auf dem Server noch kein Plan vor (HTTP 404).",
            ];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            $this->logger->error('VPPlanClient: Authentifizierung fehlgeschlagen.', [
                'school' => $schoolNumber,
                'http_code' => $httpCode,
            ]);

            return [
                'status' => $httpCode,
                'content' => null,
                'error' => 'Authentifizierung an stundenplan24.de fehlgeschlagen. Bitte Zugangsdaten prüfen.',
            ];
        }

        $errorMsg = $curlError !== '' ? $curlError : "HTTP Status {$httpCode}";
        $this->logger->warn('VPPlanClient: Fehler beim Abruf des Plans.', [
            'date' => $date,
            'url' => $url,
            'error' => $errorMsg,
        ]);

        return [
            'status' => $httpCode,
            'content' => null,
            'error' => $errorMsg,
        ];
    }
}

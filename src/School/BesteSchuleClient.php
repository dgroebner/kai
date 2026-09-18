<?php

namespace Kai\Tools\School;

use Kai\Tools\Shared\Log\Logger;
use RuntimeException;

class BesteSchuleClient
{
    private const BASE_URL = 'https://beste.schule/api';
    private string $token;
    private Logger $logger;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? ($_ENV['BESTE_SCHULE_API_TOKEN'] ?? '');
        $this->logger = new Logger();
    }

    public function isConfigured(): bool
    {
        return !empty($this->token);
    }

    /**
     * @return array|null
     */
    public function getMe(): ?array
    {
        return $this->request('GET', '/me');
    }

    public function getGrades(string $besteSchuleStudentId): ?array
    {
        return $this->request('GET', '/grades?filter[student]=' . urlencode($besteSchuleStudentId) . '&include=collection,collection.subject');
    }

    public function getAbsences(string $besteSchuleStudentId): ?array
    {
        // Hole Fehlzeiten, lade Verifikation und Typ mit
        return $this->request('GET', '/absences?filter[student]=' . urlencode($besteSchuleStudentId) . '&include=verification');
    }

    public function getJournal(string $besteSchuleStudentId): ?array
    {
        // Journal/Hausaufgaben etc.
        // Die API hat "/journal/lesson-student", was die Teilnahme an einer Stunde darstellt.
        // missing_homework ist dort enthalten.
        return $this->request('GET', '/journal/lesson-student?filter[student]=' . urlencode($besteSchuleStudentId) . '&include=lesson');
    }

    private function request(string $method, string $path, array $data = []): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $url = self::BASE_URL . $path;
        
        $options = [
            'http' => [
                'header'  => [
                    "Authorization: Bearer {$this->token}",
                    "Accept: application/json",
                    "Content-Type: application/json"
                ],
                'method'  => $method,
                'ignore_errors' => true,
            ]
        ];

        // Lokaler SSL-Bypass (wie im Rest von KAI für XAMPP/Localhost)
        if (($_ENV['GEMINI_DISABLE_SSL'] ?? 'false') === 'true') {
            $options['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }

        if ($method !== 'GET' && !empty($data)) {
            $options['http']['content'] = json_encode($data);
        }

        $context  = stream_context_create($options);
        
        $this->logger->info("BesteSchuleClient: Sende API-Request", ['method' => $method, 'url' => $url]);
        
        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $error = error_get_last();
            $this->logger->error("BesteSchuleClient: Netzwerkfehler bei {$path}", ['error' => $error]);
            return null;
        }

        $responseCode = $http_response_header[0] ?? '';
        if (strpos($responseCode, '200') === false) {
            $this->logger->warn("BesteSchuleClient: Fehlgeschlagener API-Request", ['path' => $path, 'status' => $responseCode, 'response' => $result]);
            return null;
        }

        $this->logger->info("BesteSchuleClient: API-Request erfolgreich", ['path' => $path, 'status' => $responseCode]);

        $json = json_decode($result, true);
        if (!is_array($json)) {
            $this->logger->error("BesteSchuleClient: Ungültiges JSON in der Antwort", ['path' => $path]);
            return null;
        }

        return $json['data'] ?? $json;
    }
}

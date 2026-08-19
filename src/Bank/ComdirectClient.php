<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Log\Logger;
use Exception;

class ComdirectClient
{
    private string $clientId;
    private string $clientSecret;
    private Logger $logger;
    private string $apiHost = 'api.comdirect.de';

    public function __construct()
    {
        $this->logger = new Logger(14);
        $this->clientId = $_ENV['COMDIRECT_CLIENT_ID'] ?? '';
        $this->clientSecret = $_ENV['COMDIRECT_CLIENT_SECRET'] ?? '';
    }

    /**
     * Führt eine HTTP-Anfrage an die comdirect API aus.
     */
    private function request(string $method, string $path, array $headers = [], $body = null): array
    {
		if (strpos($path, '/oauth/') === false) {
           $url = "https://{$this->apiHost}/api{$path}";
	    } else {
		   $url = "https://{$this->apiHost}{$path}";
	    }

        $ch = curl_init();
		
		$this->logger->debug("Comdirectclient curl request to : $method $url");

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));

        // Request-Info header generieren falls nicht vorhanden
        $hasRequestInfo = false;
        foreach ($headers as $h) {
            if (stripos($h, 'x-http-request-info') === 0) {
                $hasRequestInfo = true;
                break;
            }
        }
        
        // Der Header wird nun auch für POST, PATCH etc. gesendet (außer bei /oauth/).
        if (!$hasRequestInfo && strpos($path, '/oauth/') === false) {
            $reqInfo = json_encode([
                'clientRequestId' => [
                    'sessionId' => str_replace('-', '', $this->generateUuid()),
                    'requestId' => str_replace('-', '', $this->generateUuid())
                ]
            ]);
            $headers[] = "x-http-request-info: {$reqInfo}";
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null) {
            if (is_array($body)) {
                $bodyString = http_build_query($body);
            } else {
                $bodyString = $body;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyString);
        }

        // Header mit auslesen für x-once-authentication-info
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        });

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("ComdirectClient curl error: {$error}", ['path' => $path]);
            throw new Exception("Netzwerkfehler bei Verbindung zu comdirect.");
        }

        $decodedBody = json_decode($responseBody, true) ?: [];
		
		$ret = [
            'code' => $httpCode,
            'body' => $decodedBody,
            'headers' => $responseHeaders,
            'raw_body' => $responseBody
        ];
		
		$logBody = json_encode($ret);
		$this->logger->debug("Comdirectclient curl response: $logBody", ['path' => $path]);

        return $ret;
    }

    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Holt ein initiales Access Token mittels Username (Zugangsnummer) und Passwort (PIN)
     */
    public function getAccessTokenWithPassword(string $username, string $password): array
    {
		$maskedUsername = (strlen($username) > 1) ? substr($username, 1, 1) . '...' . substr($username, -1) : $username;
		$maskedPassword = (strlen($password) > 1) ? substr($password, 1, 1) . '...' . substr($password, -1) : $password; 
		$this->logger->debug("ComdirectClient: getAccessTokenWithPassword $maskedUsername / $maskedPassword");    

        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'
        ];
        $body = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password
        ];

        $res = $this->request('POST', '/oauth/token', $headers, $body);
        if ($res['code'] !== 200) {
            $msg = $res['body']['error_description'] ?? $res['body']['error'] ?? 'Unbekannter Fehler';
            throw new Exception("Login failed: " . $msg);
        }

        $res['body']['created_at'] = time();
        return $res['body'];
    }

    /**
     * Holt aktuelle Sessions des Clients.
     */
    public function getSessions(string $accessToken): array
    {
        $headers = [
            'Accept: application/json',
            "Authorization: Bearer {$accessToken}"
        ];

        $res = $this->request('GET', "/session/clients/user/v1/sessions", $headers);
        if ($res['code'] !== 200) {
            throw new Exception("Abrufen der Session-ID fehlgeschlagen.");
        }

        return $res['body'];
    }

    /**
     * Validiert die Session und stößt die TAN-Freigabe an (photoTAN-Push).
     */
    public function validateSession(string $accessToken, string $sessionId, array $sessionObj): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}"
        ];

        // Die Session-Daten für Validierung senden
        $body = json_encode($sessionObj);
		
	    $this->logger->debug("ComdirectClient: validateSession request body: $body ");

        $res = $this->request('POST', "/session/clients/user/v1/sessions/{$sessionId}/validate", $headers, $body);
        if ($res['code'] !== 201) {
            throw new Exception("Session-Validierung fehlgeschlagen.");
        }

        $tanInfoStr = $res['headers']['x-once-authentication-info'] ?? '';
        if (empty($tanInfoStr)) {
            throw new Exception("Keine TAN-Informationen vom Server erhalten.");
        }

        $tanInfo = json_decode($tanInfoStr, true);
        if (!$tanInfo) {
            throw new Exception("Ungültige TAN-Informationen im Header.");
        }

        return $tanInfo;
    }

    /**
     * Aktiviert die Session. Bei photoTAN-Push wird dies nach Bestätigung in der App aufgerufen.
     */
    public function activateSession(string $accessToken, string $sessionId, array $sessionObj, array $tanInfo): bool
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}",
            'x-once-authentication-info: ' . json_encode(['id' => $tanInfo['id']])
        ];

        $body = json_encode($sessionObj);

        $res = $this->request('PATCH', "/session/clients/user/v1/sessions/{$sessionId}", $headers, $body);
        
        // 200 bedeutet erfolgreich aktiviert
        if ($res['code'] === 200) {
            return true;
        }

        // Falls unprocessable oder pending, ist die TAN noch nicht freigegeben
        return false;
    }

    /**
     * Führt den Secondary Flow durch, um das endgültige Banking-Token zu erhalten.
     */
    public function getSecondaryToken(string $initialAccessToken): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            "Authorization: Bearer {$initialAccessToken}"
        ];
        $body = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'cd_secondary',
            'token' => $initialAccessToken
        ];

        $res = $this->request('POST', '/oauth/token', $headers, $body);
        if ($res['code'] !== 200) {
            $msg = $res['body']['error_description'] ?? $res['body']['error'] ?? 'Unbekannter Fehler';
            throw new Exception("Secondary OAuth Upgrade fehlgeschlagen: " . $msg);
        }

        $res['body']['created_at'] = time();
        return $res['body'];
    }

    /**
     * Erneuert das Access Token über das Refresh Token.
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded'
        ];
        $body = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken
        ];

        $res = $this->request('POST', '/oauth/token', $headers, $body);
        if ($res['code'] !== 200) {
            $msg = $res['body']['error_description'] ?? $res['body']['error'] ?? 'Unbekannter Fehler';
            throw new Exception("Token-Refresh fehlgeschlagen: " . $msg);
        }

        $res['body']['created_at'] = time();
        return $res['body'];
    }

    /**
     * Ruft alle Konten und Salden ab.
     */
    public function getAccounts(string $accessToken): array
    {
        $headers = [
            'Accept: application/json',
            "Authorization: Bearer {$accessToken}"
        ];

        $res = $this->request('GET', "/banking/clients/user/v2/accounts/balances", $headers);
        if ($res['code'] !== 200) {
            throw new Exception("Abrufen der Kontodaten fehlgeschlagen.");
        }

        return $res['body'];
    }

    /**
     * Ruft Transaktionen für ein Konto ab.
     */
    public function getTransactions(string $accessToken, string $accountId, ?string $minBookingDate = null): array
    {
        $path = "/banking/v1/accounts/{$accountId}/transactions";

        // Standardmäßig filtern nach booking-date-from falls minBookingDate gesetzt ist
        $queryParams = [];
        if ($minBookingDate) {
            $queryParams['booking-date-from'] = $minBookingDate;
        }

        $queryString = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';

        $headers = [
            'Accept: application/json',
            "Authorization: Bearer {$accessToken}"
        ];

        $res = $this->request('GET', $path . $queryString, $headers);
        if ($res['code'] !== 200) {
            throw new Exception("Abrufen der Umsätze fehlgeschlagen.");
        }

        return $res['body'];
    }
}

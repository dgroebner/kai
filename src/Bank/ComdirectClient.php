<?php

namespace Kai\Tools\Bank;

use Kai\Tools\Shared\Log\Logger;
use Exception;

class ComdirectClient
{
    private string $clientId;
    private string $clientSecret;
    private bool $simulationMode;
    private Logger $logger;
    private string $apiHost = 'api.comdirect.de';

    public function __construct()
    {
        $this->logger = new Logger(14);
        $this->clientId = $_ENV['COMDIRECT_CLIENT_ID'] ?? '';
        $this->clientSecret = $_ENV['COMDIRECT_CLIENT_SECRET'] ?? '';
        
        $simConfig = $_ENV['COMDIRECT_SIMULATION_MODE'] ?? 'false';
        $this->simulationMode = (strtolower($simConfig) === 'true') || empty($this->clientId) || empty($this->clientSecret);
    }

    /**
     * Führt eine HTTP-Anfrage an die comdirect API aus.
     */
    private function request(string $method, string $path, array $headers = [], $body = null): array
    {
        $url = "https://{$this->apiHost}{$path}";
        $ch = curl_init();

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
        
        // FIX: $method === 'GET' wurde entfernt. 
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

        return [
            'code' => $httpCode,
            'body' => $decodedBody,
            'headers' => $responseHeaders,
            'raw_body' => $responseBody
        ];
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
		if ($this->simulationMode) {
            $this->logger->info("ComdirectClient: [SIMULATION] getAccessTokenWithPassword");
            return [
                'access_token' => 'sim_initial_access_' . bin2hex(random_bytes(16)),
                'refresh_token' => 'sim_refresh_' . bin2hex(random_bytes(16)),
                'expires_in' => 1200,
                'created_at' => time()
            ];
        } else {
            $maskedUsername = (strlen($username) > 1) ? $username . '...' . substr($username, -1) : $username;
            $maskedPassword = (strlen($password) > 1) ? $password . '...' . substr($password, -1) : $password; 
            $this->logger->debug("ComdirectClient: getAccessTokenWithPassword $maskedUsername / $maskedPassword");    
        }

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

        $this->logger->debug("ComdirectClient: getAccessTokenWithPassword response: {$res['body']}");	

        $res['body']['created_at'] = time();
        return $res['body'];
    }

    /**
     * Holt aktuelle Sessions des Clients.
     */
    public function getSessions(string $accessToken): array
    {
        if ($this->simulationMode) {
            return [
                [
                    'id' => 12345,
                    'identifier' => 'mock-session-id-12345',
                    'sessionTanActive' => false,
                    'activated2FA' => false
                ]
            ];
        }

        $headers = [
            'Accept: application/json',
            "Authorization: Bearer {$accessToken}"
        ];

        $res = $this->request('GET', '/session/clients/user/v1/sessions', $headers);
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
        if ($this->simulationMode) {
            $this->logger->info("ComdirectClient: [SIMULATION] validateSession");
            return [
                'tan_id' => 'sim-tan-id-' . bin2hex(random_bytes(8)),
                'type' => 'P_TAN_PUSH'
            ];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}"
        ];

        // Die Session-Daten für Validierung senden
        $body = json_encode($sessionObj);

        $res = $this->request('POST', "/session/clients/user/v1/sessions/{$sessionId}/validate", $headers, $body);
        if ($res['code'] !== 200) {
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
        if ($this->simulationMode) {
            // Im Simulationsmodus prüfen wir den Poll-Zähler aus der Session, um photoTAN Push-Verhalten nachzuahmen
            if (!isset($_SESSION['sim_phototan_polls'])) {
                $_SESSION['sim_phototan_polls'] = 0;
            }
            $_SESSION['sim_phototan_polls']++;

            $this->logger->debug("ComdirectClient: [SIMULATION] activateSession poll count: " . $_SESSION['sim_phototan_polls']);
            
            // Erst ab dem 2. Poll geben wir Erfolg zurück, um das Laden im Dialog zu simulieren
            if ($_SESSION['sim_phototan_polls'] >= 2) {
                return true;
            }
            return false; // simuliert PENDING/no-action
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}",
            'x-once-authentication-info: ' . json_encode($tanInfo)
        ];

        // Bei photoTAN-Push bleibt x-once-authentication-code leer
        $headers[] = 'x-once-authentication-code: ';

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
        if ($this->simulationMode) {
            $this->logger->info("ComdirectClient: [SIMULATION] getSecondaryToken");
            return [
                'access_token' => 'sim_secondary_access_' . bin2hex(random_bytes(16)),
                'refresh_token' => 'sim_refresh_' . bin2hex(random_bytes(16)),
                'expires_in' => 1800,
                'created_at' => time()
            ];
        }

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
        if ($this->simulationMode) {
            $this->logger->info("ComdirectClient: [SIMULATION] refreshAccessToken");
            return [
                'access_token' => 'sim_refreshed_access_' . bin2hex(random_bytes(16)),
                'refresh_token' => 'sim_refresh_' . bin2hex(random_bytes(16)),
                'expires_in' => 1800,
                'created_at' => time()
            ];
        }

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
        if ($this->simulationMode) {
            return [
                'values' => [
                    [
                        'account' => [
                            'accountId' => 'checking-uuid-1111',
                            'accountDisplayId' => 'checking-display-1111',
                            'iban' => 'DE12345678901234567890',
                            'accountType' => [
                                'key' => 'checking'
                            ]
                        ],
                        'balance' => [
                            'balance' => [
                                'value' => 2450.75
                            ]
                        ]
                    ],
                    [
                        'account' => [
                            'accountId' => 'savings-uuid-2222',
                            'accountDisplayId' => 'savings-display-2222',
                            'iban' => 'DE98765432109876543210',
                            'accountType' => [
                                'key' => 'savings'
                            ]
                        ],
                        'balance' => [
                            'balance' => [
                                'value' => 15000.00
                            ]
                        ]
                    ]
                ]
            ];
        }

        $headers = [
            'Accept: application/json',
            "Authorization: Bearer {$accessToken}"
        ];

        $res = $this->request('GET', '/banking/clients/user/v2/accounts/balances', $headers);
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
        if ($this->simulationMode) {
            // Mock-Transaktionen nach dem 15.08.2026 generieren
            $mockData = [];
            if ($accountId === 'checking-uuid-1111') {
                $mockData = [
                    'values' => [
                        [
                            'transactionId' => 'api_tx_checking_001',
                            'bookingDate' => '2026-08-16',
                            'valutaDate' => '2026-08-16',
                            'remittanceInfo' => 'REWE Supermarkt Filiale 42 Hamburg',
                            'amount' => [
                                'value' => -45.50
                            ],
                            'bookingStatus' => 'BOOKED'
                        ],
                        [
                            'transactionId' => 'api_tx_checking_002',
                            'bookingDate' => '2026-08-17',
                            'valutaDate' => '2026-08-17',
                            'remittanceInfo' => 'EDEKA-Aktiv-Markt Hamburg',
                            'amount' => [
                                'value' => -12.30
                            ],
                            'bookingStatus' => 'BOOKED'
                        ],
                        [
                            'transactionId' => 'api_tx_checking_003',
                            'bookingDate' => '2026-08-17',
                            'valutaDate' => '2026-08-17',
                            'remittanceInfo' => 'Gehaltszahlung Agent Smith GmbH',
                            'amount' => [
                                'value' => 2500.00
                            ],
                            'bookingStatus' => 'BOOKED'
                        ]
                    ]
                ];
            } elseif ($accountId === 'savings-uuid-2222') {
                $mockData = [
                    'values' => [
                        [
                            'transactionId' => 'api_tx_savings_001',
                            'bookingDate' => '2026-08-16',
                            'valutaDate' => '2026-08-16',
                            'remittanceInfo' => 'Umbuchung von Girokonto',
                            'amount' => [
                                'value' => 500.00
                            ],
                            'bookingStatus' => 'BOOKED'
                        ]
                    ]
                ];
            }

            // Filtern nach minBookingDate falls übergeben
            if ($minBookingDate && isset($mockData['values'])) {
                $mockData['values'] = array_values(array_filter($mockData['values'], function($tx) use ($minBookingDate) {
                    return $tx['bookingDate'] > $minBookingDate;
                }));
            }

            return $mockData;
        }

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

    public function isSimulationMode(): bool
    {
        return $this->simulationMode;
    }
}

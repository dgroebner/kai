<?php
namespace Kai\Tools\PVCharge;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Exception;

class SolarForecastService {
    private string $apiUrl;
    private Logger $logger;
    private PDO $db;

    public function __construct(?string $apiUrl = null) {
		if ($apiUrl === null) {
            // Basis-URL ohne Key
            $baseUrl = 'https://api.forecast.solar/estimate/51.2956/12.4541/45/0/4.7';
            
            // API-Key aus $_ENV oder getenv() auslesen
            $apiKey = $_ENV['FORECAST_SOLAR_API_KEY'] ?? getenv('FORECAST_SOLAR_API_KEY') ?: null;
            
            // Wenn ein Key vorhanden ist, hängen wir ihn als Query-Parameter an
            if ($apiKey) {
                $baseUrl .= '?apikey=' . urlencode($apiKey);
            }
            
            $apiUrl = $baseUrl;
        }

        $this->apiUrl = $apiUrl;
        $this->logger = new Logger();
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Fetches solar forecast from the API and updates the database tables.
     * Overwrites existing dates/hours to keep predictions updated without duplicates.
     *
     * @return bool True if successful, false otherwise
     * @throws Exception on critical failures
     */
    public function fetchAndStoreForecast(): bool {
        $this->logger->info("SolarForecastService: Starte Abruf von Solar-Prognosedaten...");

        $ch = curl_init($this->apiUrl);
        if ($ch === false) {
            throw new Exception("SolarForecastService: cURL konnte nicht initialisiert werden.");
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: KaiPVChargeManagement/1.0'
        ]);

        // SSL Bypass check identical to GeminiClient (for local dev environments)
        if (($_ENV['GEMINI_DISABLE_SSL'] ?? 'false') === 'true') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            $this->logger->error("SolarForecastService: cURL Netzwerkfehler: " . $errorMsg);
            return false;
        }
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->error("SolarForecastService: API lehnte Anfrage ab.", [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }

        $data = json_decode($response, true);
        if (empty($data) || !isset($data['result'])) {
            $this->logger->error("SolarForecastService: Ungültige oder leere API Antwort erhalten.", [
                'raw_response' => $response
            ]);
            return false;
        }

        $result = $data['result'];
        $watts = $result['watts'] ?? [];
        $wattHours = $result['watt_hours'] ?? [];
        $wattHoursDay = $result['watt_hours_day'] ?? [];

        if (empty($watts) && empty($wattHoursDay)) {
            $this->logger->warn("SolarForecastService: Keine Zeitreihendaten in API Antwort gefunden.");
            return false;
        }

        try {
            // 1. Hourly Forecasts speichern
            $hourlyRecords = [];
            foreach ($watts as $time => $val) {
                $hourlyRecords[$time] = [
                    'time' => $time,
                    'watts' => (int)$val,
                    'watt_hours' => (int)($wattHours[$time] ?? 0)
                ];
            }

            if (!empty($hourlyRecords)) {
                $this->logger->info("SolarForecastService: Speichere " . count($hourlyRecords) . " stündliche Datensätze...");
                
                $this->db->beginTransaction();
                $stmtHourly = $this->db->prepare("
                    INSERT INTO pv_forecast_hourly (forecast_time, watts, watt_hours)
                    VALUES (:time, :watts, :watt_hours)
                    ON DUPLICATE KEY UPDATE 
                        watts = VALUES(watts), 
                        watt_hours = VALUES(watt_hours)
                ");

                foreach ($hourlyRecords as $record) {
                    $stmtHourly->execute([
                        ':time' => $record['time'],
                        ':watts' => $record['watts'],
                        ':watt_hours' => $record['watt_hours']
                    ]);
                }
                $this->db->commit();
            }

            // 2. Daily Forecasts speichern
            if (!empty($wattHoursDay)) {
                $this->logger->info("SolarForecastService: Speichere " . count($wattHoursDay) . " tägliche Datensätze...");
                
                $this->db->beginTransaction();
                $stmtDaily = $this->db->prepare("
                    INSERT INTO pv_forecast_daily (forecast_date, watt_hours_day)
                    VALUES (:date, :watt_hours_day)
                    ON DUPLICATE KEY UPDATE 
                        watt_hours_day = VALUES(watt_hours_day)
                ");

                foreach ($wattHoursDay as $date => $wh) {
                    $stmtDaily->execute([
                        ':date' => $date,
                        ':watt_hours_day' => (int)$wh
                    ]);
                }
                $this->db->commit();
            }

            $this->logger->info("SolarForecastService: Prognosedaten erfolgreich importiert.");
            return true;

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error("SolarForecastService: Fehler beim Speichern der Prognosedaten.", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

<?php

namespace Kai\Tools\Car;

use DateTime;
use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;
use PDO;

class TelemetryRepository
{
    private Database $db;
    private PDO $dbCon;
    private Logger $logger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->dbCon = $this->db->getConnection();
        $this->logger = new Logger(14);
    }

    /**
     * Speichert / aktualisiert den Live-Status in vehicle_state.
     */
    public function saveState(array $data): bool
    {
        try {
            $vin = $data['vin'];
            $capturedAtObj = new DateTime($data['captured_at']);
            $carCapturedAt = $capturedAtObj->format('Y-m-d H:i:s');

            $socPercent = isset($data['battery']['soc']) ? (int)$data['battery']['soc'] : null;
            $targetSoc = isset($data['battery']['target_soc']) ? (int)$data['battery']['target_soc'] : null;
            $chargePowerKw = isset($data['battery']['charge_power_kw']) ? (float)$data['battery']['charge_power_kw'] : null;
            $batteryTempMax = isset($data['battery']['max_temp_c']) ? (float)$data['battery']['max_temp_c'] : null;
            $batteryTempMin = isset($data['battery']['min_temp_c']) ? (float)$data['battery']['min_temp_c'] : null;
            $estimatedFinishAt = $data['battery']['estimated_finish_at'] ?? null;

            $chargingState = $data['status']['charging_state'] ?? null;
            $plugConnected = isset($data['status']['plug_connected']) ? ($data['status']['plug_connected'] ? 1 : 0) : null;
            $isLocked = isset($data['status']['is_locked']) ? ($data['status']['is_locked'] ? 1 : 0) : null;
            $mileageKm = isset($data['status']['mileage_km']) ? (int)$data['status']['mileage_km'] : null;
            $outdoorTempC = isset($data['status']['outdoor_temp_c']) ? (float)$data['status']['outdoor_temp_c'] : null;

            $latitude = isset($data['status']['latitude']) && is_numeric($data['status']['latitude']) ? (float)$data['status']['latitude'] : null;
            $longitude = isset($data['status']['longitude']) && is_numeric($data['status']['longitude']) ? (float)$data['status']['longitude'] : null;

            // Reale Reichweite (z. B. aus TRONITY) übernehmen, falls vorhanden (> 0)
            $incomingRange = isset($data['status']['range_km']) ? (int)$data['status']['range_km'] : 0;
            $rangeKm = $incomingRange > 0 ? $incomingRange : 0;

            // Vorhandenen State für diesen VIN abrufen
            $stmtCurrent = $this->dbCon->prepare("SELECT * FROM vehicle_state WHERE vin = :vin");
            $stmtCurrent->execute([':vin' => $vin]);
            $currentState = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

            // Prüfen, ob der eingehende Stand älter ist als der bereits gespeicherte State
            if ($currentState && !empty($currentState['car_captured_at'])) {
                if (strtotime($carCapturedAt) < strtotime($currentState['car_captured_at'])) {
                    $this->logger->info("TelemetryRepository: saveState übersprungen, empfangener Stand ($carCapturedAt) ist älter als vorhandener Stand ({$currentState['car_captured_at']}).");
                    return true;
                }
            }

            // Mergen: Eingehende Werte verwenden; falls null, bestehende Werte beibehalten (Partial Updates)
            $finalSoc = $socPercent ?? ($currentState ? (int)$currentState['soc_percent'] : 0);
            $finalTargetSoc = $targetSoc ?? ($currentState ? (int)$currentState['target_soc'] : 80);
            $finalChargeKw = $chargePowerKw ?? ($currentState ? (float)$currentState['charge_power_kw'] : 0.0);
            $finalTempMax = $batteryTempMax ?? ($currentState ? (float)$currentState['battery_temp_max'] : 0.0);
            $finalTempMin = $batteryTempMin ?? ($currentState ? (float)$currentState['battery_temp_min'] : 0.0);
            $finalChargingState = $chargingState ?? ($currentState ? $currentState['charging_state'] : 'unknown');
            $finalPlug = $plugConnected ?? ($currentState ? (int)$currentState['plug_connected'] : 0);
            $finalLocked = $isLocked ?? ($currentState ? (int)$currentState['is_locked'] : 1);
            $finalMileage = ($mileageKm !== null && $mileageKm > 0) ? $mileageKm : ($currentState ? (int)$currentState['mileage_km'] : 0);
            $finalOutdoorTemp = $outdoorTempC ?? ($currentState ? (float)$currentState['outdoor_temp_c'] : 0.0);
            $finalEstimatedFinish = $estimatedFinishAt ?? ($currentState ? $currentState['estimated_finish_at'] : null);
            $finalLat = $latitude ?? ($currentState ? ($currentState['latitude'] ?? null) : null);
            $finalLon = $longitude ?? ($currentState ? ($currentState['longitude'] ?? null) : null);

            $hasLoc = $this->hasLocationColumns('vehicle_state');
            $locColSql = $hasLoc ? ", `latitude`, `longitude`" : "";
            $locValSql = $hasLoc ? ", :latitude, :longitude" : "";
            $locUpdSql = $hasLoc ? ", `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`)" : "";

            $stmtState = $this->dbCon->prepare("
					INSERT INTO `vehicle_state` (
						`vin`, 
						`car_captured_at`, 
						`soc_percent`, 
						`target_soc`, 
						`charge_power_kw`, 
						`battery_temp_max`, 
						`battery_temp_min`, 
						`charging_state`, 
						`plug_connected`, 
						`is_locked`, 
						`mileage_km`, 
						`range_km`, 
						`outdoor_temp_c`{$locColSql}, 
						`estimated_finish_at`
					) VALUES (
						:vin, 
						:car_captured_at, 
						:soc_percent, 
						:target_soc, 
						:charge_power_kw, 
						:battery_temp_max, 
						:battery_temp_min, 
						:charging_state, 
						:plug_connected, 
						:is_locked, 
						:mileage_km, 
						:range_km, 
						:outdoor_temp_c{$locValSql}, 
						:estimated_finish_at
					) ON DUPLICATE KEY UPDATE
						`car_captured_at`     = VALUES(`car_captured_at`),
						`soc_percent`         = VALUES(`soc_percent`),
						`target_soc`          = VALUES(`target_soc`),
						`charge_power_kw`     = VALUES(`charge_power_kw`),
						`battery_temp_max`    = VALUES(`battery_temp_max`),
						`battery_temp_min`    = VALUES(`battery_temp_min`),
						`charging_state`      = VALUES(`charging_state`),
						`plug_connected`      = VALUES(`plug_connected`),
						`is_locked`           = VALUES(`is_locked`),
						`mileage_km`          = VALUES(`mileage_km`),
						`range_km`            = CASE WHEN VALUES(`range_km`) > 0 THEN VALUES(`range_km`) WHEN VALUES(`car_captured_at`) != `car_captured_at` THEN 0 ELSE `range_km` END,
						`outdoor_temp_c`      = VALUES(`outdoor_temp_c`){$locUpdSql},
						`estimated_finish_at` = VALUES(`estimated_finish_at`),
						`updated_at`          = CURRENT_TIMESTAMP
				");

            $params = [
                ':vin' => $vin,
                ':car_captured_at' => $carCapturedAt,
                ':soc_percent' => $finalSoc,
                ':target_soc' => $finalTargetSoc,
                ':charge_power_kw' => $finalChargeKw,
                ':battery_temp_max' => $finalTempMax,
                ':battery_temp_min' => $finalTempMin,
                ':charging_state' => $finalChargingState,
                ':plug_connected' => $finalPlug,
                ':is_locked' => $finalLocked,
                ':mileage_km' => $finalMileage,
                ':range_km' => $rangeKm,
                ':outdoor_temp_c' => $finalOutdoorTemp,
                ':estimated_finish_at' => $finalEstimatedFinish,
            ];
            if ($hasLoc) {
                $params[':latitude'] = $finalLat;
                $params[':longitude'] = $finalLon;
            }

            $stmtState->execute($params);

            return true;
        } catch (Exception $e) {
            $this->logger->error("TelemetryRepository: Fehler bei saveState.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Schreibt einen Log-Eintrag in vehicle_telemetry_log.
     */
    public function saveLog(array $data): bool
    {
        try {
            $vin = $data['vin'];
            $capturedAtObj = new DateTime($data['captured_at']);
            $carCapturedAt = $capturedAtObj->format('Y-m-d H:i:s');

            $stmtCurrent = $this->dbCon->prepare("
                SELECT mileage_km, outdoor_temp_c 
                FROM vehicle_state 
                WHERE vin = :vin
            ");
            $stmtCurrent->execute([':vin' => $vin]);
            $currentState = $stmtCurrent->fetch(PDO::FETCH_ASSOC) ?: [];

            $socPercent = (int)($data['battery']['soc'] ?? 0);
            $chargePowerKw = (float)($data['battery']['charge_power_kw'] ?? 0.0);

            $mileageKm = isset($data['status']['mileage_km'])
                ? (int)$data['status']['mileage_km']
                : (int)($currentState['mileage_km'] ?? 0);

            // Reale Reichweite (z. B. aus TRONITY) übernehmen, sonst vorhandener Wert aus dem State
            $incomingRange = isset($data['status']['range_km']) ? (int)$data['status']['range_km'] : 0;
            $rangeKm = $incomingRange > 0 ? $incomingRange : (int)($currentState['range_km'] ?? 0);

            $outdoorTempC = isset($data['status']['outdoor_temp_c'])
                ? (float)$data['status']['outdoor_temp_c']
                : (float)($currentState['outdoor_temp_c'] ?? 0.0);

            $latitude = isset($data['status']['latitude']) && is_numeric($data['status']['latitude']) ? (float)$data['status']['latitude'] : null;
            $longitude = isset($data['status']['longitude']) && is_numeric($data['status']['longitude']) ? (float)$data['status']['longitude'] : null;

            $rawPayload = json_encode($data);

            $hasLoc = $this->hasLocationColumns('vehicle_telemetry_log');
            $locColSql = $hasLoc ? ", `latitude`, `longitude`" : "";
            $locValSql = $hasLoc ? ", :latitude, :longitude" : "";
            $locUpdSql = $hasLoc ? ", `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`)" : "";

            $stmtLog = $this->dbCon->prepare("
                INSERT INTO `vehicle_telemetry_log` (
                    `vin`, 
                    `car_captured_at`, 
                    `soc_percent`, 
                    `charge_power_kw`, 
                    `range_km`, 
                    `mileage_km`, 
                    `outdoor_temp_c`{$locColSql}, 
                    `raw_payload`
                ) VALUES (
                    :vin, 
                    :car_captured_at, 
                    :soc_percent, 
                    :charge_power_kw, 
                    :range_km, 
                    :mileage_km, 
                    :outdoor_temp_c{$locValSql}, 
                    :raw_payload
                ) ON DUPLICATE KEY UPDATE
                    `soc_percent`     = VALUES(`soc_percent`),
                    `charge_power_kw` = VALUES(`charge_power_kw`),
                    `range_km`        = IF(VALUES(`range_km`) > 0, VALUES(`range_km`), `range_km`),
                    `mileage_km`      = IF(VALUES(`mileage_km`) > 0, VALUES(`mileage_km`), `mileage_km`),
                    `outdoor_temp_c`  = VALUES(`outdoor_temp_c`){$locUpdSql},
                    `raw_payload`     = VALUES(`raw_payload`)
            ");

            $params = [
                ':vin' => $vin,
                ':car_captured_at' => $carCapturedAt,
                ':soc_percent' => $socPercent,
                ':charge_power_kw' => $chargePowerKw,
                ':range_km' => $rangeKm,
                ':mileage_km' => $mileageKm,
                ':outdoor_temp_c' => $outdoorTempC,
                ':raw_payload' => $rawPayload
            ];
            if ($hasLoc) {
                $params[':latitude'] = $latitude;
                $params[':longitude'] = $longitude;
            }

            $stmtLog->execute($params);

            $affectedRows = $stmtLog->rowCount();
            if ($affectedRows === 1) {
                $capturedAt = date('d.m.Y H:i', strtotime($data['captured_at']));
                $activityLogger = new ActivityLogger($this->db);
                $activityLogger->logCarTelemetryLoaded("ID.Buzz Stand: $capturedAt Uhr");
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error("TelemetryRepository: Fehler bei saveLog.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function hasLocationColumns(string $table): bool
    {
        static $hasCol = [];
        if (isset($hasCol[$table])) {
            return $hasCol[$table];
        }
        try {
            $stmt = $this->dbCon->query("SHOW COLUMNS FROM `{$table}` LIKE 'latitude'");
            $hasCol[$table] = (bool)$stmt->fetch();
        } catch (\Throwable) {
            $hasCol[$table] = false;
        }
        return $hasCol[$table];
    }

    /**
     * Plausibilisiert und korrigiert ggf. empfangene Telemetriedaten anhand des rohen Payloads.
     * Schützt vor bekannten Artefakten des VW EU Data Act Portals:
     * - Ignoriert Keys 93b55324..., 7bddd5e7..., bd4b6d50... (Start-Ladestand bei Ladebeginn)
     * - Bevorzugt Key 506cb83e... (Kundenanzeige-SoC) vor BMS-Rohwerten
     * - Bevorzugt Key 96c211b4... (Live-Ladezustand) vor veralteten Cache-Werten
     * - Bevorzugt Key 44ed0d61... (tatsächliche Ladeleistung) vor 0.0 kW Cache
     * - Bevorzugt Key cad65f6f... (verbleibende Restladezeit)
     */
    public function sanitizePayload(array &$data): void
    {
        if (!isset($data['raw_payload']['Data']) || !is_array($data['raw_payload']['Data'])) {
            return;
        }

        $rawData = $data['raw_payload']['Data'];
        $currentSoc = isset($data['battery']['soc']) ? (int)$data['battery']['soc'] : null;

        $startChargeKeys = [
            '93b55324-6628-36df-8f76-8eba797fc59c',
            '7bddd5e7-43a4-3878-bd63-9502782f77a5',
            'bd4b6d50-b574-31e6-8141-8787ca5fec8c',
        ];

        $customerDisplayCandidates = [];
        $filteredHvCandidates = [];
        $rawBmsCandidates = [];
        $otherSocCandidates = [];
        $chargeStartSoc = null;

        $currentDt = null;
        $canonicalChargeState = null;
        $activeChargeStates = [];
        $canonicalChargePower = null;
        $positiveChargePowers = [];
        $canonicalRemSeconds = null;

        foreach ($rawData as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fn = $item['dataFieldName'] ?? '';
            $val = $item['value'] ?? null;
            $key = $item['key'] ?? '';

            if (in_array($fn, ['car_captured_time', 'timestamp'], true) && is_string($val)) {
                $time = strtotime($val);
                if ($time !== false) {
                    $currentDt = $time;
                }
            }

            if ($val === null || $val === '') {
                continue;
            }

            // Start-Ladestand bei Ladebeginn merken
            if (in_array($key, $startChargeKeys, true)) {
                $chargeStartSoc = (int)round((float)$val);
                continue;
            }

            // 1. Priorität: Offizieller Kundenanzeige-SoC (Display-Wert im Auto)
            if ($key === '506cb83e-f99f-3af3-bbeb-0429b69a78d9') {
                $num = (int)round((float)$val);
                if ($num >= 0 && $num <= 100) {
                    $customerDisplayCandidates[] = ['time' => $currentDt, 'val' => $num];
                }
            } elseif ($key === '162c2a75-edf4-3990-b8ed-7c600b3dbc40' || $fn === 'battery_level_HV.battery_level_HV.value') {
                // 2. Priorität: Gefilterter BMS-Wert
                $num = (int)round((float)$val);
                if ($num >= 0 && $num <= 100) {
                    $filteredHvCandidates[] = ['time' => $currentDt, 'val' => $num];
                }
            } elseif ($key === 'ac1108b1-b8cc-3db9-a663-03d387e42223' || $fn === 'battery_level_HV.value') {
                // 3. Priorität: Ungefilterter Roh-BMS-Wert
                $num = (int)round((float)$val);
                if ($num >= 0 && $num <= 100) {
                    $rawBmsCandidates[] = ['time' => $currentDt, 'val' => $num];
                }
            } elseif ($fn === 'battery_state_report.soc' || str_contains(strtolower($fn), 'soc')) {
                $num = (int)round((float)$val);
                if ($num >= 0 && $num <= 100) {
                    $otherSocCandidates[] = ['time' => $currentDt, 'val' => $num];
                }
            }

            // Ladezustand erfassen
            if ($key === '96c211b4-f8fb-3f40-b7cf-6a1cd12cce6d') {
                $canonicalChargeState = (string)$val;
            }
            if ($fn === 'charging_state_report.current_charge_state') {
                $valUpper = strtoupper((string)$val);
                if (str_contains($valUpper, 'CHARGING') && !str_contains($valUpper, 'NOT_READY')) {
                    $activeChargeStates[] = (string)$val;
                }
            }
            if ($fn === 'charging_state_report.charging_scenario') {
                $valUpper = strtoupper((string)$val);
                if (str_contains($valUpper, 'ACTIVE') && str_contains($valUpper, 'CHARGING')) {
                    $activeChargeStates[] = 'CHARGE_STATE_CHARGING_HV_BATTERY';
                }
            }

            // Ladeleistung erfassen
            if ($key === '44ed0d61-98c4-36df-b860-b077929a5797') {
                $p = (float)$val;
                if ($p > 0) {
                    $canonicalChargePower = round($p, 2);
                }
            }
            if ($fn === 'battery_state_report.charge_power') {
                $p = (float)$val;
                if ($p > 0) {
                    $positiveChargePowers[] = round($p, 2);
                }
            }

            // Restladezeit erfassen
            if ($key === 'cad65f6f-17c5-377b-b030-821ffaf27dd5') {
                $cleanSec = str_replace('s', '', (string)$val);
                $sec = (int)round((float)$cleanSec);
                if ($sec > 0) {
                    $canonicalRemSeconds = $sec;
                }
            }
        }

        $selectBest = function (array $candidates): ?int {
            if (empty($candidates)) {
                return null;
            }
            $withTime = array_filter($candidates, fn($c) => $c['time'] !== null);
            if (!empty($withTime)) {
                usort($withTime, fn($a, $b) => $a['time'] <=> $b['time']);
                return end($withTime)['val'];
            }
            return end($candidates)['val'];
        };

        $reliableSoc = $selectBest($customerDisplayCandidates)
            ?? $selectBest($filteredHvCandidates)
            ?? $selectBest($rawBmsCandidates)
            ?? $selectBest($otherSocCandidates);

        if ($reliableSoc !== null && $reliableSoc !== $currentSoc) {
            $reason = ($currentSoc === $chargeStartSoc)
                ? "Start-Ladestand ($currentSoc %) durch echten Live-SoC ($reliableSoc %) ersetzt"
                : "SoC von $currentSoc % auf verlässlichen Live-Wert ($reliableSoc %) korrigiert";

            $this->logger->info("TelemetryRepository: $reason.", [
                'vin' => $data['vin'] ?? 'unknown',
                'original_soc' => $currentSoc,
                'corrected_soc' => $reliableSoc,
                'charge_start_soc' => $chargeStartSoc,
            ]);

            $data['battery']['soc'] = $reliableSoc;
        }

        // Ladeleistung korrigieren, falls canonical oder aktive Leistung vorhanden
        $bestPower = $canonicalChargePower ?? (!empty($positiveChargePowers) ? max($positiveChargePowers) : null);
        if ($bestPower !== null && ($data['battery']['charge_power_kw'] ?? 0.0) <= 0.0) {
            $data['battery']['charge_power_kw'] = $bestPower;
        }

        // Ladezustand korrigieren, falls aktives Laden statt NOT_READY gemeldet wurde
        $currentCs = strtoupper((string)($data['status']['charging_state'] ?? ''));
        $bestCs = $canonicalChargeState ?? (!empty($activeChargeStates) ? end($activeChargeStates) : null);
        if ($bestCs && (empty($currentCs) || str_contains($currentCs, 'NOT_READY') || $currentCs === 'UNKNOWN')) {
            $data['status']['charging_state'] = $bestCs;
            $currentCs = strtoupper($bestCs);
        }

        // Plausibilisierung: Wer aktiv lädt oder Ladeleistung zieht, ist auch angesteckt
        if ((str_contains($currentCs, 'CHARGING') && !str_contains($currentCs, 'NOT_READY')) || ($data['battery']['charge_power_kw'] ?? 0) > 0) {
            $data['status']['plug_connected'] = true;
        }

        // Restladezeit / estimated_finish_at nachberechnen, falls leer
        if ($canonicalRemSeconds !== null && empty($data['battery']['estimated_finish_at']) && !empty($data['captured_at'])) {
            $capTime = strtotime($data['captured_at']);
            if ($capTime !== false) {
                $finishTime = $capTime + $canonicalRemSeconds;
                $data['battery']['estimated_finish_at'] = gmdate('Y-m-d H:i:s', $finishTime);
            }
        }
    }
}
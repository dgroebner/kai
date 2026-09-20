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

            // Reichweite wird bei automatischen Telemetrie-Updates nicht erfasst/geschätzt,
            // sondern ausschließlich manuell durch den Benutzer im Dashboard gepflegt.
            $rangeKm = 0;

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
						`outdoor_temp_c`, 
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
						:outdoor_temp_c, 
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
						`range_km`            = CASE WHEN VALUES(`car_captured_at`) != `car_captured_at` THEN 0 ELSE `range_km` END,
						`outdoor_temp_c`      = VALUES(`outdoor_temp_c`),
						`estimated_finish_at` = VALUES(`estimated_finish_at`),
						`updated_at`          = CURRENT_TIMESTAMP
				");

            // Exakt 14 Parameter für 14 eindeutige Slots im Prepared Statement
            $stmtState->execute([
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
            ]);

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

            // Reichweite wird bei automatischen Telemetrie-Updates nicht erfasst (wird manuell gepflegt)
            $rangeKm = 0;

            $outdoorTempC = isset($data['status']['outdoor_temp_c'])
                ? (float)$data['status']['outdoor_temp_c']
                : (float)($currentState['outdoor_temp_c'] ?? 0.0);

            $rawPayload = json_encode($data);

            $stmtLog = $this->dbCon->prepare("
                INSERT INTO `vehicle_telemetry_log` (
                    `vin`, 
                    `car_captured_at`, 
                    `soc_percent`, 
                    `charge_power_kw`, 
                    `range_km`, 
                    `mileage_km`, 
                    `outdoor_temp_c`, 
                    `raw_payload`
                ) VALUES (
                    :vin, 
                    :car_captured_at, 
                    :soc_percent, 
                    :charge_power_kw, 
                    :range_km, 
                    :mileage_km, 
                    :outdoor_temp_c, 
                    :raw_payload
                ) ON DUPLICATE KEY UPDATE
                    `soc_percent`     = VALUES(`soc_percent`),
                    `charge_power_kw` = VALUES(`charge_power_kw`),
                    `mileage_km`      = IF(VALUES(`mileage_km`) > 0, VALUES(`mileage_km`), `mileage_km`),
                    `outdoor_temp_c`  = VALUES(`outdoor_temp_c`),
                    `raw_payload`     = VALUES(`raw_payload`)
            ");

            $stmtLog->execute([
                ':vin' => $vin,
                ':car_captured_at' => $carCapturedAt,
                ':soc_percent' => $socPercent,
                ':charge_power_kw' => $chargePowerKw,
                ':range_km' => $rangeKm,
                ':mileage_km' => $mileageKm,
                ':outdoor_temp_c' => $outdoorTempC,
                ':raw_payload' => $rawPayload
            ]);

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

    /**
     * Plausibilisiert und korrigiert ggf. empfangene Telemetriedaten anhand des rohen Payloads.
     * Schützt vor bekannten Artefakten des VW EU Data Act Portals:
     * - Ignoriert Key 7bddd5e7... (Start-Ladestand bei Ladebeginn)
     * - Bevorzugt battery_level_HV.value (direkte BMS-Messung) bzw. Key 506cb83e... (aktueller Live-SoC)
     */
    public function sanitizePayload(array &$data): void
    {
        if (!isset($data['raw_payload']['Data']) || !is_array($data['raw_payload']['Data'])) {
            return;
        }

        $rawData = $data['raw_payload']['Data'];
        $currentSoc = isset($data['battery']['soc']) ? (int)$data['battery']['soc'] : null;

        $hvCandidates = [];
        $liveSocCandidates = [];
        $otherSocCandidates = [];
        $chargeStartSoc = null;

        $currentDt = null;
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
            if ($key === '7bddd5e7-43a4-3878-bd63-9502782f77a5') {
                $chargeStartSoc = (int)round((float)$val);
                continue;
            }

            // 1. Priorität: battery_level_HV.value (BMS-Messwert)
            if ($fn === 'battery_level_HV.value' || $key === 'ac1108b1-b8cc-3db9-a663-03d387e42223') {
                $num = (int)round((float)$val);
                if ($num >= 0 && $num <= 100) {
                    $hvCandidates[] = ['time' => $currentDt, 'val' => $num];
                }
            } elseif ($fn === 'battery_state_report.soc') {
                $num = (int)round((float)$val);
                if ($num >= 0 && $num <= 100) {
                    if ($key === '506cb83e-f99f-3af3-bbeb-0429b69a78d9') {
                        $liveSocCandidates[] = ['time' => $currentDt, 'val' => $num];
                    } else {
                        $otherSocCandidates[] = ['time' => $currentDt, 'val' => $num];
                    }
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

        $reliableSoc = $selectBest($hvCandidates) 
            ?? $selectBest($liveSocCandidates) 
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

        // Plausibilisierung: Wer aktiv lädt, ist auch angesteckt
        if (!empty($data['status']['charging_state'])) {
            $cs = strtoupper((string)$data['status']['charging_state']);
            if (str_contains($cs, 'CHARGING') && !str_contains($cs, 'NOT_READY')) {
                $data['status']['plug_connected'] = true;
            }
        }
    }
}
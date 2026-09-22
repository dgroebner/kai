<?php

namespace Kai\Tools\Car\Tronity;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\System\SystemSettingsService;
use PDO;

class HomeChargeAnalyzer
{
    private PDO $db;
    private SystemSettingsService $settings;

    public function __construct(?SystemSettingsService $settings = null)
    {
        $this->db = Database::getInstance()->getConnection();
        $this->settings = $settings ?? new SystemSettingsService();
    }

    /**
     * Analyzes a home charging session and calculates PV vs Grid share and costs.
     */
    public function analyze(string $startTimeUtc, string $endTimeUtc, float $chargedNetKwh): array
    {
        // 1. Hole alle 5-Minuten Telemetrie-Einträge für den Ladezeitraum
        // Da die Werte in pv_telemetry Leistung in W sind, rechnen wir sie in Energie (kWh) um.
        $stmt = $this->db->prepare("
            SELECT 
                last_update,
                house_load_w,
                grid_total_w,
                pv_power_w,
                battery_power_w
            FROM pv_telemetry 
            WHERE last_update >= :start AND last_update <= :end
            ORDER BY last_update ASC
        ");
        $stmt->execute([':start' => $startTimeUtc, ':end' => $endTimeUtc]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            // Fallback, falls keine Telemetriedaten vorhanden sind
            return $this->fallbackAnalysis($chargedNetKwh);
        }

        $totalHouseKwh = 0.0;
        $totalGridImportKwh = 0.0;
        
        $previousTime = null;

        foreach ($rows as $row) {
            $currentTime = strtotime($row['last_update']);
            if ($previousTime !== null) {
                // Zeitdifferenz in Stunden
                $hours = ($currentTime - $previousTime) / 3600.0;
                
                // Wir begrenzen auf max 15 Minuten, falls Lücken in der Telemetrie sind
                if ($hours > 0 && $hours <= 0.25) {
                    $houseLoadW = max(0, (float)$row['house_load_w']);
                    $gridTotalW = (float)$row['grid_total_w']; // Positiv = Netzbezug, Negativ = Einspeisung

                    $totalHouseKwh += ($houseLoadW * $hours) / 1000.0;
                    if ($gridTotalW > 0) {
                        $totalGridImportKwh += ($gridTotalW * $hours) / 1000.0;
                    }
                }
            }
            $previousTime = $currentTime;
        }

        // Wir schätzen den echten Verbrauch inkl. Ladeverluste (ca. 10-12% Ladeverlust)
        // home_meter_kwh repräsentiert die Energie, die an der Wallbox "gezogen" wurde.
        $estimatedLossPct = 10.0;
        $homeMeterKwh = $chargedNetKwh / (1 - ($estimatedLossPct / 100));
        
        // Falls in dem Zeitraum aus irgendeinem Grund weniger Hausverbrauch gemessen wurde als geladen wurde,
        // (z.B. Lücken in den Daten), nehmen wir homeMeterKwh als Minimum für Hausverbrauch.
        if ($totalHouseKwh < $homeMeterKwh) {
            $totalHouseKwh = $homeMeterKwh;
        }

        // Verhältnis von Netzbezug am gesamten Hausverbrauch im Ladezeitraum
        $gridRatio = $totalHouseKwh > 0 ? min(1.0, $totalGridImportKwh / $totalHouseKwh) : 1.0;
        
        $carGridKwh = $homeMeterKwh * $gridRatio;
        $carPvKwh = $homeMeterKwh - $carGridKwh; // PV + Batterie

        // Kosten berechnen
        $gridPrice = $this->settings->getGridImportPrice(); // z.B. 0.2689
        $pvPrice = $this->settings->getGridExportPrice(); // Opportunitätskosten, z.B. 0.06

        $costEur = ($carGridKwh * $gridPrice) + ($carPvKwh * $pvPrice);

        return [
            'home_meter_kwh' => round($homeMeterKwh, 2),
            'home_pv_kwh' => round($carPvKwh, 2),
            'home_grid_kwh' => round($carGridKwh, 2),
            'loss_kwh' => round($homeMeterKwh - $chargedNetKwh, 2),
            'loss_pct' => $estimatedLossPct,
            'cost_eur' => round($costEur, 2)
        ];
    }

    private function fallbackAnalysis(float $chargedNetKwh): array
    {
        $estimatedLossPct = 10.0;
        $homeMeterKwh = $chargedNetKwh / (1 - ($estimatedLossPct / 100));
        $gridPrice = $this->settings->getGridImportPrice();
        
        return [
            'home_meter_kwh' => round($homeMeterKwh, 2),
            'home_pv_kwh' => 0.0,
            'home_grid_kwh' => round($homeMeterKwh, 2),
            'loss_kwh' => round($homeMeterKwh - $chargedNetKwh, 2),
            'loss_pct' => $estimatedLossPct,
            'cost_eur' => round($homeMeterKwh * $gridPrice, 2)
        ];
    }
}

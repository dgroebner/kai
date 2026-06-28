<?php
namespace Kai\Tools\PVCharge;

use Kai\Tools\Shared\Log\Logger;
use DateTime;
use Exception;

class ChargeDataCollector {
    private Logger $logger;

    public function __construct() {
        $this->logger = new Logger();
    }

    /**
     * Retrieves the predicted solar yield (kWh) and weather summary for the next 4 days.
     * In a production environment, this would call a weather API (e.g., OpenWeatherMap, Solcast).
     *
     * @return array Array of forecast data grouped by date
     */
    public function getWeatherAndSolarForecast(): array {
        $this->logger->info("ChargeDataCollector: Sammle PV- und Wetterprognose für die nächsten 4 Tage.");
        
        $forecast = [];
        $currentDate = new DateTime();
        
        // Mock data generator for the next 4 days
        $mockForecasts = [
            1 => ['kwh' => 18.5, 'summary' => 'Sonnig, kaum Wolken'],
            2 => ['kwh' => 12.2, 'summary' => 'Wechselnd bewölkt'],
            3 => ['kwh' => 5.4,  'summary' => 'Stark bewölkt, Regen'],
            4 => ['kwh' => 15.8, 'summary' => 'Leicht bewölkt, sonnige Abschnitte'],
        ];

        for ($i = 0; $i < 4; $i++) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayIndex = ($i % 4) + 1;
            
            $forecast[$dateStr] = [
                'date' => $dateStr,
                'predicted_pv_yield_kwh' => $mockForecasts[$dayIndex]['kwh'],
                'weather_summary' => $mockForecasts[$dayIndex]['summary']
            ];
            
            $currentDate->modify('+1 day');
        }

        return $forecast;
    }

    /**
     * Checks Google Calendar events for the next 7 days to identify planned long-distance trips.
     * In a production environment, this would call the Google Calendar API.
     *
     * @return array List of calendar events matching long-distance patterns
     */
    public function getGoogleCalendarEvents(): array {
        $this->logger->info("ChargeDataCollector: Prüfe Google Calendar auf Langstrecken-Fahrten in den nächsten 7 Tagen.");
        
        $events = [];
        $currentDate = new DateTime();
        
        // Let's mock a long-distance trip on day 3
        $tripDate = clone $currentDate;
        $tripDate->modify('+3 days');
        
        $events[] = [
            'summary' => 'Fahrt nach Hamburg (Familienbesuch)',
            'start_time' => $tripDate->format('Y-m-d') . ' 08:00:00',
            'end_time' => $tripDate->format('Y-m-d') . ' 18:00:00',
            'estimated_distance_km' => 280, // Trip to Hamburg, requires charging
            'notes' => 'Langstrecke, ID.Buzz voll laden erforderlich.'
        ];

        // Another simple event that is local and doesn't require extra charging (just for demonstration)
        $localEventDate = clone $currentDate;
        $localEventDate->modify('+1 day');
        $events[] = [
            'summary' => 'Einkauf im Nachbarort',
            'start_time' => $localEventDate->format('Y-m-d') . ' 14:00:00',
            'end_time' => $localEventDate->format('Y-m-d') . ' 15:30:00',
            'estimated_distance_km' => 15,
            'notes' => 'Nahverkehr'
        ];

        return $events;
    }

    /**
     * Calculates the remaining charging demand for the current week.
     * The VW ID.Buzz has an 86 kWh net battery capacity.
     * Base weekly demand is 15 kWh (approx. 70 km city driving).
     *
     * @param float $weeklyBaseDemandKwh The standard target demand (defaults to 15 kWh)
     * @return array Structured array of charging status and remaining demand
     */
    public function getCarChargingDemand(float $weeklyBaseDemandKwh = 15.0): array {
        $this->logger->info("ChargeDataCollector: Berechne verbleibenden Ladebedarf des Fahrzeugs.");

        $batteryCapacity = 86.0; // 86 kWh Netto
        
        // Mock current State of Charge (SoC) and weekly charging status
        $currentSocPercent = 40.0; // 40% battery remaining
        $alreadyChargedThisWeekKwh = 5.0; // 5 kWh already charged this week

        $currentEnergyKwh = ($currentSocPercent / 100.0) * $batteryCapacity;
        
        // Remaining demand for the current week
        $remainingWeeklyBaseDemandKwh = max(0.0, $weeklyBaseDemandKwh - $alreadyChargedThisWeekKwh);

        return [
            'vehicle' => 'VW ID.Buzz',
            'battery_capacity_kwh' => $batteryCapacity,
            'current_soc_percent' => $currentSocPercent,
            'current_energy_kwh' => $currentEnergyKwh,
            'weekly_base_demand_kwh' => $weeklyBaseDemandKwh,
            'already_charged_this_week_kwh' => $alreadyChargedThisWeekKwh,
            'remaining_weekly_base_demand_kwh' => $remainingWeeklyBaseDemandKwh
        ];
    }
}

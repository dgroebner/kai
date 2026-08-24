<?php

namespace Kai\Tools\PVCharge;

use Kai\Tools\System\SystemSettingsService;

/**
 * Berechnet die Kennzahlen des Energie-Dashboards aus Live-, Telemetrie- und
 * Preisdaten. Wird sowohl vom initialen Seitenaufbau als auch vom AJAX-Refresh genutzt.
 */
class PvDashboardService
{
    /**
     * Messintervall-Faktor: Die Telemetrie liefert alle 5 Minuten einen Watt-Wert,
     * die Summe von Watt-Messpunkten wird deshalb durch 12 (Messungen pro Stunde)
     * und 1000 (Wh -> kWh) geteilt.
     */
    private const WATT_SUM_TO_KWH_DIVISOR = 12000;

    private PvTelemetryRepository $telemetryRepository;
    private SystemSettingsService $settingsService;

    public function __construct(
        ?PvTelemetryRepository $telemetryRepository = null,
        ?SystemSettingsService $settingsService = null
    ) {
        $this->telemetryRepository = $telemetryRepository ?? new PvTelemetryRepository();
        $this->settingsService = $settingsService ?? new SystemSettingsService();
    }

    /**
     * Liefert den aktuellen Live-Datensatz zusammen mit den daraus abgeleiteten
     * Tages-Kennzahlen (Ertrag, Peak, Netzbezug/-einspeisung inkl. Kosten und Erlösen).
     *
     * @return array{live: array, kpis: array<string, float|int>}
     */
    public function getDashboardData(): array
    {
        $liveData = $this->telemetryRepository->getLatestLiveData();
        $gridTotals = $this->telemetryRepository->getTodayGridTotals();

        $importPrice = $this->settingsService->getGridImportPrice();
        $exportPrice = $this->settingsService->getGridExportPrice();

        $gridImportKwh = $gridTotals['sum_import_w'] / self::WATT_SUM_TO_KWH_DIVISOR;
        $gridExportKwh = $gridTotals['sum_export_w'] / self::WATT_SUM_TO_KWH_DIVISOR;
        $yieldDailyKwh = (float)($liveData['yield_daily_kwh'] ?? 0);

        return [
            'live' => $liveData,
            'kpis' => [
                'yieldDailyKwh'     => $yieldDailyKwh,
                'yieldRevenue'      => $yieldDailyKwh * $importPrice,
                'todayPeakW'        => $this->telemetryRepository->getTodayPeakPowerW(),
                'gridImportKwh'     => $gridImportKwh,
                'gridImportCost'    => $gridImportKwh * $importPrice,
                'gridExportKwh'     => $gridExportKwh,
                'gridExportRevenue' => $gridExportKwh * $exportPrice,
            ],
        ];
    }
}

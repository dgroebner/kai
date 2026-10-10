<?php

namespace Kai\Tools\Car;

use DateTime;
use Kai\Tools\PVCharge\PvForecastRepository;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\System\SystemSettingsService;
use Throwable;

/**
 * Zentraler Orchestrierungs-Service für den gesamten Lebenszyklus einer Reise:
 * Von der automatischen Erkennung (ICS) über Vorbereitung (PV-Vorlauf & Vorabend-Netzladung),
 * Reisedurchführung (ABRP Deep Links) bis zum Kostenabgleich.
 */
class TripPlanningService
{
    private TripRepository $tripRepo;
    private RoutePlannerInterface $routePlanner;
    private PvForecastRepository $pvForecastRepo;
    private SystemSettingsService $settingsService;
    private GeocodingService $geocodingService;
    private TripExpenseService $expenseService;
    private IcsTripParser $icsParser;
    private IcsReplyService $icsReplyService;
    private ?\Kai\Tools\Shared\Log\ActivityLogger $activityLogger;
    private Logger $logger;

    public function __construct(
        ?TripRepository $tripRepo = null,
        ?RoutePlannerInterface $routePlanner = null,
        ?PvForecastRepository $pvForecastRepo = null,
        ?SystemSettingsService $settingsService = null,
        ?GeocodingService $geocodingService = null,
        ?TripExpenseService $expenseService = null,
        ?IcsTripParser $icsParser = null,
        ?IcsReplyService $icsReplyService = null,
        ?\Kai\Tools\Shared\Log\ActivityLogger $activityLogger = null,
        ?Logger $logger = null
    ) {
        $this->tripRepo = $tripRepo ?? new TripRepository();
        $this->logger = $logger ?? new Logger(14);
        $this->settingsService = $settingsService ?? new SystemSettingsService();
        $this->pvForecastRepo = $pvForecastRepo ?? new PvForecastRepository();
        $this->geocodingService = $geocodingService ?? new GeocodingService($this->settingsService, logger: $this->logger);
        $this->expenseService = $expenseService ?? new TripExpenseService(tripRepo: $this->tripRepo, logger: $this->logger);
        $this->icsParser = $icsParser ?? new IcsTripParser();
        $this->icsReplyService = $icsReplyService ?? new IcsReplyService(logger: $this->logger);
        $this->activityLogger = $activityLogger ?? (class_exists(Database::class) ? new \Kai\Tools\Shared\Log\ActivityLogger(Database::getInstance(), $this->logger) : null);

        if ($routePlanner !== null) {
            $this->routePlanner = $routePlanner;
        } else {
            $providerType = strtoupper((string)($_ENV['TRIP_ROUTING_PROVIDER'] ?? 'ORS_HEURISTIC'));
            if ($providerType === 'ABRP' || $providerType === 'ABRP_V2') {
                $this->routePlanner = new AbrpPlanner(logger: $this->logger);
            } else {
                $this->routePlanner = new OrsHeuristicPlanner(logger: $this->logger);
            }
        }
    }

    /**
     * Plant eine neue Reise vollumfänglich und speichert sie inklusive Ladeschritten in der Datenbank.
     *
     * @param array<string, mixed> $input
     * @return int Generierte Trip-ID
     */
    public function planAndSaveTrip(array $input): int
    {
        $title = trim((string)($input['title'] ?? 'Neue Reise'));
        $departureTime = (string)($input['departure_time'] ?? date('Y-m-d 08:00:00'));
        $returnTime = !empty($input['return_time']) ? (string)$input['return_time'] : null;
        $isRoundTrip = !empty($input['is_round_trip']);
        $calendarUid = !empty($input['calendar_uid']) ? (string)$input['calendar_uid'] : null;
        $parentTripId = !empty($input['parent_trip_id']) ? (int)$input['parent_trip_id'] : null;

        $targetArrivalSoc = isset($input['target_arrival_soc']) ? (int)$input['target_arrival_soc'] : 10;
        $plannedDepartureSoc = isset($input['planned_departure_soc']) && (int)$input['planned_departure_soc'] > 0
            ? (int)$input['planned_departure_soc']
            : null;

        $destAddress = trim((string)($input['destination_address'] ?? ''));
        $destLat = (float)($input['destination_lat'] ?? 0.0);
        $destLon = (float)($input['destination_lon'] ?? 0.0);

        $startAddress = trim((string)($input['start_address'] ?? ''));
        $startLat = (float)($input['start_lat'] ?? 0.0);
        $startLon = (float)($input['start_lon'] ?? 0.0);

        // 1. Automatische Erkennung verschachtelter Ausflüge (Trip-Nesting)
        if ($parentTripId === null && $returnTime !== null) {
            $parentTrip = $this->tripRepo->findOverlappingParentTrip($departureTime, $returnTime);
            if ($parentTrip) {
                $parentTripId = (int)$parentTrip['id'];
                $this->logger->info("TripPlanningService: Reise '{$title}' als Ausflug unter '{$parentTrip['title']}' erkannt.");

                // Startort des Ausflugs ist die Unterkunftsadresse der Hauptreise
                if ($startAddress === '' || strtolower($startAddress) === 'zuhause') {
                    $startAddress = $parentTrip['destination_address'];
                    $startLat = (float)$parentTrip['destination_lat'];
                    $startLon = (float)$parentTrip['destination_lon'];
                }
            }
        }

        // 2. Geocodierung für Zielort, falls keine Koordinaten übergeben wurden
        if (($destLat === 0.0 || $destLon === 0.0) && $destAddress !== '') {
            $geoDest = $this->geocodingService->geocode($destAddress);
            if ($geoDest) {
                $destLat = $geoDest['lat'];
                $destLon = $geoDest['lon'];
                if ($destAddress === '') {
                    $destAddress = $geoDest['display_name'];
                }
            }
        }

        // 3. Geocodierung für Startort (Standard: Heimatadresse aus Systemeinstellungen)
        if ($startLat === 0.0 || $startLon === 0.0) {
            if ($startAddress !== '' && strtolower($startAddress) !== 'zuhause') {
                $geoStart = $this->geocodingService->geocode($startAddress);
                if ($geoStart) {
                    $startLat = $geoStart['lat'];
                    $startLon = $geoStart['lon'];
                }
            } else {
                $startAddress = 'Zuhause';
                $startLat = $this->settingsService->getHomeLatitude();
                $startLon = $this->settingsService->getHomeLongitude();
            }
        }

        // 4. Automatische Kurztrip-Klassifizierung (Fachkonzept: <= 48h = Rundreise)
        if (!$isRoundTrip && $parentTripId === null && $returnTime !== null) {
            $depTs = strtotime($departureTime);
            $retTs = strtotime($returnTime);
            if ($depTs && $retTs && ($retTs - $depTs) <= (48 * 3600)) {
                $isRoundTrip = true;
                $this->logger->info("TripPlanningService: Reise '{$title}' <= 48h automatisch als Rundreise klassifiziert.");
            }
        }

        // 5. Routen- & Ladebedarfsberechnung über aktiven Provider
        $routeResult = $this->routePlanner->planRoute(
            $startLat,
            $startLon,
            $destLat,
            $destLon,
            $targetArrivalSoc,
            $plannedDepartureSoc ?? 0
        );

        $totalDistanceKm = $routeResult->totalDistanceKm;
        $estimatedConsumptionKwh = $routeResult->estimatedConsumptionKwh;
        $enRouteChargeKwh = $routeResult->enRouteChargeKwh;
        $recommendedSoc = $routeResult->recommendedDepartureSoc;

        // Bei Rundreise verdoppeln sich Distanz und Verbrauch (Heim -> Ziel -> Heim)
        if ($isRoundTrip) {
            $totalDistanceKm = round($totalDistanceKm * 2.0, 2);
            $estimatedConsumptionKwh = round($estimatedConsumptionKwh * 2.0, 2);
        }

        // Wenn kein Start-SoC fest vorgegeben wurde:
        // 80% als akkuschonender Standard. Reicht 80% (minus Ziel-SoC) für den Gesamttrip?
        // Wenn 80% nicht reichen, auf 100% erhöhen.
        if ($plannedDepartureSoc === null) {
            $usableWith80 = OrsHeuristicPlanner::BATTERY_CAPACITY_KWH * ((80 - $targetArrivalSoc) / 100.0);
            $effectiveDepartureSoc = ($estimatedConsumptionKwh <= $usableWith80) ? 80 : 100;
        } else {
            $effectiveDepartureSoc = $plannedDepartureSoc;
        }

        // Ladebedarf unterwegs anhand des tatsächlichen Start-SoC berechnen
        $usableBattery = OrsHeuristicPlanner::BATTERY_CAPACITY_KWH * (($effectiveDepartureSoc - $targetArrivalSoc) / 100.0);
        if ($estimatedConsumptionKwh > $usableBattery) {
            $enRouteChargeKwh = round(($estimatedConsumptionKwh - $usableBattery) + 4.0, 1);
        } else {
            $enRouteChargeKwh = 0.0;
        }

        $minDepartureSoc = TripRepository::calculateMinDepartureSoc($estimatedConsumptionKwh, $targetArrivalSoc);

        // 6. Vorlade-Kette & Ladeschritte generieren
        $chargingSteps = $this->generateChargingSchedule(
            $departureTime,
            $effectiveDepartureSoc,
            $enRouteChargeKwh,
            $parentTripId !== null,
            $minDepartureSoc
        );

        // Berechnete kalkulatorische Vorabend-Netzstromkosten ermitteln
        $homeChargeCost = 0.0;
        foreach ($chargingSteps as $step) {
            if ($step['step_type'] === 'evening_grid') {
                $gridPrice = $this->settingsService->getGridImportPrice();
                $homeChargeCost = round($step['planned_kwh'] * $gridPrice, 2);
                break;
            }
        }

        // 7. In Datenbank speichern
        $tripData = [
            'parent_trip_id' => $parentTripId,
            'calendar_uid' => $calendarUid,
            'title' => $title,
            'start_address' => $startAddress,
            'start_lat' => $startLat,
            'start_lon' => $startLon,
            'destination_address' => $destAddress,
            'destination_lat' => $destLat,
            'destination_lon' => $destLon,
            'departure_time' => $departureTime,
            'return_time' => $returnTime,
            'is_round_trip' => $isRoundTrip ? 1 : 0,
            'target_arrival_soc' => $targetArrivalSoc,
            'planned_departure_soc' => $effectiveDepartureSoc,
            'total_distance_km' => $totalDistanceKm,
            'estimated_consumption_kwh' => $estimatedConsumptionKwh,
            'en_route_charge_kwh' => $enRouteChargeKwh,
            'routing_provider' => $routeResult->providerName,
            'abrp_deep_link' => $routeResult->deepLink,
            'status' => 'geplant',
            'home_charge_cost' => $homeChargeCost,
            'en_route_charge_cost' => 0.0,
            'additional_cost' => 0.0,
        ];

        $tripId = $this->tripRepo->createTrip($tripData);
        $this->tripRepo->saveChargingSteps($tripId, $chargingSteps);

        $this->logger->info("TripPlanningService: Reise #{$tripId} '{$title}' erfolgreich geplant ({$totalDistanceKm} km, {$estimatedConsumptionKwh} kWh).");

        return $tripId;
    }

    /**
     * Berechnet eine bestehende Reise anhand ihrer aktuellen Attribute komplett neu.
     */
    public function recalculateTrip(int $tripId, bool $preservePlannedSoc = false): bool
    {
        $trip = $this->tripRepo->getTrip($tripId);
        if (!$trip) {
            return false;
        }

        $destLat = (float)$trip['destination_lat'];
        $destLon = (float)$trip['destination_lon'];
        $startLat = (float)$trip['start_lat'];
        $startLon = (float)$trip['start_lon'];

        // Koordinaten anhand der Adressen frisch auflösen, um fehlerhafte oder unpräzise Alt-Geocodes zu korrigieren
        if (!empty($trip['destination_address'])) {
            $destGeo = $this->geocodingService->geocode($trip['destination_address']);
            if ($destGeo !== null) {
                $this->logger->info("TripPlanningService: Zielort für Reise #{$tripId} neu aufgelöst: {$destGeo['lat']}, {$destGeo['lon']} ('{$destGeo['display_name']}')");
                $destLat = $destGeo['lat'];
                $destLon = $destGeo['lon'];
            } else {
                $this->logger->warn("TripPlanningService: Zielort '{$trip['destination_address']}' konnte für Reise #{$tripId} nicht geocodiert werden.");
            }
        }
        if (!empty($trip['start_address'])) {
            $startGeo = $this->geocodingService->geocode($trip['start_address']);
            if ($startGeo !== null) {
                $startLat = $startGeo['lat'];
                $startLon = $startGeo['lon'];
            }
        }

        $currentPlannedSoc = (int)($trip['planned_departure_soc'] ?? 80);
        $targetArrivalSoc = (int)($trip['target_arrival_soc'] ?? 10);

        $routeResult = $this->routePlanner->planRoute(
            $startLat,
            $startLon,
            $destLat,
            $destLon,
            $targetArrivalSoc,
            $currentPlannedSoc
        );

        $totalDistanceKm = $routeResult->totalDistanceKm;
        $estimatedConsumptionKwh = $routeResult->estimatedConsumptionKwh;

        if (!empty($trip['is_round_trip'])) {
            $totalDistanceKm = round($totalDistanceKm * 2.0, 2);
            $estimatedConsumptionKwh = round($estimatedConsumptionKwh * 2.0, 2);
        }

        // Wenn nicht explizit erzwungen: prüfen, ob 80% (Akkuschonung) für die Reise ausreichen
        $plannedDepartureSoc = $currentPlannedSoc;
        if (!$preservePlannedSoc) {
            $usableWith80 = OrsHeuristicPlanner::BATTERY_CAPACITY_KWH * ((80 - $targetArrivalSoc) / 100.0);
            if ($estimatedConsumptionKwh <= $usableWith80) {
                $plannedDepartureSoc = 80;
            } elseif ($plannedDepartureSoc < 100) {
                $plannedDepartureSoc = 100;
            }
        }

        $usableBattery = OrsHeuristicPlanner::BATTERY_CAPACITY_KWH * (($plannedDepartureSoc - $targetArrivalSoc) / 100.0);
        $enRouteChargeKwh = ($estimatedConsumptionKwh > $usableBattery)
            ? round(($estimatedConsumptionKwh - $usableBattery) + 4.0, 1)
            : 0.0;

        $minDepartureSoc = TripRepository::calculateMinDepartureSoc($estimatedConsumptionKwh, $targetArrivalSoc);

        $chargingSteps = $this->generateChargingSchedule(
            $trip['departure_time'],
            $plannedDepartureSoc,
            $enRouteChargeKwh,
            !empty($trip['parent_trip_id']),
            $minDepartureSoc
        );

        $homeChargeCost = 0.0;
        foreach ($chargingSteps as $step) {
            if ($step['step_type'] === 'evening_grid') {
                $gridPrice = $this->settingsService->getGridImportPrice();
                $homeChargeCost = round($step['planned_kwh'] * $gridPrice, 2);
                break;
            }
        }

        $this->tripRepo->updateTrip($tripId, [
            'start_lat' => $startLat,
            'start_lon' => $startLon,
            'destination_lat' => $destLat,
            'destination_lon' => $destLon,
            'planned_departure_soc' => $plannedDepartureSoc,
            'total_distance_km' => $totalDistanceKm,
            'estimated_consumption_kwh' => $estimatedConsumptionKwh,
            'en_route_charge_kwh' => $enRouteChargeKwh,
            'routing_provider' => $routeResult->providerName,
            'abrp_deep_link' => $routeResult->deepLink,
            'home_charge_cost' => $homeChargeCost,
        ]);

        $this->tripRepo->saveChargingSteps($tripId, $chargingSteps);
        $this->expenseService->recalculateTripCosts($tripId);

        return true;
    }

    /**
     * Generiert die Vorladekette (PV-Vorlaufschritte, Vorabend-Netzladung, Unterwegs-Schnellladen).
     *
     * @return array<int, array{
     *     step_type: string,
     *     scheduled_date: string,
     *     target_soc: int,
     *     planned_kwh: float,
     *     status: string
     * }>
     */
    private function generateChargingSchedule(
        string $departureTime,
        int $targetDepartureSoc,
        float $enRouteChargeKwh,
        bool $isNestedSubtrip,
        int $minDepartureSoc = 80
    ): array {
        $steps = [];
        $depDate = substr($departureTime, 0, 10);

        // Bei verschachtelten Ausflügen im Urlaub keine PV-Heimvorladung möglich
        if (!$isNestedSubtrip) {
            // A. PV-Vorlauf (bis zu 3 Tage vor Abreise prüfen)
            for ($daysBack = 3; $daysBack >= 1; $daysBack--) {
                $checkDate = date('Y-m-d', strtotime("{$depDate} -{$daysBack} day"));
                if ($checkDate < date('Y-m-d')) {
                    continue; // Vorbei liegende Tage überspringen
                }

                $forecastWh = $this->pvForecastRepo->getForecastForDate($checkDate);
                $forecastKwh = ($forecastWh !== null) ? round($forecastWh / 1000.0, 1) : 0.0;

                // Hoher prognostizierter Solarertrag (>= 14 kWh): +10% SoC Überschussladung einplanen
                if ($forecastKwh >= 14.0) {
                    $steps[] = [
                        'step_type' => 'pv_precharge',
                        'scheduled_date' => $checkDate,
                        'target_soc' => min($targetDepartureSoc, min(90, 70 + (4 - $daysBack) * 10)),
                        'planned_kwh' => 7.7, // ca. 10% der 77 kWh Batterie
                        'status' => 'geplant',
                    ];
                }
            }

            // B. Vorabend-Ladung (Netzstrom an der Wallbox auf geplanten Abfahrts-SoC)
            // Nur einplanen, wenn der aktuelle Akkustand unter dem Mindest-SoC für die Reise liegt!
            $eveningDate = date('Y-m-d', strtotime("{$depDate} -1 day"));
            if ($eveningDate >= date('Y-m-d')) {
                // Aktuellen Ist-SoC aus vehicle_state lesen
                $currentSoc = $this->getCurrentVehicleSoc();
                if ($currentSoc < $minDepartureSoc && $targetDepartureSoc > $currentSoc) {
                    $socDelta = max(10, $targetDepartureSoc - $currentSoc);
                    $neededKwh = round(OrsHeuristicPlanner::BATTERY_CAPACITY_KWH * ($socDelta / 100.0), 1);

                    $steps[] = [
                        'step_type' => 'evening_grid',
                        'scheduled_date' => $eveningDate,
                        'target_soc' => $targetDepartureSoc,
                        'planned_kwh' => $neededKwh,
                        'status' => 'geplant',
                    ];
                }
            }
        }

        // C. Unterwegs-Schnellladen (en_route_fast)
        if ($enRouteChargeKwh > 0.0) {
            $steps[] = [
                'step_type' => 'en_route_fast',
                'scheduled_date' => $depDate,
                'target_soc' => 80,
                'planned_kwh' => $enRouteChargeKwh,
                'status' => 'geplant',
            ];
        }

        return $steps;
    }

    /**
     * Ermittelt den aktuellen SoC des Fahrzeugs aus vehicle_state.
     */
    private function getCurrentVehicleSoc(): int
    {
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->query("SELECT soc_percent FROM vehicle_state LIMIT 1");
            $soc = $stmt->fetchColumn();
            if ($soc !== false && $soc !== null) {
                return (int)$soc;
            }
        } catch (Throwable) {
            // Ignorieren
        }

        return 70; // Konservativer Standardwert
    }

    /**
     * Verarbeitet eingehende iCalendar-Einladungen (.ics) aus dem MailDispatcher.
     *
     * @param string $icsContent
     * @param string $senderEmail
     * @param ?string $recipientEmail
     * @param ?string $emailBody Optionaler HTML- oder Plaintext-Body der Einladungs-Mail
     * @return int Anzahl importierter / aktualisierter Reisen
     */
    public function processIcsInvite(string $icsContent, string $senderEmail, ?string $recipientEmail = null, ?string $emailBody = null): int
    {
        // 1. Absender-Prüfung gegen erlaubte Systemeinstellungen / Allowlist
        if (!$this->isSenderAllowed($senderEmail)) {
            $this->logger->warn("TripPlanningService: Kalendereinladung von nicht autorisiertem Absender abgewiesen: {$senderEmail}");
            return 0;
        }

        $events = $this->icsParser->parse($icsContent);
        if (empty($events)) {
            $this->logger->info("TripPlanningService: Keine gültigen VEVENT-Einträge in ICS-Datei gefunden.");
            return 0;
        }

        $processed = 0;

        foreach ($events as $event) {
            $uid = $event['uid'];
            $title = $event['summary'] ?: 'Reise ohne Titel';
            $location = $event['location'];
            $startDt = $event['start_datetime'];
            $endDt = $event['end_datetime'];
            $status = $event['status'];

            if (empty($startDt)) {
                continue;
            }

            // Prüfen, ob Termin bereits existiert
            $existing = $this->tripRepo->getTripByCalendarUid($uid);
            $tripId = null;

            if ($existing) {
                // Bei Stornierung (STATUS: CANCELLED) auf 'storniert' setzen
                if ($status === 'CANCELLED') {
                    $this->tripRepo->updateTrip((int)$existing['id'], ['status' => 'storniert']);
                    $this->logger->info("TripPlanningService: Reise #{$existing['id']} durch Kalenderabsage storniert.");
                    $processed++;
                    continue;
                }

                // Aktualisieren bei Terminverschiebung
                $this->tripRepo->updateTrip((int)$existing['id'], [
                    'title' => $title,
                    'departure_time' => $startDt,
                    'return_time' => $endDt,
                    'destination_address' => $location ?: $existing['destination_address'],
                ]);
                $this->recalculateTrip((int)$existing['id']);
                $tripId = (int)$existing['id'];
                $processed++;
            } else {
                if ($status === 'CANCELLED') {
                    continue; // Stornierte neue Termine nicht anlegen
                }

                // Neu anlegen
                $tripId = $this->planAndSaveTrip([
                    'calendar_uid' => $uid,
                    'title' => $title,
                    'destination_address' => $location,
                    'departure_time' => $startDt,
                    'return_time' => $endDt,
                ]);
                $processed++;
            }

            // Automatische Bestätigung (vorrangig Google Calendar Zusagen-Button via HTTP, sonst iMIP METHOD:REPLY via Mail)
            if ($tripId !== null && $this->settingsService->isTripCalendarAutoAcceptEnabled()) {
                $responder = !empty($recipientEmail) ? $recipientEmail : (string)($_ENV['IMAP_USER_KASSENBON'] ?? '');
                if ($responder !== '') {
                    $replyOk = $this->icsReplyService->sendAcceptReply($event, $responder, 'Kai Ladeplaner', $emailBody);
                    if ($replyOk) {
                        $this->logger->info("TripPlanningService: Einladung zu '{$title}' an Organisator {$event['organizer_email']} bestätigt.");
                        if ($this->activityLogger !== null) {
                            $this->activityLogger->log(
                                'car_charge_captured',
                                "Termineinladung zu \"{$title}\" angenommen & bestätigt",
                                "/car/index.php?tab=trips&trip_id=" . $tripId,
                                $tripId
                            );
                        }
                    }
                }
            }
        }

        return $processed;
    }

    /**
     * Prüft, ob der Absender einer Kalender-Mail berechtigt ist.
     */
    private function isSenderAllowed(string $senderEmail): bool
    {
        $sender = strtolower(trim($senderEmail));
        if ($sender === '') {
            return false;
        }

        // 1. Systemeinstellung 'trip_calendar_allowed_senders'
        $customAllowed = $this->settingsService->getTripCalendarAllowedSenders();
        if (!empty($customAllowed)) {
            $list = array_map('trim', explode(',', strtolower($customAllowed)));
            if (in_array($sender, $list, true)) {
                return true;
            }
        }

        // 2. Fallback: ALLOWED_USERS aus .env
        $allowedEnv = (string)($_ENV['ALLOWED_USERS'] ?? '');
        $envList = array_map('trim', explode(',', strtolower($allowedEnv)));

        return in_array($sender, $envList, true);
    }

    /**
     * Synchronisiert den Status aller anstehenden und aktiven Reisen anhand der aktuellen Zeit.
     */
    public function syncTripStatuses(): int
    {
        $now = date('Y-m-d H:i:s');
        $updated = 0;

        $pdo = Database::getInstance()->getConnection();

        // 1. 'geplant' -> 'aktiv', wenn Abfahrtszeitpunkt erreicht ist
        $stmtStart = $pdo->prepare("
            UPDATE car_trips
            SET status = 'aktiv'
            WHERE status = 'geplant'
              AND departure_time <= :now
              AND (return_time IS NULL OR return_time >= :now2)
        ");
        $stmtStart->execute([':now' => $now, ':now2' => $now]);
        $updated += $stmtStart->rowCount();

        // 2. 'aktiv' / 'geplant' -> 'abgeschlossen', wenn Rückkehrzeitpunkt überschritten ist
        $stmtEnd = $pdo->prepare("
            UPDATE car_trips
            SET status = 'abgeschlossen'
            WHERE status IN ('geplant', 'aktiv')
              AND return_time IS NOT NULL
              AND return_time < :now
        ");
        $stmtEnd->execute([':now' => $now]);
        $updated += $stmtEnd->rowCount();

        // 3. Telemetrie-Abgleich mit realen TRONITY-Daten durchführen
        try {
            $telemetryTracker = new TripTelemetryTracker($pdo, $this->logger);
            $telemetryTracker->trackTrips();
        } catch (\Throwable $te) {
            $this->logger->warn("TripPlanningService: Telemetrie-Tracking übersprungen: " . $te->getMessage());
        }

        return $updated;
    }

    public function getTripRepository(): TripRepository
    {
        return $this->tripRepo;
    }

    public function getExpenseService(): TripExpenseService
    {
        return $this->expenseService;
    }
}

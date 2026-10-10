<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Service für das Auffinden und Zuordnen von Bank- & Kreditkartenbuchungen zu Reisen (Phase 3).
 * Berechnet Cent-genaue Reisekostenbilanzen und Effizienz-Metriken (€ / 100 km).
 */
class TripExpenseService
{
    private const array CHARGE_KEYWORDS = [
        'enbw', 'ionity', 'tesla', 'fastned', 'aral pulse', 'allego', 'ewe go',
        'maingau', 'shell recharge', 'e.on drive', 'eon drive', 'supercharger',
        'ladenetz', 'mer germany', 'compleo', 'smatrics', 'mobility+', 'chargepoint',
        'be-emobility', 'totalenergies charge', 'kempower', 'electrify', 'ladestation',
    ];

    private const array TOLL_KEYWORDS = [
        'vignette', 'maut', 'telepass', 'bip&go', 'asfinag', 'autostrade',
        'sanef', 'aprr', 'vinci autoroutes', 'area maut', 'toll', 'peage',
    ];

    private const array PARKING_KEYWORDS = [
        'apcoa', 'easypark', 'parken', 'parkhaus', 'q-park', 'contipark',
        'flowbird', 'paybyphone', 'parkplatz', 'tiefgarage', 'parking',
    ];

    private PDO $pdo;
    private TripRepository $tripRepo;
    private Logger $logger;

    public function __construct(
        ?PDO $pdo = null,
        ?TripRepository $tripRepo = null,
        ?Logger $logger = null
    ) {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->tripRepo = $tripRepo ?? new TripRepository($this->pdo);
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Ermittelt passende Buchungskandidaten im Reisezeitraum (inkl. Vor- und Nachlauffenster).
     *
     * @return array<int, array{
     *     transaction_type: string,
     *     transaction_id: int,
     *     booking_date: string,
     *     amount: float,
     *     partner_name: string,
     *     description: string,
     *     suggested_category: string,
     *     confidence: string,
     *     is_linked: bool
     * }>
     */
    public function getCandidateTransactions(int $tripId): array
    {
        $trip = $this->tripRepo->getTrip($tripId);
        if (!$trip) {
            return [];
        }

        $depDate = substr($trip['departure_time'], 0, 10);
        $retDate = !empty($trip['return_time']) ? substr($trip['return_time'], 0, 10) : $depDate;

        // Puffer: 1 Tag vor Abfahrt (z.B. Vorab-Maut) bis 4 Tage nach Rückkehr (Wertstellung Kreditkarte)
        $dateFrom = date('Y-m-d', strtotime("{$depDate} -1 day"));
        $dateTo = date('Y-m-d', strtotime("{$retDate} +4 days"));

        // Bereits dieser Reise zugeordnete Transaktionen ermitteln
        $linked = $this->tripRepo->getTripTransactions($tripId);
        $linkedMap = [];
        foreach ($linked as $item) {
            $linkedMap[$item['transaction_type'] . '_' . $item['transaction_id']] = true;
        }

        $candidates = [];

        // 1. Giro-Buchungen im Zeitraum
        $stmtGiro = $this->pdo->prepare("
            SELECT id, booking_date, amount,
                   COALESCE(NULLIF(creditor, ''), NULLIF(remitter, ''), NULLIF(debitor, ''), 'Giro-Buchung') AS partner_name,
                   remittance_info AS description
            FROM bank_giro_transactions
            WHERE booking_date >= :from_date AND booking_date <= :to_date
              AND amount < 0
            ORDER BY booking_date DESC
        ");
        $stmtGiro->execute([':from_date' => $dateFrom, ':to_date' => $dateTo]);
        while ($row = $stmtGiro->fetch(PDO::FETCH_ASSOC)) {
            $key = 'giro_' . $row['id'];
            $isLinked = isset($linkedMap[$key]);
            $category = $this->classifyTransaction($row['partner_name'] . ' ' . $row['description']);

            $candidates[] = [
                'transaction_type' => 'giro',
                'transaction_id' => (int)$row['id'],
                'booking_date' => $row['booking_date'],
                'amount' => abs((float)$row['amount']),
                'partner_name' => $row['partner_name'],
                'description' => $row['description'],
                'suggested_category' => $category['category'],
                'confidence' => $category['confidence'],
                'is_linked' => $isLinked,
            ];
        }

        // 2. Kreditkarten-Buchungen im Zeitraum
        $stmtCc = $this->pdo->prepare("
            SELECT id, booking_date, amount, merchant_name AS partner_name
            FROM bank_cc_transactions
            WHERE booking_date >= :from_date AND booking_date <= :to_date
              AND amount < 0
            ORDER BY booking_date DESC
        ");
        $stmtCc->execute([':from_date' => $dateFrom, ':to_date' => $dateTo]);
        while ($row = $stmtCc->fetch(PDO::FETCH_ASSOC)) {
            $key = 'creditcard_' . $row['id'];
            $isLinked = isset($linkedMap[$key]);
            $category = $this->classifyTransaction($row['partner_name']);

            $candidates[] = [
                'transaction_type' => 'creditcard',
                'transaction_id' => (int)$row['id'],
                'booking_date' => $row['booking_date'],
                'amount' => abs((float)$row['amount']),
                'partner_name' => $row['partner_name'],
                'description' => 'Kreditkarte: ' . $row['partner_name'],
                'suggested_category' => $category['category'],
                'confidence' => $category['confidence'],
                'is_linked' => $isLinked,
            ];
        }

        // Sortierung: Treffer mit hoher Relevanz zuerst, dann nach Buchungsdatum
        usort($candidates, static function ($a, $b) {
            $scoreA = ($a['confidence'] === 'high') ? 2 : (($a['confidence'] === 'medium') ? 1 : 0);
            $scoreB = ($b['confidence'] === 'high') ? 2 : (($b['confidence'] === 'medium') ? 1 : 0);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }
            return strcmp($b['booking_date'], $a['booking_date']);
        });

        return $candidates;
    }

    /**
     * Berechnet die Gesamtkosten einer Reise neu und aktualisiert die DB.
     *
     * @return array{
     *     home_charge_cost: float,
     *     en_route_charge_cost: float,
     *     additional_cost: float,
     *     total_cost: float,
     *     cost_per_100km: float
     * }
     */
    public function recalculateTripCosts(int $tripId): array
    {
        $trip = $this->tripRepo->getTrip($tripId);
        if (!$trip) {
            return [
                'home_charge_cost' => 0.0,
                'en_route_charge_cost' => 0.0,
                'additional_cost' => 0.0,
                'total_cost' => 0.0,
                'cost_per_100km' => 0.0,
            ];
        }

        $homeChargeCost = (float)$trip['home_charge_cost'];
        $enRouteCost = 0.0;
        $additionalCost = 0.0;

        $linked = $this->tripRepo->getTripTransactions($tripId);
        foreach ($linked as $item) {
            $amt = abs((float)$item['amount']);
            if ($item['cost_category'] === 'charge') {
                $enRouteCost += $amt;
            } else {
                $additionalCost += $amt;
            }
        }

        $totalCost = round($homeChargeCost + $enRouteCost + $additionalCost, 2);
        $distanceKm = (float)$trip['total_distance_km'];
        $costPer100Km = ($distanceKm > 0) ? round(($totalCost / $distanceKm) * 100.0, 2) : 0.0;

        $this->tripRepo->updateTripCosts($tripId, $homeChargeCost, $enRouteCost, $additionalCost);

        return [
            'home_charge_cost' => $homeChargeCost,
            'en_route_charge_cost' => round($enRouteCost, 2),
            'additional_cost' => round($additionalCost, 2),
            'total_cost' => $totalCost,
            'cost_per_100km' => $costPer100Km,
        ];
    }

    /**
     * Ordnet einen Buchungstext heuristisch einer Kostenkategorie zu.
     *
     * @return array{category: string, confidence: string}
     */
    private function classifyTransaction(string $text): array
    {
        $lower = strtolower($text);

        foreach (self::CHARGE_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return ['category' => 'charge', 'confidence' => 'high'];
            }
        }

        foreach (self::TOLL_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return ['category' => 'toll', 'confidence' => 'high'];
            }
        }

        foreach (self::PARKING_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return ['category' => 'parking', 'confidence' => 'high'];
            }
        }

        return ['category' => 'other', 'confidence' => 'low'];
    }
}

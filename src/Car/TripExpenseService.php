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

        // Prüfen, welche Kandidaten bereits durch zugeordnete Ladebelege abgedeckt sind
        $charges = $this->tripRepo->getTripCharges($tripId);
        $coveredCcTxMap = [];
        $coveredGiroTxMap = [];
        $coveredReceipts = [];

        foreach ($charges as $c) {
            if (!empty($c['bank_cc_transaction_id'])) {
                $coveredCcTxMap[(int)$c['bank_cc_transaction_id']] = $c;
            }
            if (!empty($c['bank_giro_transaction_id'])) {
                $coveredGiroTxMap[(int)$c['bank_giro_transaction_id']] = $c;
            }
            if (!empty($c['receipt_id']) && !empty($c['cost_eur'])) {
                $coveredReceipts[] = [
                    'amount' => round((float)$c['cost_eur'], 2),
                    'store' => mb_strtolower((string)($c['receipt_store'] ?: $c['tariff_category'] ?: '')),
                    'charge' => $c,
                ];
            }
        }

        foreach ($candidates as &$cand) {
            $isCovered = false;
            $coveredInfo = '';

            if ($cand['transaction_type'] === 'creditcard' && isset($coveredCcTxMap[$cand['transaction_id']])) {
                $isCovered = true;
                $c = $coveredCcTxMap[$cand['transaction_id']];
                $coveredInfo = ($c['receipt_store'] ?: 'Ladebeleg') . ' (' . number_format((float)$c['cost_eur'], 2, ',', '.') . ' €)';
            } elseif ($cand['transaction_type'] === 'giro' && isset($coveredGiroTxMap[$cand['transaction_id']])) {
                $isCovered = true;
                $c = $coveredGiroTxMap[$cand['transaction_id']];
                $coveredInfo = ($c['receipt_store'] ?: 'Ladebeleg') . ' (' . number_format((float)$c['cost_eur'], 2, ',', '.') . ' €)';
            } else {
                $candAmt = round($cand['amount'], 2);
                $partnerLower = mb_strtolower($cand['partner_name'] . ' ' . $cand['description']);
                foreach ($coveredReceipts as $cr) {
                    if (abs($candAmt - $cr['amount']) < 0.01 && ($cr['store'] !== '' && str_contains($partnerLower, $cr['store']))) {
                        $isCovered = true;
                        $c = $cr['charge'];
                        $coveredInfo = ($c['receipt_store'] ?: 'Ladebeleg') . ' (' . number_format((float)$c['cost_eur'], 2, ',', '.') . ' €)';
                        break;
                    }
                }
            }

            $cand['already_covered'] = $isCovered;
            $cand['covered_by_receipt'] = $coveredInfo;
        }
        unset($cand);

        return $candidates;
    }

    /**
     * Berechnet die Gesamtkosten einer Reise neu und aktualisiert die DB.
     * Berücksichtigt vorrangig Ladebelege aus vehicle_charges (z. B. B2Mobility, EnBW),
     * damit Kosten sofort nach Reiseabschluss ohne Warten auf Bankabrechnungen feststehen.
     *
     * @return array{
     *     home_charge_cost: float,
     *     en_route_charge_cost: float,
     *     additional_cost: float,
     *     total_cost: float,
     *     cost_per_100km: float,
     *     actual_en_route_charge_kwh: float
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
                'actual_en_route_charge_kwh' => 0.0,
            ];
        }

        // 1. Automatisch Ladevorgänge im Reisezeitraum mit der Reise verknüpfen
        $this->tripRepo->autoLinkChargesForTrip($tripId);

        // 2. Alle verknüpften Ladevorgänge abrufen
        $charges = $this->tripRepo->getTripCharges($tripId);

        $homeChargeCost = (float)$trip['home_charge_cost'];
        $enRouteKwh = 0.0;
        $enRouteCostFromCharges = 0.0;
        $coveredCcTxIds = [];
        $coveredGiroTxIds = [];
        $coveredReceipts = [];

        foreach ($charges as $c) {
            $isHome = ($c['location_type'] ?? '') === 'HOME';

            if ($isHome) {
                // Vorabend-Heimladung aus vehicle_charges übernehmen
                if (!empty($c['cost_eur']) && (float)$c['cost_eur'] > 0) {
                    $homeChargeCost = round((float)$c['cost_eur'], 2);
                } elseif (!empty($c['home_grid_kwh']) && (float)$c['home_grid_kwh'] > 0) {
                    $settingsService = new \Kai\Tools\System\SystemSettingsService($this->pdo);
                    $homeChargeCost = round((float)$c['home_grid_kwh'] * $settingsService->getGridImportPrice(), 2);
                }
                continue;
            }

            $enRouteKwh += (float)$c['charged_net_kwh'];

            if (!empty($c['cost_eur']) && (float)$c['cost_eur'] > 0) {
                $enRouteCostFromCharges += (float)$c['cost_eur'];
                $coveredReceipts[] = [
                    'amount' => round((float)$c['cost_eur'], 2),
                    'store' => mb_strtolower((string)($c['receipt_store'] ?: $c['tariff_category'] ?: '')),
                ];
            }
            if (!empty($c['bank_cc_transaction_id'])) {
                $coveredCcTxIds[] = (int)$c['bank_cc_transaction_id'];
            }
            if (!empty($c['bank_giro_transaction_id'])) {
                $coveredGiroTxIds[] = (int)$c['bank_giro_transaction_id'];
            }
        }

        // 3. Manuell/automatisch zugeordnete Bank- und Kreditkartentransaktionen
        $enRouteCostFromTx = 0.0;
        $additionalCost = 0.0;

        $linked = $this->tripRepo->getTripTransactions($tripId);
        foreach ($linked as $item) {
            $amt = abs((float)$item['amount']);
            $txType = $item['transaction_type'];
            $txId = (int)$item['transaction_id'];

            if ($item['cost_category'] === 'charge') {
                // Doppelzählung vermeiden: Ist die Buchung bereits durch einen Ladebeleg abgedeckt?
                $isCovered = ($txType === 'creditcard' && in_array($txId, $coveredCcTxIds, true))
                    || ($txType === 'giro' && in_array($txId, $coveredGiroTxIds, true));

                if (!$isCovered && !empty($coveredReceipts)) {
                    $itemAmt = round($amt, 2);
                    $partnerLower = mb_strtolower($item['partner_name'] . ' ' . ($item['description'] ?? ''));
                    foreach ($coveredReceipts as $cr) {
                        if (abs($itemAmt - $cr['amount']) < 0.01 && ($cr['store'] !== '' && str_contains($partnerLower, $cr['store']))) {
                            $isCovered = true;
                            break;
                        }
                    }
                }

                if (!$isCovered) {
                    $enRouteCostFromTx += $amt;
                }
            } else {
                $additionalCost += $amt;
            }
        }

        $enRouteTotalCost = round($enRouteCostFromCharges + $enRouteCostFromTx, 2);
        $totalCost = round($homeChargeCost + $enRouteTotalCost + $additionalCost, 2);

        $distanceKm = !empty($trip['actual_distance_km']) ? (float)$trip['actual_distance_km'] : (float)$trip['total_distance_km'];
        $costPer100Km = ($distanceKm > 0) ? round(($totalCost / $distanceKm) * 100.0, 2) : 0.0;

        $actualEnRouteKwh = ($enRouteKwh > 0) ? round($enRouteKwh, 2) : null;

        $this->tripRepo->updateTrip($tripId, [
            'home_charge_cost' => $homeChargeCost,
            'en_route_charge_cost' => $enRouteTotalCost,
            'additional_cost' => round($additionalCost, 2),
            'actual_en_route_charge_kwh' => $actualEnRouteKwh,
        ]);

        return [
            'home_charge_cost' => $homeChargeCost,
            'en_route_charge_cost' => $enRouteTotalCost,
            'additional_cost' => round($additionalCost, 2),
            'total_cost' => $totalCost,
            'cost_per_100km' => $costPer100Km,
            'actual_en_route_charge_kwh' => $actualEnRouteKwh ?? 0.0,
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

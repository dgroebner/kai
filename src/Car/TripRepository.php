<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Db\Database;
use PDO;

/**
 * Repository für Datenbankzugriffe auf car_trips, car_trip_charging_steps und car_trip_transactions.
 */
class TripRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
    }

    /**
     * Erstellt eine neue Reise.
     *
     * @param array<string, mixed> $data
     * @return int Generierte Trip-ID
     */
    public function createTrip(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO car_trips (
                parent_trip_id, calendar_uid, title,
                start_address, start_lat, start_lon,
                destination_address, destination_lat, destination_lon,
                departure_time, return_time, is_round_trip,
                target_arrival_soc, planned_departure_soc,
                total_distance_km, estimated_consumption_kwh, en_route_charge_kwh,
                routing_provider, abrp_deep_link, status,
                home_charge_cost, en_route_charge_cost, additional_cost
            ) VALUES (
                :parent_trip_id, :calendar_uid, :title,
                :start_address, :start_lat, :start_lon,
                :destination_address, :destination_lat, :destination_lon,
                :departure_time, :return_time, :is_round_trip,
                :target_arrival_soc, :planned_departure_soc,
                :total_distance_km, :estimated_consumption_kwh, :en_route_charge_kwh,
                :routing_provider, :abrp_deep_link, :status,
                :home_charge_cost, :en_route_charge_cost, :additional_cost
            )
        ");

        $stmt->execute([
            ':parent_trip_id' => $data['parent_trip_id'] ?? null,
            ':calendar_uid' => $data['calendar_uid'] ?? null,
            ':title' => $data['title'] ?? 'Neue Reise',
            ':start_address' => $data['start_address'] ?? 'Zuhause',
            ':start_lat' => $data['start_lat'] ?? 0.0,
            ':start_lon' => $data['start_lon'] ?? 0.0,
            ':destination_address' => $data['destination_address'] ?? '',
            ':destination_lat' => $data['destination_lat'] ?? 0.0,
            ':destination_lon' => $data['destination_lon'] ?? 0.0,
            ':departure_time' => $data['departure_time'],
            ':return_time' => $data['return_time'] ?? null,
            ':is_round_trip' => !empty($data['is_round_trip']) ? 1 : 0,
            ':target_arrival_soc' => (int)($data['target_arrival_soc'] ?? 10),
            ':planned_departure_soc' => (int)($data['planned_departure_soc'] ?? 80),
            ':total_distance_km' => (float)($data['total_distance_km'] ?? 0.0),
            ':estimated_consumption_kwh' => (float)($data['estimated_consumption_kwh'] ?? 0.0),
            ':en_route_charge_kwh' => (float)($data['en_route_charge_kwh'] ?? 0.0),
            ':routing_provider' => $data['routing_provider'] ?? 'ORS_HEURISTIC',
            ':abrp_deep_link' => $data['abrp_deep_link'] ?? null,
            ':status' => $data['status'] ?? 'geplant',
            ':home_charge_cost' => (float)($data['home_charge_cost'] ?? 0.0),
            ':en_route_charge_cost' => (float)($data['en_route_charge_cost'] ?? 0.0),
            ':additional_cost' => (float)($data['additional_cost'] ?? 0.0),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Aktualisiert eine bestehende Reise.
     *
     * @param int $id
     * @param array<string, mixed> $data
     * @return bool
     */
    public function updateTrip(int $id, array $data): bool
    {
        $fields = [];
        $params = [':id' => $id];

        $allowedColumns = [
            'parent_trip_id', 'calendar_uid', 'title',
            'start_address', 'start_lat', 'start_lon',
            'destination_address', 'destination_lat', 'destination_lon',
            'departure_time', 'return_time', 'is_round_trip',
            'target_arrival_soc', 'planned_departure_soc',
            'total_distance_km', 'estimated_consumption_kwh', 'en_route_charge_kwh',
            'actual_en_route_charge_kwh',
            'actual_distance_km', 'actual_consumption_kwh', 'actual_arrival_soc', 'telemetry_matched_at',
            'routing_provider', 'abrp_deep_link', 'status',
            'home_charge_cost', 'en_route_charge_cost', 'additional_cost'
        ];

        foreach ($allowedColumns as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "`{$col}` = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE car_trips SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);

        return $stmt->execute($params);
    }

    public function deleteTrip(int $id): bool
    {
        $this->ensureIgnoredChargesTable();
        $this->pdo->prepare("UPDATE vehicle_charges SET trip_id = NULL WHERE trip_id = :id")->execute([':id' => $id]);
        $this->pdo->prepare("DELETE FROM car_trip_ignored_charges WHERE trip_id = :id")->execute([':id' => $id]);
        $stmt = $this->pdo->prepare("DELETE FROM car_trips WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Berechnet den theoretischen Mindest-Start-SoC, um die geplante Fahrt
     * inklusive des gewünschten Ziel-Restladestands ohne Unterwegs-Ladestopp zu bewältigen.
     */
    public static function calculateMinDepartureSoc(float $estimatedConsumptionKwh, int $targetArrivalSoc = 10): int
    {
        if ($estimatedConsumptionKwh <= 0.0) {
            return max(10, $targetArrivalSoc);
        }
        $socNeeded = ($estimatedConsumptionKwh / OrsHeuristicPlanner::BATTERY_CAPACITY_KWH) * 100.0;
        $rawMin = (int)ceil($targetArrivalSoc + $socNeeded);

        return min(100, max($targetArrivalSoc, $rawMin));
    }

    /**
     * Ergänzt ein Reise-Array um dynamische Komfort-Werte (Mindest-SoC, Machbarkeit ohne Ladestopp).
     *
     * @param array<string, mixed> $trip
     * @return array<string, mixed>
     */
    public static function enrichTripData(array $trip): array
    {
        $consumption = (float)($trip['estimated_consumption_kwh'] ?? 0.0);
        $targetArrivalSoc = (int)($trip['target_arrival_soc'] ?? 10);
        $trip['min_departure_soc'] = self::calculateMinDepartureSoc($consumption, $targetArrivalSoc);
        $socNeeded = ($consumption / OrsHeuristicPlanner::BATTERY_CAPACITY_KWH) * 100.0;
        $trip['can_drive_without_charging'] = ($targetArrivalSoc + $socNeeded) <= 100.0;
        return $trip;
    }

    public function getTrip(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*, p.title AS parent_title
            FROM car_trips t
            LEFT JOIN car_trips p ON t.parent_trip_id = p.id
            WHERE t.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::enrichTripData($row) : null;
    }

    public function getTripByCalendarUid(string $uid): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM car_trips WHERE calendar_uid = :uid LIMIT 1");
        $stmt->execute([':uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::enrichTripData($row) : null;
    }

    public function getTrips(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT t.*, p.title AS parent_title,
                   (SELECT COUNT(*) FROM car_trips sub WHERE sub.parent_trip_id = t.id) AS subtrip_count
            FROM car_trips t
            LEFT JOIN car_trips p ON t.parent_trip_id = p.id
        ";

        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= " WHERE t.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY t.departure_time DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'enrichTripData'], $rows);
    }

    public function countTrips(?string $status = null): int
    {
        $sql = "SELECT COUNT(*) FROM car_trips";
        $params = [];

        if ($status !== null && $status !== '') {
            $sql .= " WHERE status = :status";
            $params[':status'] = $status;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function getUpcomingTrips(int $days = 7): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM car_trips
            WHERE status IN ('geplant', 'aktiv')
              AND departure_time >= NOW() - INTERVAL 1 DAY
              AND departure_time <= NOW() + INTERVAL :days DAY
            ORDER BY departure_time ASC
        ");
        $stmt->bindValue(':days', max(1, $days), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'enrichTripData'], $rows);
    }

    public function getActiveTrips(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM car_trips
            WHERE status = 'aktiv'
               OR (status = 'geplant' AND departure_time <= NOW() AND (return_time IS NULL OR return_time >= NOW()))
            ORDER BY departure_time ASC
        ");
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'enrichTripData'], $rows);
    }

    public function getSubTrips(int $parentTripId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM car_trips
            WHERE parent_trip_id = :pid
            ORDER BY departure_time ASC
        ");
        $stmt->execute([':pid' => $parentTripId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'enrichTripData'], $rows);
    }

    /**
     * Sucht eine übergeordnete Reise, in deren Zeitraum der angegebene Zeitraum komplett fällt.
     */
    public function findOverlappingParentTrip(string $start, string $end, ?int $excludeTripId = null): ?array
    {
        $sql = "
            SELECT * FROM car_trips
            WHERE parent_trip_id IS NULL
              AND departure_time <= :start
              AND return_time IS NOT NULL
              AND return_time >= :end
        ";
        $params = [
            ':start' => $start,
            ':end' => $end,
        ];

        if ($excludeTripId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeTripId;
        }

        $sql .= " ORDER BY departure_time ASC LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    // =========================================================================
    // LADESCHRITTE (car_trip_charging_steps)
    // =========================================================================

    public function saveChargingSteps(int $tripId, array $steps): void
    {
        // Vorhandene geplante Schritte für diese Reise löschen
        $stmtDel = $this->pdo->prepare("DELETE FROM car_trip_charging_steps WHERE trip_id = :tid");
        $stmtDel->execute([':tid' => $tripId]);

        if (empty($steps)) {
            return;
        }

        $stmtIns = $this->pdo->prepare("
            INSERT INTO car_trip_charging_steps (
                trip_id, step_type, scheduled_date, target_soc, planned_kwh, status
            ) VALUES (
                :trip_id, :step_type, :scheduled_date, :target_soc, :planned_kwh, :status
            )
        ");

        foreach ($steps as $s) {
            $stmtIns->execute([
                ':trip_id' => $tripId,
                ':step_type' => $s['step_type'],
                ':scheduled_date' => $s['scheduled_date'],
                ':target_soc' => (int)$s['target_soc'],
                ':planned_kwh' => (float)$s['planned_kwh'],
                ':status' => $s['status'] ?? 'geplant',
            ]);
        }
    }

    public function getChargingSteps(int $tripId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM car_trip_charging_steps
            WHERE trip_id = :tid
            ORDER BY scheduled_date ASC, id ASC
        ");
        $stmt->execute([':tid' => $tripId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateChargingStepStatus(int $stepId, string $status): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE car_trip_charging_steps
            SET status = :status
            WHERE id = :id
        ");
        return $stmt->execute([
            ':status' => $status,
            ':id' => $stepId,
        ]);
    }

    // =========================================================================
    // TRANSAKTIONEN & AUSGABEN (car_trip_transactions)
    // =========================================================================

    public function linkTransaction(int $tripId, string $type, int $txId, string $category = 'charge'): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO car_trip_transactions (trip_id, transaction_type, transaction_id, cost_category)
            VALUES (:trip_id, :type, :tx_id, :cat)
            ON DUPLICATE KEY UPDATE cost_category = VALUES(cost_category)
        ");
        return $stmt->execute([
            ':trip_id' => $tripId,
            ':type' => $type,
            ':tx_id' => $txId,
            ':cat' => $category,
        ]);
    }

    public function unlinkTransaction(int $tripId, string $type, int $txId): bool
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM car_trip_transactions
            WHERE trip_id = :trip_id AND transaction_type = :type AND transaction_id = :tx_id
        ");
        return $stmt->execute([
            ':trip_id' => $tripId,
            ':type' => $type,
            ':tx_id' => $txId,
        ]);
    }

    public function getTripTransactions(int $tripId): array
    {
        // 1. Giro-Buchungen abfragen
        $stmtGiro = $this->pdo->prepare("
            SELECT tt.id AS link_id, tt.trip_id, tt.cost_category, tt.created_at AS linked_at,
                   'giro' AS transaction_type,
                   g.id AS transaction_id, g.booking_date, g.amount,
                   COALESCE(NULLIF(g.creditor, ''), NULLIF(g.remitter, ''), NULLIF(g.debitor, ''), 'Girokonto') AS partner_name,
                   g.remittance_info AS description
            FROM car_trip_transactions tt
            JOIN bank_giro_transactions g ON tt.transaction_id = g.id
            WHERE tt.trip_id = :tid AND tt.transaction_type = 'giro'
        ");
        $stmtGiro->execute([':tid' => $tripId]);
        $giroTx = $stmtGiro->fetchAll(PDO::FETCH_ASSOC);

        // 2. Kreditkarten-Buchungen abfragen
        $stmtCc = $this->pdo->prepare("
            SELECT tt.id AS link_id, tt.trip_id, tt.cost_category, tt.created_at AS linked_at,
                   'creditcard' AS transaction_type,
                   c.id AS transaction_id, c.booking_date, c.amount,
                   c.merchant_name AS partner_name,
                   CONCAT('Kreditkarte: ', c.merchant_name) AS description
            FROM car_trip_transactions tt
            JOIN bank_cc_transactions c ON tt.transaction_id = c.id
            WHERE tt.trip_id = :tid AND tt.transaction_type = 'creditcard'
        ");
        $stmtCc->execute([':tid' => $tripId]);
        $ccTx = $stmtCc->fetchAll(PDO::FETCH_ASSOC);

        $merged = array_merge($giroTx, $ccTx);
        usort($merged, static fn($a, $b) => strcmp($a['booking_date'], $b['booking_date']));

        return $merged;
    }

    public function updateTripCosts(int $tripId, float $homeChargeCost, float $enRouteCost, float $additionalCost): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE car_trips
            SET home_charge_cost = :home,
                en_route_charge_cost = :en_route,
                additional_cost = :add
            WHERE id = :id
        ");
        return $stmt->execute([
            ':home' => $homeChargeCost,
            ':en_route' => $enRouteCost,
            ':add' => $additionalCost,
            ':id' => $tripId,
        ]);
    }

    // =========================================================================
    // LADEVORGÄNGE & BELEGE (vehicle_charges)
    // =========================================================================

    /**
     * Verknüpft automatisch Unterwegs- und Vorbereitungsladevorgänge (vehicle_charges) im Reisefenster mit der Reise,
     * sofern sie nicht vom Nutzer explizit für diese Reise ignoriert wurden.
     *
     * @return int Anzahl verknüpfter Ladevorgänge
     */
    public function autoLinkChargesForTrip(int $tripId): int
    {
        $trip = $this->getTrip($tripId);
        if (!$trip) {
            return 0;
        }

        $this->ensureIgnoredChargesTable();

        $depTime = (string)$trip['departure_time'];
        $retTime = !empty($trip['return_time']) ? (string)$trip['return_time'] : null;

        // Puffer für Reiseende: 60 Min nach Rückkehr, bzw. 24h nach Abfahrt bei Einweg-Reisen
        if ($retTime !== null) {
            $endWindowLocal = date('Y-m-d H:i:s', strtotime($retTime) + 3600);
        } elseif ($trip['status'] === 'abgeschlossen') {
            $endWindowLocal = date('Y-m-d H:i:s', strtotime($depTime) + 86400);
        } else {
            $endWindowLocal = date('Y-m-d H:i:s', time() + 3600);
        }

        $depUtc = $this->toUtcString($depTime);
        $endUtc = $this->toUtcString($endWindowLocal);

        // 1. Öffentliche / Unterwegs-Ladungen im Reisezeitraum (ab 30 Min vor Abfahrt bis Reiseende)
        $startWindowLocal = date('Y-m-d H:i:s', strtotime($depTime) - 1800);
        $startUtc = $this->toUtcString($startWindowLocal);

        $stmtEnRoute = $this->pdo->prepare("
            UPDATE vehicle_charges
            SET trip_id = :trip_id
            WHERE (trip_id IS NULL OR trip_id = :trip_id2)
              AND (location_type IS NULL OR location_type != 'HOME')
              AND start_time >= :start_utc
              AND start_time <= :end_utc
              AND id NOT IN (SELECT charge_id FROM car_trip_ignored_charges WHERE trip_id = :trip_id3)
        ");
        $stmtEnRoute->execute([
            ':trip_id' => $tripId,
            ':trip_id2' => $tripId,
            ':start_utc' => $startUtc,
            ':end_utc' => $endUtc,
            ':trip_id3' => $tripId,
        ]);
        $linkedCount = $stmtEnRoute->rowCount();

        // 2. Vorbereitungsladungen im 36h-Vorfenster vor Abfahrt (alle Heimladungen & Vorbereitungs-Schnelllader)
        $stmtPrep = $this->pdo->prepare("
            UPDATE vehicle_charges
            SET trip_id = :trip_id
            WHERE (trip_id IS NULL OR trip_id = :trip_id2)
              AND start_time >= DATE_SUB(:dep_utc, INTERVAL 36 HOUR)
              AND start_time < :start_utc
              AND id NOT IN (SELECT charge_id FROM car_trip_ignored_charges WHERE trip_id = :trip_id3)
        ");
        $stmtPrep->execute([
            ':trip_id' => $tripId,
            ':trip_id2' => $tripId,
            ':dep_utc' => $depUtc,
            ':start_utc' => $startUtc,
            ':trip_id3' => $tripId,
        ]);
        $linkedCount += $stmtPrep->rowCount();

        return $linkedCount;
    }

    /**
     * Liefert alle einer Reise zugeordneten Ladevorgänge inklusive Belegdaten.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTripCharges(int $tripId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT vc.*,
                   r.store AS receipt_store,
                   r.purchase_date AS receipt_purchase_date,
                   r.total AS receipt_total,
                   r.file_hash AS receipt_file_hash,
                   r.bank_cc_transaction_id,
                   r.bank_giro_transaction_id
            FROM vehicle_charges vc
            LEFT JOIN kb_receipts r ON r.id = vc.receipt_id
            WHERE vc.trip_id = :trip_id
            ORDER BY vc.start_time ASC
        ");
        $stmt->execute([':trip_id' => $tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Ermittelt Ladevorgänge im erweiterten Reiseumfeld (Vorbereitung ab 48h vor Abfahrt bis Reiseende),
     * die dieser Reise zugeordnet werden können bzw. bereits zugeordnet sind.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCandidateCharges(int $tripId): array
    {
        $trip = $this->getTrip($tripId);
        if (!$trip) {
            return [];
        }

        $this->ensureIgnoredChargesTable();

        $depTime = (string)$trip['departure_time'];
        $retTime = !empty($trip['return_time']) ? (string)$trip['return_time'] : null;

        // Vorbereitungsfenster: 48 Stunden vor Abfahrt bis 4 Stunden nach Rückkehr (bzw. +48h bei Einweg)
        $startWindowLocal = date('Y-m-d H:i:s', strtotime($depTime) - (48 * 3600));
        if ($retTime !== null) {
            $endWindowLocal = date('Y-m-d H:i:s', strtotime($retTime) + (4 * 3600));
        } elseif ($trip['status'] === 'abgeschlossen') {
            $endWindowLocal = date('Y-m-d H:i:s', strtotime($depTime) + (48 * 3600));
        } else {
            $endWindowLocal = date('Y-m-d H:i:s', time() + (4 * 3600));
        }

        $startUtc = $this->toUtcString($startWindowLocal);
        $endUtc = $this->toUtcString($endWindowLocal);

        $stmt = $this->pdo->prepare("
            SELECT vc.*,
                   r.store AS receipt_store,
                   r.purchase_date AS receipt_purchase_date,
                   r.total AS receipt_total,
                   r.bank_cc_transaction_id,
                   r.bank_giro_transaction_id,
                   (CASE WHEN vc.trip_id = :trip_id THEN 1 ELSE 0 END) AS is_linked
            FROM vehicle_charges vc
            LEFT JOIN kb_receipts r ON r.id = vc.receipt_id
            WHERE (vc.trip_id IS NULL OR vc.trip_id = :trip_id2)
              AND vc.start_time >= :start_utc
              AND vc.start_time <= :end_utc
            ORDER BY vc.start_time DESC
        ");
        $stmt->execute([
            ':trip_id' => $tripId,
            ':trip_id2' => $tripId,
            ':start_utc' => $startUtc,
            ':end_utc' => $endUtc,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function linkChargeToTrip(int $chargeId, int $tripId): bool
    {
        $this->ensureIgnoredChargesTable();

        $stmtUnignore = $this->pdo->prepare("
            DELETE FROM car_trip_ignored_charges
            WHERE trip_id = :trip_id AND charge_id = :charge_id
        ");
        $stmtUnignore->execute([
            ':trip_id' => $tripId,
            ':charge_id' => $chargeId,
        ]);

        $stmt = $this->pdo->prepare("
            UPDATE vehicle_charges
            SET trip_id = :trip_id
            WHERE id = :charge_id
        ");
        return $stmt->execute([
            ':trip_id' => $tripId,
            ':charge_id' => $chargeId,
        ]);
    }

    public function unlinkChargeFromTrip(int $chargeId, ?int $tripId = null): bool
    {
        $this->ensureIgnoredChargesTable();

        if ($tripId === null) {
            $stmtGet = $this->pdo->prepare("SELECT trip_id FROM vehicle_charges WHERE id = :id");
            $stmtGet->execute([':id' => $chargeId]);
            $tripId = (int)$stmtGet->fetchColumn();
        }

        if ($tripId > 0) {
            $stmtIgnore = $this->pdo->prepare("
                INSERT IGNORE INTO car_trip_ignored_charges (trip_id, charge_id)
                VALUES (:trip_id, :charge_id)
            ");
            $stmtIgnore->execute([
                ':trip_id' => $tripId,
                ':charge_id' => $chargeId,
            ]);
        }

        $stmt = $this->pdo->prepare("
            UPDATE vehicle_charges
            SET trip_id = NULL
            WHERE id = :charge_id
        ");
        return $stmt->execute([':charge_id' => $chargeId]);
    }

    private function ensureIgnoredChargesTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `car_trip_ignored_charges` (
                `trip_id` INT UNSIGNED NOT NULL,
                `charge_id` INT NOT NULL,
                PRIMARY KEY (`trip_id`, `charge_id`),
                INDEX `idx_charge_id` (`charge_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    private function toUtcString(string $localTimeStr): string
    {
        try {
            $dt = new \DateTime($localTimeStr, new \DateTimeZone('Europe/Berlin'));
            $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $localTimeStr;
        }
    }
}

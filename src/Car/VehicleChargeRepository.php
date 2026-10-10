<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Db\Database;
use PDO;

class VehicleChargeRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function saveCharge(array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO vehicle_charges (
                tronity_charge_id, start_time, end_time, duration_min,
                soc_start_pct, soc_end_pct, delta_soc_pct,
                charged_net_kwh, avg_charge_power_kw, charge_mode,
                lat, lon, location_type, tariff_category,
                station_name, station_operator,
                home_meter_kwh, home_pv_kwh, home_grid_kwh,
                loss_kwh, loss_pct, cost_eur, receipt_id
            ) VALUES (
                :tronity_charge_id, :start_time, :end_time, :duration_min,
                :soc_start_pct, :soc_end_pct, :delta_soc_pct,
                :charged_net_kwh, :avg_charge_power_kw, :charge_mode,
                :lat, :lon, :location_type, :tariff_category,
                :station_name, :station_operator,
                :home_meter_kwh, :home_pv_kwh, :home_grid_kwh,
                :loss_kwh, :loss_pct, :cost_eur, :receipt_id
            ) ON DUPLICATE KEY UPDATE
                start_time = VALUES(start_time), end_time = VALUES(end_time),
                duration_min = VALUES(duration_min), soc_start_pct = VALUES(soc_start_pct),
                soc_end_pct = VALUES(soc_end_pct), delta_soc_pct = VALUES(delta_soc_pct),
                charged_net_kwh = VALUES(charged_net_kwh), avg_charge_power_kw = VALUES(avg_charge_power_kw),
                charge_mode = VALUES(charge_mode), location_type = VALUES(location_type),
                tariff_category = COALESCE(tariff_category, VALUES(tariff_category)),
                station_name = COALESCE(VALUES(station_name), station_name),
                station_operator = COALESCE(VALUES(station_operator), station_operator),
                home_meter_kwh = VALUES(home_meter_kwh), home_pv_kwh = VALUES(home_pv_kwh),
                home_grid_kwh = VALUES(home_grid_kwh), loss_kwh = VALUES(loss_kwh),
                loss_pct = VALUES(loss_pct),
                cost_eur = COALESCE(cost_eur, VALUES(cost_eur)),
                receipt_id = COALESCE(receipt_id, VALUES(receipt_id))
        ");

        $stmt->execute([
            ':tronity_charge_id' => $data['tronity_charge_id'],
            ':start_time' => $data['start_time'],
            ':end_time' => $data['end_time'],
            ':duration_min' => $data['duration_min'],
            ':soc_start_pct' => $data['soc_start_pct'],
            ':soc_end_pct' => $data['soc_end_pct'],
            ':delta_soc_pct' => $data['delta_soc_pct'],
            ':charged_net_kwh' => $data['charged_net_kwh'],
            ':avg_charge_power_kw' => $data['avg_charge_power_kw'],
            ':charge_mode' => $data['charge_mode'] ?? null,
            ':lat' => $data['lat'] ?? null,
            ':lon' => $data['lon'] ?? null,
            ':location_type' => $data['location_type'] ?? 'UNKNOWN',
            ':tariff_category' => $data['tariff_category'] ?? null,
            ':station_name' => $data['station_name'] ?? null,
            ':station_operator' => $data['station_operator'] ?? null,
            ':home_meter_kwh' => $data['home_meter_kwh'] ?? null,
            ':home_pv_kwh' => $data['home_pv_kwh'] ?? null,
            ':home_grid_kwh' => $data['home_grid_kwh'] ?? null,
            ':loss_kwh' => $data['loss_kwh'] ?? null,
            ':loss_pct' => $data['loss_pct'] ?? null,
            ':cost_eur' => $data['cost_eur'] ?? null,
            ':receipt_id' => $data['receipt_id'] ?? null,
        ]);

        if ($stmt->rowCount() === 1) { // 1 = INSERT (neu), 2 = UPDATE (bestehend)
            $isHome = ($data['location_type'] ?? 'UNKNOWN') === 'HOME';
            $logger = new \Kai\Tools\Shared\Log\ActivityLogger(Database::getInstance());
            $logger->logCarChargeCaptured(
                (float)$data['charged_net_kwh'], 
                $isHome, 
                $data['cost_eur'] ?? null
            );
        }
    }

    public function getCharge(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                vc.*,
                r.store AS receipt_store,
                r.purchase_date AS receipt_purchase_date,
                r.total AS receipt_total,
                r.file_hash AS receipt_file_hash
            FROM vehicle_charges vc
            LEFT JOIN kb_receipts r ON r.id = vc.receipt_id
            WHERE vc.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    public function getCharges(string $startDate, string $endDate, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                vc.*,
                r.store AS receipt_store,
                r.purchase_date AS receipt_purchase_date,
                r.total AS receipt_total,
                r.file_hash AS receipt_file_hash
            FROM vehicle_charges vc
            LEFT JOIN kb_receipts r ON r.id = vc.receipt_id
            WHERE vc.start_time >= :start AND vc.start_time <= :end 
            ORDER BY vc.start_time DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':start', $startDate, PDO::PARAM_STR);
        $stmt->bindValue(':end', $endDate, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateChargeCost(int $chargeId, float $costEur, ?string $tariffCategory = null): bool
    {
        $stmt = $this->db->prepare("
            UPDATE vehicle_charges SET
                cost_eur = :cost,
                tariff_category = COALESCE(:tariff, tariff_category)
            WHERE id = :id
        ");
        return $stmt->execute([
            ':id' => $chargeId,
            ':cost' => $costEur,
            ':tariff' => $tariffCategory,
        ]);
    }

    public function updateChargeLocation(int $chargeId, ?string $stationName, ?string $stationOperator): bool
    {
        $stmt = $this->db->prepare("
            UPDATE vehicle_charges SET
                station_name = :station_name,
                station_operator = :station_operator
            WHERE id = :id
        ");
        return $stmt->execute([
            ':id' => $chargeId,
            ':station_name' => $stationName,
            ':station_operator' => $stationOperator,
        ]);
    }

    /**
     * Findet Ladevorgänge für Unterwegs mit GPS-Koordinaten, deren Ladestation noch nicht aufgelöst wurde.
     */
    public function getUnresolvedPublicCharges(int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM vehicle_charges
            WHERE location_type != 'HOME'
              AND lat IS NOT NULL 
              AND lon IS NOT NULL
              AND (station_name IS NULL OR station_name = '')
            ORDER BY start_time DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countCharges(string $startDate, string $endDate): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM vehicle_charges WHERE start_time >= :start AND start_time <= :end");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return (int)$stmt->fetchColumn();
    }

    public function getChargeStats(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                SUM(charged_net_kwh) as total_charged,
                SUM(home_pv_kwh) as total_pv,
                SUM(home_grid_kwh) as total_grid,
                SUM(cost_eur) as total_cost,
                COUNT(*) as charge_count,
                AVG(loss_pct) as avg_loss_pct
            FROM vehicle_charges
            WHERE start_time >= :start AND start_time <= :end
        ");
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function getLastChargeId(): ?string
    {
        $stmt = $this->db->query("SELECT tronity_charge_id FROM vehicle_charges ORDER BY start_time DESC LIMIT 1");
        $res = $stmt->fetchColumn();
        return $res !== false ? (string)$res : null;
    }

    public function getLatestChargeTime(): ?string
    {
        $stmt = $this->db->query("SELECT start_time FROM vehicle_charges ORDER BY start_time DESC LIMIT 1");
        $res = $stmt->fetchColumn();
        return $res !== false ? (string)$res : null;
    }
}

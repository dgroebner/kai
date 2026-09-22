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
                home_meter_kwh, home_pv_kwh, home_grid_kwh,
                loss_kwh, loss_pct, cost_eur
            ) VALUES (
                :tronity_charge_id, :start_time, :end_time, :duration_min,
                :soc_start_pct, :soc_end_pct, :delta_soc_pct,
                :charged_net_kwh, :avg_charge_power_kw, :charge_mode,
                :lat, :lon, :location_type, :tariff_category,
                :home_meter_kwh, :home_pv_kwh, :home_grid_kwh,
                :loss_kwh, :loss_pct, :cost_eur
            ) ON DUPLICATE KEY UPDATE
                start_time = VALUES(start_time), end_time = VALUES(end_time),
                duration_min = VALUES(duration_min), soc_start_pct = VALUES(soc_start_pct),
                soc_end_pct = VALUES(soc_end_pct), delta_soc_pct = VALUES(delta_soc_pct),
                charged_net_kwh = VALUES(charged_net_kwh), avg_charge_power_kw = VALUES(avg_charge_power_kw),
                charge_mode = VALUES(charge_mode), location_type = VALUES(location_type),
                tariff_category = VALUES(tariff_category),
                home_meter_kwh = VALUES(home_meter_kwh), home_pv_kwh = VALUES(home_pv_kwh),
                home_grid_kwh = VALUES(home_grid_kwh), loss_kwh = VALUES(loss_kwh),
                loss_pct = VALUES(loss_pct), cost_eur = VALUES(cost_eur)
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
            ':home_meter_kwh' => $data['home_meter_kwh'] ?? null,
            ':home_pv_kwh' => $data['home_pv_kwh'] ?? null,
            ':home_grid_kwh' => $data['home_grid_kwh'] ?? null,
            ':loss_kwh' => $data['loss_kwh'] ?? null,
            ':loss_pct' => $data['loss_pct'] ?? null,
            ':cost_eur' => $data['cost_eur'] ?? null,
        ]);
    }

    public function getCharges(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("SELECT * FROM vehicle_charges ORDER BY start_time DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}

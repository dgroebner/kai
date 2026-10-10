<?php

namespace Kai\Tools\Car;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;
use Throwable;

/**
 * Verwaltet Ladeverträge und Tarife (z. B. EnBW mobility+, Ionity Passport, EWE Go)
 * und berechnet voraussichtliche Ladekosten für Ladevorgänge.
 */
class ChargingTariffService
{
    private PDO $db;
    private Logger $logger;

    public function __construct(?PDO $db = null, ?Logger $logger = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
        $this->logger = $logger ?? new Logger(14);
    }

    /**
     * Liefert alle hinterlegten Ladetarife zurück.
     *
     * @return array<array<string, mixed>>
     */
    public function getAllTariffs(): array
    {
        $stmt = $this->db->query("SELECT * FROM car_charging_tariffs ORDER BY is_default ASC, name ASC");
        $tariffs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($tariffs)) {
            $this->seedInitialTariffs();
            $stmt = $this->db->query("SELECT * FROM car_charging_tariffs ORDER BY is_default ASC, name ASC");
            $tariffs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return $tariffs;
    }

    /**
     * Initialisiert die Standard-Ladetarife des Benutzers.
     */
    public function seedInitialTariffs(): void
    {
        $defaults = [
            [
                'name' => 'Stadtwerke Leipzig L-Charge',
                'operator_match' => 'Stadtwerke Leipzig,L-Charge,Leipziger Gruppe,Leipziger Stadtwerke',
                'price_ac_eur_kwh' => 0.3900,
                'price_dc_eur_kwh' => 0.4900,
                'is_default' => 0,
                'notes' => 'Stromkundentarif der Leipziger Stadtwerke (L-Gruppe)',
            ],
            [
                'name' => 'Vattenfall InCharge',
                'operator_match' => 'Vattenfall,InCharge',
                'price_ac_eur_kwh' => 0.4400,
                'price_dc_eur_kwh' => 0.5900,
                'is_default' => 0,
                'notes' => 'Kostenloser Tarif mit Anmeldung (Vattenfall InCharge Netz)',
            ],
            [
                'name' => 'EnBW mobility+',
                'operator_match' => 'EnBW,mobility+',
                'price_ac_eur_kwh' => 0.5900,
                'price_dc_eur_kwh' => 0.5900,
                'is_default' => 0,
                'notes' => 'Kostenloser Tarif mit Anmeldung (EnBW Ladestationen)',
            ],
            [
                'name' => 'ADAC Tarif für Aral Pulse',
                'operator_match' => 'Aral,pulse,ADAC',
                'price_ac_eur_kwh' => 0.5700,
                'price_dc_eur_kwh' => 0.5700,
                'is_default' => 0,
                'notes' => 'ADAC e-Charge Vorteilskonditionen an Aral pulse Stationen',
            ],
            [
                'name' => 'Standard Roaming',
                'operator_match' => '*',
                'price_ac_eur_kwh' => 0.5900,
                'price_dc_eur_kwh' => 0.6900,
                'is_default' => 1,
                'notes' => 'Standard Roaming-Fallback für sonstige Stationen',
            ],
        ];

        foreach ($defaults as $t) {
            $stmt = $this->db->prepare("
                INSERT INTO car_charging_tariffs (name, operator_match, price_ac_eur_kwh, price_dc_eur_kwh, is_default, notes)
                VALUES (:name, :operator_match, :price_ac, :price_dc, :is_default, :notes)
            ");
            $stmt->execute([
                ':name' => $t['name'],
                ':operator_match' => $t['operator_match'],
                ':price_ac' => $t['price_ac_eur_kwh'],
                ':price_dc' => $t['price_dc_eur_kwh'],
                ':is_default' => $t['is_default'],
                ':notes' => $t['notes'],
            ]);
        }
    }

    /**
     * Setzt die Tarife auf die Standard-Tarife des Benutzers zurück.
     */
    public function resetToDefaultTariffs(): array
    {
        $this->db->exec("TRUNCATE TABLE car_charging_tariffs");
        $this->seedInitialTariffs();
        return $this->getAllTariffs();
    }

    /**
     * Liefert einen einzelnen Tarif anhand der ID.
     */
    public function getTariff(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM car_charging_tariffs WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $tariff = $stmt->fetch(PDO::FETCH_ASSOC);
        return $tariff ?: null;
    }

    /**
     * Speichert einen neuen oder bestehenden Tarif.
     */
    public function saveTariff(array $data): int
    {
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $name = trim((string)($data['name'] ?? ''));
        $operatorMatch = trim((string)($data['operator_match'] ?? ''));
        $priceAc = max(0.0, (float)($data['price_ac_eur_kwh'] ?? 0));
        $priceDc = max(0.0, (float)($data['price_dc_eur_kwh'] ?? 0));
        $blockingFeeAfterMin = !empty($data['blocking_fee_after_min']) ? (int)$data['blocking_fee_after_min'] : null;
        $blockingFeePerMin = !empty($data['blocking_fee_per_min']) ? (float)$data['blocking_fee_per_min'] : null;
        $monthlyFee = max(0.0, (float)($data['monthly_fee_eur'] ?? 0));
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $notes = trim((string)($data['notes'] ?? ''));

        // Falls dieser Tarif Standard sein soll, andere Standard-Flags zurücksetzen
        if ($isDefault === 1) {
            $this->db->exec("UPDATE car_charging_tariffs SET is_default = 0");
        }

        if ($id) {
            $stmt = $this->db->prepare("
                UPDATE car_charging_tariffs SET
                    name = :name,
                    operator_match = :operator_match,
                    price_ac_eur_kwh = :price_ac,
                    price_dc_eur_kwh = :price_dc,
                    blocking_fee_after_min = :blocking_after,
                    blocking_fee_per_min = :blocking_per_min,
                    monthly_fee_eur = :monthly_fee,
                    is_default = :is_default,
                    notes = :notes
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':operator_match' => $operatorMatch,
                ':price_ac' => $priceAc,
                ':price_dc' => $priceDc,
                ':blocking_after' => $blockingFeeAfterMin,
                ':blocking_per_min' => $blockingFeePerMin,
                ':monthly_fee' => $monthlyFee,
                ':is_default' => $isDefault,
                ':notes' => $notes,
            ]);
            return $id;
        }

        $stmt = $this->db->prepare("
            INSERT INTO car_charging_tariffs (
                name, operator_match, price_ac_eur_kwh, price_dc_eur_kwh,
                blocking_fee_after_min, blocking_fee_per_min, monthly_fee_eur,
                is_default, notes
            ) VALUES (
                :name, :operator_match, :price_ac, :price_dc,
                :blocking_after, :blocking_per_min, :monthly_fee,
                :is_default, :notes
            )
        ");
        $stmt->execute([
            ':name' => $name,
            ':operator_match' => $operatorMatch,
            ':price_ac' => $priceAc,
            ':price_dc' => $priceDc,
            ':blocking_after' => $blockingFeeAfterMin,
            ':blocking_per_min' => $blockingFeePerMin,
            ':monthly_fee' => $monthlyFee,
            ':is_default' => $isDefault,
            ':notes' => $notes,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Löscht einen Tarif.
     */
    public function deleteTariff(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM car_charging_tariffs WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Findet den am besten passenden Ladetarif anhand des Betreibernamens.
     */
    public function findMatchingTariff(?string $operator): ?array
    {
        $tariffs = $this->getAllTariffs();
        if (empty($tariffs)) {
            return null;
        }

        if (!empty($operator)) {
            $opLower = mb_strtolower($operator);

            foreach ($tariffs as $t) {
                $patterns = array_filter(array_map('trim', explode(',', $t['operator_match'] ?? '')));
                foreach ($patterns as $pattern) {
                    if ($pattern === '*' || $pattern === '') {
                        continue;
                    }
                    if (str_contains($opLower, mb_strtolower($pattern))) {
                        return $t;
                    }
                }
            }
        }

        // Fallback: Default-Tarif suchen
        foreach ($tariffs as $t) {
            if (!empty($t['is_default'])) {
                return $t;
            }
        }

        return $tariffs[0];
    }

    /**
     * Berechnet die Ladekosten anhand eines Tarifs und der Ladedaten.
     */
    public function calculateCost(array $tariff, float $kwh, string $chargeMode = 'DC', int $durationMin = 0): float
    {
        $isDc = strtoupper($chargeMode) === 'DC';
        $rate = $isDc ? (float)$tariff['price_dc_eur_kwh'] : (float)$tariff['price_ac_eur_kwh'];

        $cost = round($kwh * $rate, 2);

        // Blockiergebühr berechnen falls Ladedauer das Limit übersteigt
        if (!empty($tariff['blocking_fee_after_min']) && !empty($tariff['blocking_fee_per_min'])) {
            $limit = (int)$tariff['blocking_fee_after_min'];
            $feePerMin = (float)$tariff['blocking_fee_per_min'];

            if ($durationMin > $limit && $feePerMin > 0) {
                $extraMin = $durationMin - $limit;
                $cost += round($extraMin * $feePerMin, 2);
            }
        }

        return round($cost, 2);
    }
}

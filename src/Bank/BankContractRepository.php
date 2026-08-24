<?php

namespace Kai\Tools\Bank;

use DateTime;
use Kai\Tools\Shared\Db\Database;
use PDO;

class BankContractRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
    }

    /**
     * Gibt alle Verträge zurück, optional gefiltert nach Status.
     */
    public function getAllContracts(?string $status = null): array
    {
        $sql = "
            SELECT c.*, cat.name AS category_name 
            FROM bank_contracts c
            LEFT JOIN bank_categories cat ON c.category_id = cat.id
        ";

        if ($status !== null) {
            $sql .= " WHERE c.status = :status";
        }

        $sql .= " ORDER BY c.name ASC";

        $stmt = $this->pdo->prepare($sql);
        if ($status !== null) {
            $stmt->execute([':status' => $status]);
        } else {
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt einen einzelnen Vertrag inklusive seiner Regeln.
     */
    public function getContractById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.*, cat.name AS category_name 
            FROM bank_contracts c
            LEFT JOIN bank_categories cat ON c.category_id = cat.id
            WHERE c.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contract) {
            return null;
        }

        // Regeln für diesen Vertrag laden
        $contract['rules'] = $this->getRulesForContract($id);

        return $contract;
    }

    /**
     * Lädt alle Matching-Regeln für einen spezifischen Vertrag.
     */
    public function getRulesForContract(int $contractId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, contract_id, pattern_type, pattern_value, priority
            FROM bank_contract_rules
            WHERE contract_id = :contract_id
            ORDER BY priority DESC, id ASC
        ");
        $stmt->execute([':contract_id' => $contractId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Speichert oder aktualisiert einen Vertrag.
     */
    public function saveContract(array $data, ?int $id = null): int
    {
        // Wenn ein Startdatum und eine Laufzeit (in Monaten) übergeben wurden, Enddatum berechnen falls leer
        if (!empty($data['start_datum']) && empty($data['end_datum']) && !empty($data['laufzeit_monate'])) {
            $startDate = new DateTime($data['start_datum']);
            $startDate->modify('+' . (int)$data['laufzeit_monate'] . ' months');
            $data['end_datum'] = $startDate->format('Y-m-d');
        }

        if ($id !== null && $id > 0) {
            // Update
            $stmt = $this->pdo->prepare("
                UPDATE bank_contracts SET
                    name = :name,
                    type = :type,
                    status = :status,
                    auftraggeber = :auftraggeber,
                    mandatsnummer = :mandatsnummer,
                    iban = :iban,
                    betrag = :betrag,
                    frequenz = :frequenz,
                    variabel = :variabel,
                    start_datum = :start_datum,
                    end_datum = :end_datum,
                    category_id = :category_id,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $data['id'] = $id;
            $stmt->execute($this->mapContractParams($data));
            return $id;
        } else {
            // Insert
            $stmt = $this->pdo->prepare("
                INSERT INTO bank_contracts 
                (name, type, status, auftraggeber, mandatsnummer, iban, betrag, frequenz, variabel, start_datum, end_datum, category_id)
                VALUES 
                (:name, :type, :status, :auftraggeber, :mandatsnummer, :iban, :betrag, :frequenz, :variabel, :start_datum, :end_datum, :category_id)
            ");
            $stmt->execute($this->mapContractParams($data));
            return (int)$this->pdo->lastInsertId();
        }
    }

    private function mapContractParams(array $data): array
    {
        $params = [
            ':name' => $data['name'] ?? '',
            ':type' => $data['type'] ?? 'vertrag',
            ':status' => $data['status'] ?? 'aktiv',
            ':auftraggeber' => $data['auftraggeber'] ?? null,
            ':mandatsnummer' => $data['mandatsnummer'] ?? null,
            ':iban' => $data['iban'] ?? null,
            ':betrag' => (float)($data['betrag'] ?? 0.0),
            ':frequenz' => $data['frequenz'] ?? 'monatlich',
            ':variabel' => isset($data['variabel']) ? (int)$data['variabel'] : 0,
            ':start_datum' => !empty($data['start_datum']) ? $data['start_datum'] : null,
            ':end_datum' => !empty($data['end_datum']) ? $data['end_datum'] : null,
            ':category_id' => !empty($data['category_id']) ? (int)$data['category_id'] : null,
        ];

        if (isset($data['id'])) {
            $params[':id'] = (int)$data['id'];
        }

        return $params;
    }

    /**
     * Fügt eine neue Matching-Regel zu einem Vertrag hinzu.
     */
    public function addRule(int $contractId, string $patternType, string $patternValue, int $priority = 10): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO bank_contract_rules (contract_id, pattern_type, pattern_value, priority)
            VALUES (:contract_id, :pattern_type, :pattern_value, :priority)
        ");
        $stmt->execute([
            ':contract_id' => $contractId,
            ':pattern_type' => $patternType,
            ':pattern_value' => $patternValue,
            ':priority' => $priority
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Löscht eine Matching-Regel.
     */
    public function deleteRule(int $ruleId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM bank_contract_rules WHERE id = :id");
        $stmt->execute([':id' => $ruleId]);
    }

    /**
     * Lädt alle aktiven Regeln inklusive der zugehörigen Vertrags-ID (für den Matcher).
     */
    public function getAllActiveRules(): array
    {
        $stmt = $this->pdo->query("
            SELECT r.*, c.status as contract_status
            FROM bank_contract_rules r
            JOIN bank_contracts c ON r.contract_id = c.id
            WHERE c.status = 'aktiv'
            ORDER BY r.priority DESC, r.id ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lädt eine schlanke Vertragsliste für Auswahlfelder.
     */
    public function getContractOptions(): array
    {
        $stmt = $this->pdo->query("SELECT id, name, type, status FROM bank_contracts ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Löscht einen Vertrag. Die zugehörigen Regeln entfernt der Fremdschlüssel (CASCADE).
     */
    public function deleteContract(int $contractId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM bank_contracts WHERE id = :id");
        $stmt->execute([':id' => $contractId]);
    }

    public function getTransactionsForContract(int $contractId, int $limit): array
    {
        $stmt = $this->pdo->prepare("
            SELECT c.*
            FROM bank_giro_transactions c
            WHERE c.contract_id = :id
            ORDER BY c.booking_date DESC
            LIMIT :limit
        ");
        $stmt->execute([':id' => $contractId, ':limit' => $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
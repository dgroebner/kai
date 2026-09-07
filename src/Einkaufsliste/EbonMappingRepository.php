<?php

namespace Kai\Tools\Einkaufsliste;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Verwaltet die Zuordnungstabelle zwischen rohen eBon-Produktnamen und Master-Artikeln.
 *
 * Ermöglicht es, dass unterschiedliche Kassenbonbezeichnungen (z. B. „Erdb. 500g",
 * „Erdbeeren lose") auf denselben Master-Artikel gemappt werden können.
 */
class EbonMappingRepository
{
    private PDO $pdo;
    private Logger $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
    }

    /**
     * Sucht das Mapping für einen exakten eBon-Rohdatennamen.
     *
     * @param string $ebonName Rohname aus dem Kassenbon
     * @return array<string, mixed>|null Mapping-Zeile inkl. product_master_id oder null
     */
    public function findByEbonName(string $ebonName): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, pm.name AS master_name,
                   COALESCE(NULLIF(pm.custom_label, ''), pm.name) AS master_display_name
            FROM ebon_product_mappings m
            JOIN product_master pm ON m.product_master_id = pm.id
            WHERE LOWER(TRIM(m.ebon_name)) = LOWER(TRIM(:ebon_name))
            LIMIT 1
        ");
        $stmt->execute([':ebon_name' => $ebonName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Gibt alle Mappings eines bestimmten Master-Artikels zurück.
     *
     * @param int $productId ID des Master-Artikels
     * @return array<int, array<string, mixed>>
     */
    public function getByProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, ebon_name, product_master_id, created_at
            FROM ebon_product_mappings
            WHERE product_master_id = :product_id
            ORDER BY ebon_name ASC
        ");
        $stmt->execute([':product_id' => $productId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Gibt alle Mappings zurück (mit JOIN auf product_master für die Verwaltungsansicht).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT m.id, m.ebon_name, m.product_master_id, m.created_at,
                   pm.name AS master_name,
                   COALESCE(NULLIF(pm.custom_label, ''), pm.name) AS master_display_name
            FROM ebon_product_mappings m
            JOIN product_master pm ON m.product_master_id = pm.id
            ORDER BY pm.name ASC, m.ebon_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Zählt alle vorhandenen Mappings.
     */
    public function count(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM ebon_product_mappings");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Legt ein neues Mapping an oder aktualisiert den Ziel-Artikel bei bestehendem eBon-Namen (UPSERT).
     *
     * @param string $ebonName  Rohname aus dem Kassenbon
     * @param int    $productId ID des Master-Artikels
     * @return int ID des Mapping-Eintrags
     */
    public function save(string $ebonName, int $productId): int
    {
        $clean = trim($ebonName);
        if ($clean === '') {
            return 0;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO ebon_product_mappings (ebon_name, product_master_id)
            VALUES (:ebon_name, :product_id)
            ON DUPLICATE KEY UPDATE
                product_master_id = VALUES(product_master_id),
                updated_at        = NOW()
        ");
        $stmt->execute([
            ':ebon_name'  => $clean,
            ':product_id' => $productId,
        ]);

        $newId = (int)$this->pdo->lastInsertId();
        if ($newId === 0) {
            // Bei UPDATE liefert lastInsertId() 0 → vorhandene ID nachschlagen
            $existing = $this->findByEbonName($clean);
            $newId = $existing ? (int)$existing['id'] : 0;
        }

        return $newId;
    }

    /**
     * Löscht ein Mapping anhand seiner ID.
     *
     * @param int $id Mapping-ID
     * @return bool true bei Erfolg
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM ebon_product_mappings WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}

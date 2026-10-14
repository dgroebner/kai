<?php

namespace Kai\Tools\Kassenbon;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Utils\MerchantNormalizer;
use PDO;

class ReceiptRepository
{
    private PDO $db;
    private Logger $logger;

    public function __construct()
    {
        // Holt sich die exakt gleiche Verbindung, die wir vorhin getestet haben
        $this->db = Database::getInstance()->getConnection();
        $this->logger = new Logger(14);
    }

    /**
     * Holt alle bisher bekannten Kategorien aus der Datenbank für den Gemini-Kontext.
     */
    public function getKnownCategories(): array
    {
        try {
            $stmt = $this->db->query("SELECT DISTINCT category FROM kb_items WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
            $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $this->logger->info("ReceiptRepository: " . count($categories) . " bekannte Kategorien geladen.");
            return $categories;
        } catch (Exception $e) {
            $this->logger->error("ReceiptRepository: Fehler beim Laden der Kategorien.", ['error' => $e->getMessage()]);
            return []; // Im Fehlerfall leeres Array zurückgeben, damit der Prozess weiterläuft
        }
    }

    /**
     * Prüft, ob ein Dateihash bereits in der Datenbank existiert.
     */
    public function receiptExists(string $hash): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM kb_receipts WHERE file_hash = :hash");
        $stmt->execute([':hash' => $hash]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Lädt einen Beleg anhand seines Dateihashes.
     */
    public function getReceiptByHash(string $hash): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM kb_receipts WHERE file_hash = :hash LIMIT 1");
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['meta_data']) && is_string($row['meta_data'])) {
            $row['meta'] = json_decode($row['meta_data'], true) ?: [];
        }
        return $row ?: null;
    }

    /**
     * Speichert den Bon. (Signatur um $fileHash erweitert)
     * @throws Exception
     */
    public function saveReceipt(array $data, ?string $fileHash = null): int
    {
        try {
            $normalizedStore = MerchantNormalizer::normalize($data['store'] ?? 'Unbekannt');
            $this->logger->info("ReceiptRepository: Starte Transaktion für Kassenbon von '$normalizedStore'...");
            $this->db->beginTransaction();

            $contractId = $this->findMatchingContract($data, $normalizedStore);
            $documentType = !empty($data['document_type']) ? (string)$data['document_type'] : 'receipt';
            $invoiceNumber = !empty($data['invoice_number']) ? trim((string)$data['invoice_number']) : null;
            $dueDate = !empty($data['due_date']) ? date('Y-m-d', strtotime((string)$data['due_date'])) : null;
            $billingPeriod = !empty($data['billing_period']) ? trim((string)$data['billing_period']) : null;

            $meta = [];
            if (!empty($data['phone_profiles'])) $meta['phone_profiles'] = $data['phone_profiles'];
            if (!empty($data['mandate_reference'])) $meta['mandate_reference'] = $data['mandate_reference'];
            if (!empty($data['customer_number'])) $meta['customer_number'] = $data['customer_number'];
            if (!empty($data['customer_account'])) $meta['customer_account'] = $data['customer_account'];
            if (isset($data['tax_amount'])) $meta['tax_amount'] = (float)$data['tax_amount'];
            if (isset($data['net_amount'])) $meta['net_amount'] = (float)$data['net_amount'];
            $metaJson = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null;

            // 1. Metadaten in kb_receipts speichern
            $stmtReceipt = $this->db->prepare("
				INSERT INTO kb_receipts (
				    file_hash, store, purchase_date, total,
				    contract_id, invoice_number, due_date, billing_period, document_type, meta_data
				) 
				VALUES (
				    :hash, :store, :date, :total,
				    :contract_id, :invoice_number, :due_date, :billing_period, :document_type, :meta_data
				)
			");

            $stmtReceipt->execute([
                ':hash' => $fileHash,
                ':store' => $normalizedStore,
                ':date' => $data['date'] ?? date('Y-m-d'),
                ':total' => $data['total'] ?? 0.00,
                ':contract_id' => $contractId,
                ':invoice_number' => $invoiceNumber,
                ':due_date' => $dueDate,
                ':billing_period' => $billingPeriod,
                ':document_type' => $documentType,
                ':meta_data' => $metaJson
            ]);

            $receiptId = $this->db->lastInsertId();

            // 2. Positionen in kb_items speichern
            $stmtItem = $this->db->prepare("
                INSERT INTO kb_items (receipt_id, name, quantity, unit_price, total_price, category) 
                VALUES (:receipt_id, :name, :quantity, :unit_price, :total_price, :category)
            ");

            $itemCount = 0;
            foreach ($data['items'] as $item) {
                // Typ-Sicherheit: Falls die KI Preise mal als String schickt, normalisieren wir sie
                $quantity = (float)($item['quantity'] ?? 1.000);
                $unitPrice = (float)($item['unit_price'] ?? 0.00);
                $totalPrice = (float)($item['total_price'] ?? 0.00);

                $stmtItem->execute([
                    ':receipt_id' => $receiptId,
                    ':name' => $item['name'] ?? 'Unbekannt',
                    ':quantity' => $quantity,
                    ':unit_price' => $unitPrice,
                    ':total_price' => $totalPrice,
                    ':category' => $item['category'] ?? 'Sonstiges'
                ]);
                $itemCount++;
            }

            // 3. Transaktion abschließen
            $this->db->commit();
            $this->logger->info("ReceiptRepository: Bon erfolgreich gespeichert. ID: $receiptId mit $itemCount Positionen.");

            return $receiptId;

        } catch (Exception $e) {
            // Im Fehlerfall alles verwerfen!
            $this->db->rollBack();
            $this->logger->error("ReceiptRepository: Transaktion fehlgeschlagen! Rollback ausgeführt.", ['error' => $e->getMessage()]);
            throw new Exception("Kassenbon konnte nicht in der Datenbank gespeichert werden.");
        }
    }

    /**
     * Sucht nach der zuletzt verwendeten Kategorie für einen bestimmten Artikelnamen.
     */
    public function getKnownCategoryForProduct(string $productName): ?string
    {
        $stmt = $this->db->prepare("
            SELECT category 
            FROM kb_items 
            WHERE name = :name AND category != '' AND category IS NOT NULL
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([':name' => $productName]);
        $result = $stmt->fetchColumn();

        return $result ?: null;
    }

    /**
     * Gibt für eine Liste von Artikelnamen die jeweils zuletzt verwendete Kategorie zurück.
     * Vermeidet N+1-Queries durch eine einzige IN-Abfrage.
     *
     * @param string[] $productNames
     * @return array<string, string> Map von Artikelname => Kategorie
     */
    public function getKnownCategoriesForProducts(array $productNames): array
    {
        if (empty($productNames)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productNames), '?'));
        $stmt = $this->db->prepare("
            SELECT name, category
            FROM kb_items
            WHERE name IN ($placeholders)
              AND category != ''
              AND category IS NOT NULL
            ORDER BY id DESC
        ");
        $stmt->execute($productNames);
        $rows = $stmt->fetchAll();

        // Nur den ersten (neuesten) Treffer pro Artikelname behalten
        $result = [];
        foreach ($rows as $row) {
            if (!isset($result[$row['name']])) {
                $result[$row['name']] = $row['category'];
            }
        }
        return $result;
    }

    /**
     * Ändert die Kategorie einer einzelnen Bon-Position.
     */
    public function updateItemCategory(int $itemId, string $categoryName): void
    {
        $stmt = $this->db->prepare("UPDATE kb_items SET category = :cat WHERE id = :id");
        $stmt->execute([':cat' => $categoryName, ':id' => $itemId]);
    }

    /**
     * Hebt die Verknüpfung zwischen einem Beleg und einem Vertrag auf.
     */
    public function unlinkContract(int $receiptId): void
    {
        $stmt = $this->db->prepare("UPDATE kb_receipts SET contract_id = NULL WHERE id = :id");
        $stmt->execute([':id' => $receiptId]);
        $this->logger->info("ReceiptRepository: Beleg #$receiptId von Vertrag getrennt.");
    }

    /**
     * Versucht, anhand von Mandatsreferenz, Händlername oder Vertragsregeln
     * einen passenden aktiven Vertrag aus bank_contracts zu finden.
     */
    public function findMatchingContract(array $data, ?string $normalizedStore = null): ?int
    {
        $normalizedStore = !empty($normalizedStore)
            ? trim($normalizedStore)
            : MerchantNormalizer::normalize($data['store'] ?? '');

        if (empty($data['meta']) && !empty($data['meta_data'])) {
            $data['meta'] = is_string($data['meta_data']) ? json_decode($data['meta_data'], true) : (array)$data['meta_data'];
        }

        try {
            $receiptId = !empty($data['id']) ? (int)$data['id'] : null;

            // 0. Erkennung Ladevorgang / Mobilität (E-Auto laden)
            $docType = $data['document_type'] ?? '';
            $isCharging = ($docType === 'charging_invoice')
                || (!empty($data['category']) && (stripos($data['category'], 'laden') !== false || stripos($data['category'], 'e-auto') !== false))
                || !empty($data['vehicle_charge_id']);

            if (!$isCharging && !empty($data['items']) && is_array($data['items'])) {
                $isCharging = array_any($data['items'], fn($it) =>
                    (bool)preg_match('/(ladevorgang|ladepunkt|ladekarte|ladetarif|\bkwh\b)/i', (string)($it['name'] ?? ''))
                    || stripos((string)($it['category'] ?? ''), 'laden') !== false
                    || stripos((string)($it['category'] ?? ''), 'e-auto') !== false
                );
            }

            if (!$isCharging && $receiptId !== null) {
                $stmtChargeCheck = $this->db->prepare("
                    SELECT 1 FROM kb_receipts r
                    WHERE r.id = :id AND (
                        r.vehicle_charge_id IS NOT NULL
                        OR EXISTS (SELECT 1 FROM vehicle_charges vc WHERE vc.receipt_id = r.id)
                        OR EXISTS (
                            SELECT 1 FROM kb_items ki 
                            WHERE ki.receipt_id = r.id 
                              AND (ki.category = 'E-Auto laden' OR ki.name LIKE '%Ladevorgang%' OR ki.name LIKE '%kWh%')
                        )
                    )
                    LIMIT 1
                ");
                $stmtChargeCheck->execute([':id' => $receiptId]);
                $isCharging = (bool)$stmtChargeCheck->fetchColumn();
            }

            // Falls bereits mit einer Giro-Transaktion verknüpft:
            // 1) Wenn die Transaktion bereits einen Vertrag hat, diesen übernehmen
            // 2) Mandatsnummer aus der Buchung als Fallback heranziehen
            $giroTxId = !empty($data['bank_giro_transaction_id']) ? (int)$data['bank_giro_transaction_id'] : null;
            $giroContractId = null;
            $giroMandate = null;
            if ($giroTxId !== null) {
                $stmtTx = $this->db->prepare("SELECT contract_id, dc_mandate_id FROM bank_giro_transactions WHERE id = :tx_id LIMIT 1");
                $stmtTx->execute([':tx_id' => $giroTxId]);
                $txRow = $stmtTx->fetch(PDO::FETCH_ASSOC);
                if ($txRow) {
                    $giroContractId = !empty($txRow['contract_id']) ? (int)$txRow['contract_id'] : null;
                    $giroMandate = trim((string)($txRow['dc_mandate_id'] ?? ''));
                }
            }

            if ($giroContractId !== null) {
                return $giroContractId;
            }

            // 1. Mandatsreferenz (höchste Genauigkeit) - Leerzeichen bereinigen
            $mandateRaw = trim((string)(
                $data['mandate_reference']
                ?? ($data['meta']['mandate_reference'] ?? '')
            ));
            if ($mandateRaw === '' && !empty($giroMandate)) {
                $mandateRaw = $giroMandate;
            }

            $mandateClean = $mandateRaw !== '' ? preg_replace('/\s+/', '', $mandateRaw) : '';

            if ($mandateClean !== '') {
                $stmt = $this->db->prepare("
                    SELECT id FROM bank_contracts 
                    WHERE (status = 'aktiv' OR status IS NULL)
                      AND mandatsnummer IS NOT NULL 
                      AND mandatsnummer != '' 
                      AND (
                          REPLACE(mandatsnummer, ' ', '') = :mandate_clean1
                          OR :mandate_clean2 LIKE CONCAT('%', REPLACE(mandatsnummer, ' ', ''), '%')
                          OR REPLACE(mandatsnummer, ' ', '') LIKE CONCAT('%', :mandate_short, '%')
                      )
                    LIMIT 1
                ");
                $stmt->execute([
                    ':mandate_clean1' => $mandateClean,
                    ':mandate_clean2' => $mandateClean,
                    ':mandate_short' => substr($mandateClean, 0, 16)
                ]);
                $cid = $stmt->fetchColumn();
                if ($cid) {
                    $this->logger->info("ReceiptRepository: Vertrag #$cid anhand Mandatsreferenz '$mandateClean' gefunden.");
                    return (int)$cid;
                }
            }

            // 2. Dokumenttyp / Kontext prüfen (Mobilfunk vs. Festnetz unterscheiden)
            $isMobile = ($docType === 'mobile_bill')
                || !empty($data['phone_profiles'])
                || !empty($data['meta']['phone_profiles'])
                || stripos((string)($data['billing_period'] ?? ''), 'mobil') !== false
                || stripos((string)($data['store'] ?? ''), 'mobil') !== false
                || (!empty($data['items']) && is_array($data['items']) && array_any($data['items'], fn($it) => (bool)preg_match('/(magentamobil|pluskarte|mobilfunk)/i', (string)($it['name'] ?? '')))) ? 1 : 0;

            $isLandline = ($docType === 'landline_bill')
                || stripos((string)($data['billing_period'] ?? ''), 'festnetz') !== false
                || stripos((string)($data['store'] ?? ''), 'festnetz') !== false
                || (!empty($data['items']) && is_array($data['items']) && array_any($data['items'], fn($it) => (bool)preg_match('/(magentazuhause|festnetz|dsl|glasfaser)/i', (string)($it['name'] ?? '')))) ? 1 : 0;

            // 3. bank_contract_rules prüfen (vor heuristischer Partner-Suche)
            $stmtRules = $this->db->prepare("
                SELECT r.contract_id, r.pattern_type, r.pattern_value, c.name as contract_name, c.auftraggeber
                FROM bank_contract_rules r
                JOIN bank_contracts c ON r.contract_id = c.id
                WHERE (c.status = 'aktiv' OR c.status IS NULL)
                  AND (
                      :is_charging_r = 0
                      OR (
                          c.name LIKE '%lade%' OR c.name LIKE '%charge%' OR c.name LIKE '%auto%' OR c.name LIKE '%mobil%'
                          OR c.auftraggeber LIKE '%lade%' OR c.auftraggeber LIKE '%charge%'
                      )
                  )
                ORDER BY 
                  CASE 
                    WHEN :is_mobile_prio_r = 1 AND (c.name LIKE '%mobil%' OR c.name LIKE '%funk%') THEN 1
                    WHEN :is_landline_prio_r = 1 AND (c.name LIKE '%fest%' OR c.name LIKE '%dsl%') THEN 1
                    WHEN :is_mobile_penalty_r = 1 AND (c.name LIKE '%fest%' OR c.name LIKE '%dsl%') THEN 3
                    ELSE 2
                  END ASC,
                  r.priority DESC
            ");
            $stmtRules->execute([
                ':is_charging_r' => $isCharging ? 1 : 0,
                ':is_mobile_prio_r' => $isMobile,
                ':is_landline_prio_r' => $isLandline,
                ':is_mobile_penalty_r' => $isMobile
            ]);
            $rules = $stmtRules->fetchAll(PDO::FETCH_ASSOC);
            $searchSpace = strtolower($normalizedStore . ' ' . $mandateRaw . ' ' . ($data['invoice_number'] ?? ''));

            foreach ($rules as $rule) {
                $val = strtolower(trim((string)$rule['pattern_value']));
                if ($val === '') {
                    continue;
                }
                if ($rule['pattern_type'] === 'substring' && str_contains($searchSpace, $val)) {
                    $cid = (int)$rule['contract_id'];
                    $this->logger->info("ReceiptRepository: Vertrag #$cid über Regel '$val' gefunden.");
                    return $cid;
                }
                if ($rule['pattern_type'] === 'exact_match' && strtolower($normalizedStore) === $val) {
                    $cid = (int)$rule['contract_id'];
                    $this->logger->info("ReceiptRepository: Vertrag #$cid über Exact-Match '$val' gefunden.");
                    return $cid;
                }
                if ($rule['pattern_type'] === 'regex') {
                    $regex = '/' . trim($rule['pattern_value'], '/') . '/i';
                    if (@preg_match($regex, $searchSpace)) {
                        $cid = (int)$rule['contract_id'];
                        $this->logger->info("ReceiptRepository: Vertrag #$cid über Regex gefunden.");
                        return $cid;
                    }
                }
            }

            // 4. Auftraggeber / Name des Vertrags (Heuristischer Fallback)
            // Beachte:
            // a) Wenn es ein Ladevorgang ist, dürfen reine Haushaltsstrom-/Energieverträge nicht matchen.
            // b) Wenn der Beleg eine Mandatsnummer hat, dürfen Verträge mit abweichender Mandatsnummer nicht matchen.
            $stmt = $this->db->prepare("
                SELECT id FROM bank_contracts 
                WHERE (status = 'aktiv' OR status IS NULL)
                  AND (
                      auftraggeber LIKE :store_like1 
                      OR name LIKE :store_like2
                      OR (auftraggeber IS NOT NULL AND :store_clean LIKE CONCAT('%', auftraggeber, '%'))
                  )
                  AND (
                      :is_charging = 0
                      OR (
                          name LIKE '%lade%' OR name LIKE '%charge%' OR name LIKE '%auto%' OR name LIKE '%mobil%'
                          OR auftraggeber LIKE '%lade%' OR auftraggeber LIKE '%charge%'
                      )
                  )
                  AND (
                      :has_mandate = 0
                      OR mandatsnummer IS NULL
                      OR mandatsnummer = ''
                      OR REPLACE(mandatsnummer, ' ', '') = :mandate_clean
                  )
                ORDER BY
                  CASE 
                    WHEN :is_mobile_prio = 1 AND (name LIKE '%mobil%' OR name LIKE '%funk%') THEN 1
                    WHEN :is_landline_prio = 1 AND (name LIKE '%fest%' OR name LIKE '%dsl%' OR name LIKE '%internet%') THEN 1
                    WHEN :is_mobile_penalty = 1 AND (name LIKE '%fest%' OR name LIKE '%dsl%') THEN 3
                    ELSE 2
                  END ASC,
                  id ASC
                LIMIT 1
            ");
            $stmt->execute([
                ':store_like1' => '%' . $normalizedStore . '%',
                ':store_like2' => '%' . $normalizedStore . '%',
                ':store_clean' => $normalizedStore,
                ':is_charging' => $isCharging ? 1 : 0,
                ':has_mandate' => $mandateClean !== '' ? 1 : 0,
                ':mandate_clean' => $mandateClean,
                ':is_mobile_prio' => $isMobile,
                ':is_landline_prio' => $isLandline,
                ':is_mobile_penalty' => $isMobile
            ]);
            $cid = $stmt->fetchColumn();
            if ($cid) {
                $this->logger->info("ReceiptRepository: Vertrag #$cid anhand Partner/Name '$normalizedStore' (isMobile=$isMobile, isCharging=" . ($isCharging ? 1 : 0) . ") gefunden.");
                return (int)$cid;
            }
        } catch (\Throwable $e) {
            $this->logger->warn("ReceiptRepository: Vertragsabgleich übersprungen (" . $e->getMessage() . ")");
        }

        return null;
    }
}
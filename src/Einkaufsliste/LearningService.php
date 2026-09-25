<?php

namespace Kai\Tools\Einkaufsliste;

use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Analysiert historische eBons (kb_receipts & kb_items) und ermittelt
 * Marktpräferenzen (Rewe vs. Globus) sowie Verbrauchsintervalle für den Artikelstamm.
 *
 * Nutzt EbonMappingRepository, um rohe Kassenbonnamen auf Master-Artikel
 * abzubilden (Phase 1: Entkopplung eBon-Rohdaten ↔ Artikelstamm).
 */
class LearningService
{
    private PDO $pdo;
    private Logger $logger;
    private ProductMasterRepository $productRepo;
    private EbonMappingRepository $mappingRepo;

    public function __construct(
        ?ProductMasterRepository $productRepo = null,
        ?EbonMappingRepository   $mappingRepo = null
    ) {
        $this->pdo         = Database::getInstance()->getConnection();
        $this->logger      = new Logger();
        $this->productRepo = $productRepo ?? new ProductMasterRepository();
        $this->mappingRepo = $mappingRepo ?? new EbonMappingRepository();
    }

    /**
     * Liest alle historischen Kassenbon-Positionen aus und lernt Marktzuordnungen,
     * Kategorien und durchschnittliche Kaufintervalle.
     *
     * Ablauf pro eBon-Rohname:
     * 1. Existiert ein Mapping ebon_name → product_master_id? → Master-Artikel aktualisieren.
     * 2. Kein Mapping: product_master per Name suchen / anlegen, danach Mapping persistieren.
     *
     * @return array{
     *     total_items_analyzed: int,
     *     unique_products: int,
     *     products_updated: int
     * }
     */
    public function learnFromReceipts(): array
    {
        $this->logger->info("LearningService: Starte Analyse historischer eBons...");

        // 1. Alle Kassenbon-Positionen chronologisch laden
        $stmt = $this->pdo->query("
            SELECT 
                i.name,
                i.category,
                r.store,
                r.purchase_date
            FROM kb_items i
            JOIN kb_receipts r ON i.receipt_id = r.id
            WHERE i.name IS NOT NULL AND TRIM(i.name) != ''
            ORDER BY r.purchase_date ASC, i.id ASC
        ");

        $rows       = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalItems = count($rows);

        if ($totalItems === 0) {
            $this->logger->info("LearningService: Keine eBons in kb_receipts/kb_items vorhanden.");
            return [
                'total_items_analyzed' => 0,
                'unique_products'      => 0,
                'products_updated'     => 0,
                'auto_mapped'          => 0,
            ];
        }

        // 2. Alle bestehenden Master-Artikel laden
        $mastersStmt = $this->pdo->query("
            SELECT id, name, custom_label, avg_interval_days, last_purchased_at, preferred_market, default_category
            FROM product_master
        ");
        $masterProducts = [];
        $masterByName = [];
        foreach ($mastersStmt->fetchAll(PDO::FETCH_ASSOC) as $mp) {
            $mId = (int)$mp['id'];
            $masterProducts[$mId] = $mp;
            $masterByName[mb_strtolower(trim($mp['name']), 'UTF-8')] = $mId;
            if (!empty($mp['custom_label'])) {
                $masterByName[mb_strtolower(trim($mp['custom_label']), 'UTF-8')] = $mId;
            }
        }

        // 3. Alle bestehenden Mappings laden
        $mappingsStmt = $this->pdo->query("SELECT ebon_name, product_master_id FROM ebon_product_mappings");
        $mappings = [];
        foreach ($mappingsStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $norm = mb_strtolower(trim($m['ebon_name']), 'UTF-8');
            $mappings[$norm] = $m['product_master_id'] !== null ? (int)$m['product_master_id'] : null;
        }

        $insertMappingStmt = $this->pdo->prepare("
            INSERT INTO ebon_product_mappings (ebon_name, product_master_id)
            VALUES (:ebon_name, :product_master_id)
            ON DUPLICATE KEY UPDATE product_master_id = VALUES(product_master_id)
        ");

        $autoMappedCount = 0;
        $uniqueRawNames = [];
        $masterData = [];

        // 4. Positionen nach Master-Artikel ID aggregieren
        foreach ($rows as $row) {
            $rawName = trim($row['name']);
            $normRaw = mb_strtolower($rawName, 'UTF-8');
            $uniqueRawNames[$normRaw] = true;

            $targetMasterId = null;

            // Schritt A: Vorhandenes Mapping prüfen
            if (array_key_exists($normRaw, $mappings)) {
                $targetMasterId = $mappings[$normRaw];
                // Wenn targetMasterId === null -> vom Nutzer explizit ignoriert (Pfand, Rabatt etc.)
                if ($targetMasterId === null) {
                    continue;
                }
            } else {
                // Schritt B: Typische Nicht-Produkte (Rabatte, Pfand) erkennen und ignorieren
                if ($this->isNonProduct($rawName)) {
                    $insertMappingStmt->execute([':ebon_name' => $rawName, ':product_master_id' => null]);
                    $mappings[$normRaw] = null;
                    continue;
                }

                // Schritt C: Exakter Treffer auf bestehenden Master-Artikel
                if (isset($masterByName[$normRaw])) {
                    $targetMasterId = $masterByName[$normRaw];
                    $insertMappingStmt->execute([':ebon_name' => $rawName, ':product_master_id' => $targetMasterId]);
                    $mappings[$normRaw] = $targetMasterId;
                    $autoMappedCount++;
                } else {
                    // Schritt D: Supermarkt-Präfixe bereinigen (z.B. "JA! BANANEN", "REWE BIO BUTTER", "GLOBUS MILCH")
                    $cleanPrefix = preg_replace('/^(ja!\s*|rewe\s*bio\s*|rewe\s*|globus\s*|bio\s*|k-classic\s*|gut&günstig\s*)/iu', '', $normRaw);
                    $cleanPrefix = trim($cleanPrefix ?? '');
                    if ($cleanPrefix !== '' && $cleanPrefix !== $normRaw && isset($masterByName[$cleanPrefix])) {
                        $targetMasterId = $masterByName[$cleanPrefix];
                        $insertMappingStmt->execute([':ebon_name' => $rawName, ':product_master_id' => $targetMasterId]);
                        $mappings[$normRaw] = $targetMasterId;
                        $autoMappedCount++;
                    }
                }
            }

            // Wenn noch kein Master-Artikel existiert: verbleibt in der Inbox
            if ($targetMasterId === null || !isset($masterProducts[$targetMasterId])) {
                continue;
            }

            // Daten auf Master-Artikel-Ebene sammeln
            if (!isset($masterData[$targetMasterId])) {
                $masterData[$targetMasterId] = [
                    'stores'     => ['Rewe' => 0, 'Globus' => 0, 'Other' => 0],
                    'categories' => [],
                    'dates'      => [],
                ];
            }

            // Händler zählen
            $storeLower = mb_strtolower($row['store'] ?? '', 'UTF-8');
            if (str_contains($storeLower, 'globus')) {
                $masterData[$targetMasterId]['stores']['Globus']++;
            } elseif (str_contains($storeLower, 'rewe')) {
                $masterData[$targetMasterId]['stores']['Rewe']++;
            } else {
                $masterData[$targetMasterId]['stores']['Other']++;
            }

            // Kategorie zählen
            $cat = trim($row['category'] ?? '');
            if ($cat !== '') {
                $masterData[$targetMasterId]['categories'][$cat] = ($masterData[$targetMasterId]['categories'][$cat] ?? 0) + 1;
            }

            // Kaufdatum sammeln
            $date = $row['purchase_date'] ?? null;
            if ($date && !in_array($date, $masterData[$targetMasterId]['dates'], true)) {
                $masterData[$targetMasterId]['dates'][] = $date;
            }
        }

        // 5. Stammdaten für jeden gemappten Master-Artikel fortschreiben
        $updatedCount = 0;
        $updateStmt = $this->pdo->prepare("
            UPDATE product_master SET
                preferred_market = COALESCE(:preferred_market, preferred_market),
                default_category = COALESCE(:default_category, default_category),
                avg_interval_days = COALESCE(:avg_interval_days, avg_interval_days),
                last_purchased_at = :last_purchased_at,
                updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($masterData as $masterId => $data) {
            $master = $masterProducts[$masterId];

            // Bevorzugter Markt
            if ($data['stores']['Globus'] > $data['stores']['Rewe']) {
                $preferredMarket = 'Globus';
            } elseif ($data['stores']['Rewe'] > $data['stores']['Globus']) {
                $preferredMarket = 'Rewe';
            } else {
                $preferredMarket = $master['preferred_market'] ?? 'Übergreifend';
            }

            // Häufigste Kategorie ermitteln
            $dominantCategory = $master['default_category'] ?? null;
            if (!empty($data['categories'])) {
                arsort($data['categories']);
                $dominantCategory = array_key_first($data['categories']);
            }

            // Kaufdaten sortieren
            $dates = $data['dates'];
            sort($dates);
            $lastPurchased = !empty($dates) ? end($dates) : $master['last_purchased_at'];

            // Durchschnittliches Kaufintervall in Tagen berechnen
            $avgInterval = $master['avg_interval_days'] !== null ? (float)$master['avg_interval_days'] : null;
            if (count($dates) >= 2) {
                $diffs = [];
                for ($i = 1; $i < count($dates); $i++) {
                    $diffDays = (strtotime($dates[$i]) - strtotime($dates[$i - 1])) / 86400;
                    if ($diffDays > 0 && $diffDays < 180) { // Ausreißer über 6 Monate ignorieren
                        $diffs[] = $diffDays;
                    }
                }
                if (!empty($diffs)) {
                    $avgInterval = round(array_sum($diffs) / count($diffs), 1);
                }
            }

            $updateStmt->execute([
                ':preferred_market'  => $preferredMarket,
                ':default_category'  => $dominantCategory,
                ':avg_interval_days' => $avgInterval,
                ':last_purchased_at' => $lastPurchased,
                ':id'                => $masterId,
            ]);
            $updatedCount++;
        }

        $this->logger->info("LearningService: eBon-Analyse abgeschlossen.", [
            'total_items'     => $totalItems,
            'unique_products' => count($uniqueRawNames),
            'updated'         => $updatedCount,
            'auto_mapped'     => $autoMappedCount,
        ]);

        return [
            'total_items_analyzed' => $totalItems,
            'unique_products'      => count($uniqueRawNames),
            'products_updated'     => $updatedCount,
            'auto_mapped'          => $autoMappedCount,
        ];
    }

    /**
     * Ermittelt alle Kassenbon-Positionen, die noch keinem Master-Artikel zugeordnet sind (Inbox).
     *
     * @return array<int, array{name: string, first_seen: string, last_seen: string, count: int, dominant_category: string}>
     */
    public function getInboxItems(): array
    {
        // Alle Kassenbon-Namen, die nicht in ebon_product_mappings stehen.
        // Wir aggregieren gleich die Metadaten.
        $stmt = $this->pdo->query("
            SELECT 
                i.name,
                MIN(r.purchase_date) AS first_seen,
                MAX(r.purchase_date) AS last_seen,
                COUNT(i.id) AS `count`,
                i.category
            FROM kb_items i
            JOIN kb_receipts r ON i.receipt_id = r.id
            WHERE i.name NOT IN (SELECT ebon_name FROM ebon_product_mappings)
              AND i.name IS NOT NULL 
              AND TRIM(i.name) != ''
            GROUP BY i.name, i.category
            ORDER BY `count` DESC, i.name ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Nach Namen gruppieren und dominante Kategorie finden
        $inbox = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            if (!isset($inbox[$name])) {
                $inbox[$name] = [
                    'name'       => $name,
                    'first_seen' => $row['first_seen'],
                    'last_seen'  => $row['last_seen'],
                    'count'      => 0,
                    'categories' => []
                ];
            }

            $inbox[$name]['count'] += $row['count'];
            
            // Ältestes Datum
            if ($row['first_seen'] < $inbox[$name]['first_seen']) {
                $inbox[$name]['first_seen'] = $row['first_seen'];
            }
            // Neuestes Datum
            if ($row['last_seen'] > $inbox[$name]['last_seen']) {
                $inbox[$name]['last_seen'] = $row['last_seen'];
            }
            
            if ($row['category']) {
                $inbox[$name]['categories'][$row['category']] = ($inbox[$name]['categories'][$row['category']] ?? 0) + $row['count'];
            }
        }

        // Dominante Kategorie ermitteln und bereinigen
        $result = [];
        foreach ($inbox as $name => $data) {
            $dominant = 'Sonstiges';
            if (!empty($data['categories'])) {
                arsort($data['categories']);
                $dominant = array_key_first($data['categories']);
            }
            $data['dominant_category'] = \Kai\Tools\Einkaufsliste\CategoryIconHelper::getIcon($dominant) . ' ' . $dominant;
            
            // isNonProduct check (Rabatt, etc.) um sie im UI markieren zu können
            $data['is_likely_non_product'] = $this->isNonProduct($name);
            
            unset($data['categories']);
            $result[] = $data;
        }

        // Sortierung: Häufigkeit absteigend, dann Name
        usort($result, function($a, $b) {
            if ($a['count'] === $b['count']) {
                return strcmp($a['name'], $b['name']);
            }
            return $b['count'] <=> $a['count'];
        });

        return $result;
    }

    /**
     * Prüft, ob ein Kassenbon-Eintrag typischerweise kein kaufbarer Artikel ist
     * (z. B. Rabatte, Aktionen, Pfand, Leergut).
     */
    private function isNonProduct(string $name): bool
    {
        $lower    = mb_strtolower($name, 'UTF-8');
        $keywords = [
            'rabatt', 'coupon', 'aktionsnachlass', 'gutschein', 'treuepunkt',
            'bonus', 'ersparnis', 'nachlass', 'leergut', 'pfand', 'rückgabe',
            'spende', 'aufrund',
        ];

        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }
}

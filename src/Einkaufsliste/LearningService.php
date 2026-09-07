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

        // Alle Bon-Positionen chronologisch laden
        $stmt = $this->pdo->query("
            SELECT 
                i.name,
                i.category,
                r.store,
                r.purchase_date
            FROM kb_items i
            JOIN kb_receipts r ON i.receipt_id = r.id
            WHERE i.name IS NOT NULL AND TRIM(i.name) != ''
            ORDER BY i.name ASC, r.purchase_date ASC
        ");

        $rows       = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalItems = count($rows);

        if ($totalItems === 0) {
            $this->logger->info("LearningService: Keine eBons in kb_receipts/kb_items vorhanden.");
            return [
                'total_items_analyzed' => 0,
                'unique_products'      => 0,
                'products_updated'     => 0,
            ];
        }

        // Gruppierung nach normalisiertem eBon-Rohdatennamen
        $products = [];
        foreach ($rows as $row) {
            $rawName = trim($row['name']);
            $normKey = mb_strtolower($rawName, 'UTF-8');

            if (!isset($products[$normKey])) {
                $products[$normKey] = [
                    'canonical_name' => $rawName,
                    'stores'         => ['Rewe' => 0, 'Globus' => 0, 'Other' => 0],
                    'categories'     => [],
                    'dates'          => [],
                ];
            }

            // Händler zuordnen (Zwei-Märkte-Strategie: Rewe vs. Globus)
            $storeLower = mb_strtolower($row['store'] ?? '', 'UTF-8');
            if (str_contains($storeLower, 'globus')) {
                $products[$normKey]['stores']['Globus']++;
            } elseif (str_contains($storeLower, 'rewe')) {
                $products[$normKey]['stores']['Rewe']++;
            } else {
                $products[$normKey]['stores']['Other']++;
            }

            // Kategorie zählen
            $cat = trim($row['category'] ?? '');
            if ($cat !== '') {
                $products[$normKey]['categories'][$cat] = ($products[$normKey]['categories'][$cat] ?? 0) + 1;
            }

            // Kaufdatum sammeln
            $date = $row['purchase_date'] ?? null;
            if ($date && !in_array($date, $products[$normKey]['dates'], true)) {
                $products[$normKey]['dates'][] = $date;
            }
        }

        $updatedCount = 0;

        foreach ($products as $normKey => $data) {
            $rawName = $data['canonical_name'];

            // Bevorzugter Markt: Globus wenn Globus-Häufigkeit höher, sonst Standard Rewe
            $preferredMarket = $data['stores']['Globus'] > $data['stores']['Rewe'] ? 'Globus' : 'Rewe';

            // Häufigste Kategorie ermitteln
            $dominantCategory = null;
            if (!empty($data['categories'])) {
                arsort($data['categories']);
                $dominantCategory = array_key_first($data['categories']);
            }

            // Kaufdaten sortieren
            $dates         = $data['dates'];
            sort($dates);
            $lastPurchased = end($dates);

            // Durchschnittliches Kaufintervall in Tagen berechnen
            $avgInterval = null;
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

            $updateData = [
                'preferred_market'  => $preferredMarket,
                'default_category'  => $dominantCategory,
                'default_unit'      => 'Stück',
                'avg_interval_days' => $avgInterval,
                'last_purchased_at' => $lastPurchased,
            ];

            // --- Mapping-Schicht prüfen ---
            $mapping = $this->mappingRepo->findByEbonName($rawName);

            if ($mapping !== null) {
                // Vorhandenes Mapping → zugehörigen Master-Artikel aktualisieren
                $this->productRepo->saveOrUpdate(
                    array_merge($updateData, ['name' => $mapping['master_name']])
                );
                $updatedCount++;
            }
            // ELSE: Phase 1.5 - Unbekannte Artikel werden NICHT mehr blind als neuer
            // Master-Artikel angelegt. Sie verbleiben als ungemappte kb_items in der Inbox.
        }

        $this->logger->info("LearningService: eBon-Analyse abgeschlossen.", [
            'total_items'     => $totalItems,
            'unique_products' => count($products),
            'updated'         => $updatedCount,
        ]);

        return [
            'total_items_analyzed' => $totalItems,
            'unique_products'      => count($products),
            'products_updated'     => $updatedCount,
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
            $data['dominant_category'] = $dominant;
            
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

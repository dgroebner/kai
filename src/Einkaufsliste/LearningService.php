<?php

namespace Kai\Tools\Einkaufsliste;

use Exception;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\Logger;
use PDO;

/**
 * Analysiert historische eBons (kb_receipts & kb_items) und ermittelt
 * Marktpräferenzen (Rewe vs. Globus) sowie Verbrauchsintervalle für den Artikelstamm.
 */
class LearningService
{
    private PDO $pdo;
    private Logger $logger;
    private ProductMasterRepository $productRepo;

    public function __construct(?ProductMasterRepository $productRepo = null)
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new Logger();
        $this->productRepo = $productRepo ?? new ProductMasterRepository();
    }

    /**
     * Liest alle historischen Kassenbon-Positionen aus und lernt Marktzuordnungen,
     * Kategorien und durchschnittliche Kaufintervalle.
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

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalItems = count($rows);

        if ($totalItems === 0) {
            $this->logger->info("LearningService: Keine eBons in kb_receipts/kb_items vorhanden.");
            return [
                'total_items_analyzed' => 0,
                'unique_products' => 0,
                'products_updated' => 0,
            ];
        }

        // Gruppierung nach normalisiertem Artikelnamen
        $products = [];
        foreach ($rows as $row) {
            $rawName = trim($row['name']);
            $normKey = mb_strtolower($rawName, 'UTF-8');

            if (!isset($products[$normKey])) {
                $products[$normKey] = [
                    'canonical_name' => $rawName,
                    'stores' => ['Rewe' => 0, 'Globus' => 0, 'Other' => 0],
                    'categories' => [],
                    'dates' => [],
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
            $name = $data['canonical_name'];

            // Bevorzugter Markt: Globus wenn Globus-Häufigkeit höher, sonst Standard Rewe
            $preferredMarket = $data['stores']['Globus'] > $data['stores']['Rewe'] ? 'Globus' : 'Rewe';

            // Häufigste Kategorie ermitteln
            $dominantCategory = null;
            if (!empty($data['categories'])) {
                arsort($data['categories']);
                $dominantCategory = array_key_first($data['categories']);
            }

            // Kaufdaten sortieren
            $dates = $data['dates'];
            sort($dates);
            $lastPurchased = end($dates);

            // Durchschnittliches Kaufintervall in Tagen berechnen (wenn mindestens 2 verschiedene Einkaufsdaten vorliegen)
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

            // Artikelstamm aktualisieren / ergänzen
            $updateData = [
                'name' => $name,
                'preferred_market' => $preferredMarket,
                'default_category' => $dominantCategory,
                'default_unit' => 'Stück',
                'avg_interval_days' => $avgInterval,
                'last_purchased_at' => $lastPurchased,
            ];

            // Wenn neuer Artikel und Muster für Rabatt/Pfand erkannt: initial auf ignoriert setzen
            $existing = $this->productRepo->findByName($name);
            if (!$existing && $this->isNonProduct($name)) {
                $updateData['is_ignored'] = 1;
                if (!$dominantCategory) {
                    $updateData['default_category'] = 'Sonstiges';
                }
            }

            $this->productRepo->saveOrUpdate($updateData);

            $updatedCount++;
        }

        $this->logger->info("LearningService: eBon-Analyse abgeschlossen.", [
            'total_items' => $totalItems,
            'unique_products' => count($products),
            'updated' => $updatedCount
        ]);

        return [
            'total_items_analyzed' => $totalItems,
            'unique_products' => count($products),
            'products_updated' => $updatedCount,
        ];
    }

    /**
     * Prüft, ob ein Kassenbon-Eintrag typischerweise kein kaufbarer Artikel ist
     * (z. B. Rabatte, Aktionen, Pfand, Leergut).
     */
    private function isNonProduct(string $name): bool
    {
        $lower = mb_strtolower($name, 'UTF-8');
        $keywords = [
            'rabatt', 'coupon', 'aktionsnachlass', 'gutschein', 'treuepunkt',
            'bonus', 'ersparnis', 'nachlass', 'leergut', 'pfand', 'rückgabe',
            'spende', 'aufrund'
        ];

        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }
}

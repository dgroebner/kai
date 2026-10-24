<?php

namespace Kai\Tools\Einkaufsliste;

/**
 * Filtert, validiert und normiert Prospekt-Angebote aus der Marktguru-API
 * gegen Artikel aus dem lokalen Artikelstamm (product_master) und bekannte eBon-Mappings.
 */
class OfferMatcher
{
    /**
     * Bekannte Schein-Komposita (Bestimmungswort entspricht dem Suchbegriff,
     * das Grundwort bezeichnet jedoch eine völlig andere Produktkategorie).
     */
    private const array COMPOUND_EXCLUSIONS = [
        'butter' => ['erdnussbutter', 'kakaobutter', 'sheabutter', 'körperbutter', 'mandelbutter', 'cashewbutter', 'butterkeks', 'butterkekse', 'buttermilch', 'buttergemüse', 'butterkäse', 'buttertoast', 'buttercroissant', 'butterhörnchen', 'buttergebäck', 'butterschmalz'],
        'milch' => ['kokosmilch', 'scheuermilch', 'sonnenmilch', 'körpermilch', 'reinigungsmilch', 'buttermilch', 'milchschnitte', 'milchreis', 'milchbrötchen', 'milchschokolade', 'milchmäuse'],
        'käse' => ['leberkäse', 'fleischkäse', 'käsekuchen', 'käsegebäck', 'käsecracker', 'käsesauce'],
        'apfel' => ['apfelsaft', 'apfelmus', 'apfelmark', 'apfelessig', 'apfelschorle', 'apfelwein', 'apfelstrudel', 'apfeltasche', 'granatapfel'],
        'tomate' => ['tomatensaft', 'tomatenketchup', 'tomatensuppe'],
        'tomaten' => ['tomatensaft', 'tomatenketchup', 'tomatensuppe'],
        'kaffee' => ['kaffeefilter', 'kaffeemaschine', 'kaffeebecher', 'kaffeesahne', 'kaffeeweißer', 'eiskaffee'],
        'wasser' => ['mundwasser', 'rasierwasser', 'gesichtswasser', 'blütenwasser', 'destilliertes'],
        'ei' => ['eierlikör', 'eiersalat', 'eierkuchen', 'eiernudeln', 'eierbecher', 'überraschungsei'],
        'eier' => ['eierlikör', 'eiersalat', 'eierkuchen', 'eiernudeln', 'eierbecher', 'überraschungseier'],
        'brot' => ['brotaufstrich', 'brotdose', 'brotkorb', 'knäckebrot'],
        'sahne' => ['sahnebonbon', 'sahnesteif', 'sahnelikör', 'kaffeesahne'],
    ];

    /**
     * Leitet aus einem Master-Artikel den optimalen Suchbegriff für die Marktguru-Suche ab.
     *
     * @param array<string, mixed> $product Datensatz aus product_master
     */
    public function extractSearchTerm(array $product): string
    {
        if (!empty($product['offer_search_term'])) {
            return trim((string)$product['offer_search_term']);
        }

        $raw = !empty($product['custom_label'])
            ? trim((string)$product['custom_label'])
            : trim((string)($product['name'] ?? ''));

        if ($raw === '') {
            return '';
        }

        // Klammerzusätze und Mengenangaben (z. B. "500g", "1,5 l", "6x1,5L", "3,5%") entfernen
        $cleaned = preg_replace('/\([^)]*\)/u', ' ', $raw) ?? $raw;
        $cleaned = preg_replace('/\b\d+(?:[.,]\d+)?\s*(?:x\s*\d+(?:[.,]\d+)?)?\s*(?:g|kg|ml|l|cl|stk|stück|er|pack|pck|btl|ds|fl|%)\b/iu', ' ', $cleaned) ?? $cleaned;

        // Typische Handelsmarken-Präfixe aus alten eBon-Namen entfernen
        $cleaned = preg_replace('/^(?:rewe\s+beste\s+wahl|rewe\s+bio|rewe\s+feine\s+welt|ja!|globus\s+bio|globus|jeden\s+tag)\b\s*/iu', '', trim($cleaned)) ?? $cleaned;
        $cleaned = preg_replace('/\s{2,}/u', ' ', trim($cleaned)) ?? $cleaned;

        return trim($cleaned, " \t\n\r\0\x0B,-./");
    }

    /**
     * Prüft anhand der internen Marktguru-Regionaltags (~twx...~), ob das Angebot
     * für Sachsen (~twxsn~), Ostdeutschland (~twxode~) oder bundesweit (~twxde~) gilt.
     *
     * @param array<string, mixed> $rawOffer
     */
    public function hasValidRegionalTag(array $rawOffer): bool
    {
        $prodDesc = (string)($rawOffer['product']['description'] ?? '');
        if ($prodDesc === '' || !str_contains($prodDesc, '~twx')) {
            return true;
        }

        if (preg_match_all('/~twx([a-z0-9]+)~/', strtolower($prodDesc), $matches)) {
            $regions = $matches[1];
            if (empty($regions)) {
                return true;
            }
            return in_array('sn', $regions, true)
                || in_array('ode', $regions, true)
                || in_array('de', $regions, true);
        }

        return true;
    }

    /**
     * Prüft, ob ein zurückgeliefertes Marktguru-Angebot fachlich zum gesuchten Artikel passt
     * (Kopf-Nomen-/Komposita-Prüfung gegen False Positives wie "Butterkekse" bei "Butter").
     *
     * @param string $searchTerm Bereinigter Suchbegriff
     * @param array<string, mixed> $rawOffer Einzelnes Element aus results[]
     * @param string[] $knownEbonNames Zugeordnete Kassenbon-Namen aus ebon_product_mappings
     */
    public function isRelevantMatch(string $searchTerm, array $rawOffer, array $knownEbonNames = []): bool
    {
        $termLower = mb_strtolower(trim($searchTerm), 'UTF-8');
        if ($termLower === '') {
            return false;
        }

        $productName = trim((string)($rawOffer['product']['name'] ?? ''));
        $brandName = trim((string)($rawOffer['brand']['name'] ?? ''));
        $description = $this->stripInternalTags((string)($rawOffer['description'] ?? ''));

        $prodLower = mb_strtolower($productName, 'UTF-8');
        $fullLower = mb_strtolower($brandName . ' ' . $productName . ' ' . $description, 'UTF-8');

        // 1. Ausschlussliste für bekannte Schein-Komposita prüfen
        $exclusions = self::COMPOUND_EXCLUSIONS[$termLower] ?? [];
        foreach ($exclusions as $excludedWord) {
            if (str_contains($prodLower, $excludedWord)) {
                return false;
            }
        }

        // 2. Zerlegung des Suchbegriffs in Wörter
        $searchWords = array_values(array_filter(
            preg_split('/[\s,\-\/]+/u', $termLower) ?: [],
            fn(string $w) => mb_strlen($w, 'UTF-8') >= 2
        ));

        if (empty($searchWords)) {
            return false;
        }

        // Bei Einzelwort-Suche (z. B. "Butter", "Milch", "Kaffee"):
        // Das Wort muss im Produktnamen (product.name) als eigenständiges Wort oder als Grundwort (Suffix eines Kompositums) stehen!
        if (count($searchWords) === 1) {
            $word = $searchWords[0];
            if ($this->matchesAsHeadNoun($word, $prodLower)) {
                return true;
            }

            // Fallback: Falls product.name generisch ist, aber Marke + Beschreibung exakt zu einem bekannten eBon-Mapping passen
            if (!empty($knownEbonNames) && $this->matchesKnownBrandAndTerm($word, $brandName, $prodLower, $description, $knownEbonNames)) {
                return true;
            }

            return false;
        }

        // Bei Mehrwort-Suche (z. B. "Passierte Tomaten", "Rote Linsen", "Geriebener Käse"):
        // Das letzte Wort (oder Hauptwort) muss in product.name/description vorkommen, und alle Wörter im Gesamttext
        foreach ($searchWords as $w) {
            $stem = $this->stemGermanWord($w);
            if (!str_contains($fullLower, $stem)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prüft, ob ein gesuchtes Nomen ($word) in $targetText als eigenständiges Wort
     * oder als Grundwort am Wortende eines deutschen Kompositums (z. B. "Süßrahmbutter" für "Butter") auftritt,
     * jedoch NICHT nur als Bestimmungswort am Wortanfang (wie "Butterkeks" oder "Buttergemüse").
     */
    private function matchesAsHeadNoun(string $word, string $targetText): bool
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $targetText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stem = $this->stemGermanWord($word);

        foreach ($tokens as $token) {
            // Exakte Wortübereinstimmung oder gängige Pluralform (z. B. "Apfel" / "banane" -> "bananen")
            if ($token === $word || $token === $stem || $this->isPluralVariant($word, $token)) {
                return true;
            }

            // Kompositum-Prüfung: Token endet auf das gesuchte Wort (z. B. "alpenbutter", "vollmilch", "rinderhackfleisch")
            if (mb_strlen($word, 'UTF-8') >= 4 && str_ends_with($token, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prüft einfache deutsche Plural-/Flexionsvarianten desselben Wortes (nicht aber neue Komposita).
     */
    private function isPluralVariant(string $word, string $token): bool
    {
        foreach (['e', 'n', 'en', 's', 'er'] as $suffix) {
            if ($token === $word . $suffix || $word === $token . $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * Einfache Wortstamm-Reduktion für deutsche Adjektiv-/Plural-Endungen bei Mehrwort-Suchen.
     */
    private function stemGermanWord(string $word): string
    {
        $len = mb_strlen($word, 'UTF-8');
        if ($len <= 4) {
            return $word;
        }
        foreach (['er', 'en', 'es', 'e', 'n', 's'] as $ending) {
            if (str_ends_with($word, $ending) && ($len - strlen($ending)) >= 4) {
                return mb_substr($word, 0, $len - strlen($ending), 'UTF-8');
            }
        }
        return $word;
    }

    /**
     * Prüft, ob die Angebots-Marke in den gelernten eBon-Mappings des Artikels vorkommt.
     *
     * @param string[] $knownEbonNames
     */
    public function isPreferredBrand(?string $brandName, array $knownEbonNames): bool
    {
        $brand = mb_strtolower(trim((string)$brandName), 'UTF-8');
        if ($brand === '' || mb_strlen($brand, 'UTF-8') < 3 || empty($knownEbonNames)) {
            return false;
        }

        // Erste signifikante Marken-Komponente (z. B. "meggle", "kerrygold", "oatly", "exquisa")
        $brandParts = preg_split('/\s+/u', $brand) ?: [$brand];
        $primaryBrand = $brandParts[0];
        if (in_array($primaryBrand, ['rewe', 'ja', 'ja!', 'globus', 'bio'], true) && isset($brandParts[1])) {
            $primaryBrand = $brand . '';
        }

        foreach ($knownEbonNames as $ebonName) {
            $ebonLower = mb_strtolower((string)$ebonName, 'UTF-8');
            if ($primaryBrand !== '' && mb_strlen($primaryBrand, 'UTF-8') >= 3 && str_contains($ebonLower, $primaryBrand)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hilfsprüfung, falls product.name bei Marktguru sehr allgemein ist, aber Marke + Beschreibung
     * eindeutig mit einem bekannten eBon-Eintrag korrespondieren.
     *
     * @param string[] $knownEbonNames
     */
    private function matchesKnownBrandAndTerm(string $word, string $brandName, string $prodLower, string $description, array $knownEbonNames): bool
    {
        if (!$this->isPreferredBrand($brandName, $knownEbonNames)) {
            return false;
        }
        return $this->matchesAsHeadNoun($word, mb_strtolower($description, 'UTF-8'))
            || $this->matchesAsHeadNoun($word, $prodLower);
    }

    /**
     * Extrahiert Packungsgröße (unit_size) und Grundpreis (reference_price) aus der Angebotsbeschreibung.
     *
     * @return array{clean_description: string, unit_size: ?string, reference_price: ?string, normalized_ref_price: ?float}
     */
    public function parseUnitAndReferencePrice(string $rawDescription, ?float $apiRefPrice = null, ?string $apiUnitShortName = null): array
    {
        $clean = $this->stripInternalTags($rawDescription);
        $unitSize = null;
        $refPriceStr = null;
        $normalizedRef = $apiRefPrice !== null && $apiRefPrice > 0 ? round($apiRefPrice, 2) : null;

        // 1. Grundpreis in Klammern erkennen, z. B. "(1 kg = 7.16)" oder "(1 l = 1,49)"
        if (preg_match('/\(\s*(1\s*(?:kg|l|liter|m|stück|stk|wl|bl)\s*=\s*([0-9]+(?:[.,][0-9]{1,2})?))\s*(?:€|EUR)?\s*\)/iu', $clean, $m)) {
            $val = (float)str_replace(',', '.', $m[2]);
            if ($val > 0) {
                $normalizedRef = round($val, 2);
            }
            $refPriceStr = preg_replace('/\s+/', ' ', trim($m[1])) . ' €';
            $refPriceStr = str_replace('.', ',', $refPriceStr);
        } elseif ($normalizedRef !== null) {
            $unitLabel = ($apiUnitShortName !== null && trim($apiUnitShortName) !== '') ? trim($apiUnitShortName) : 'kg';
            $refPriceStr = '1 ' . $unitLabel . ' = ' . number_format($normalizedRef, 2, ',', '.') . ' €';
        }

        // 2. Packungsgröße erkennen, z. B. "je 250-g-Packung", "500-g-Becher", "6 x 1,5-l-Flasche", "1-kg-Beutel"
        if (preg_match('/\b(\d+\s*x\s*\d+(?:[.,]\d+)?|\d+(?:[.,]\d+)?)\s*-?\s*(g|kg|ml|l|cl|er|stück|stk|wl)\b/iu', $clean, $um)) {
            $amount = str_replace('.', ',', trim($um[1]));
            $unit = mb_strtolower($um[2], 'UTF-8');
            if ($unit === 'stk') {
                $unit = 'Stück';
            }
            $unitSize = $amount . ' ' . $unit;
        }

        return [
            'clean_description' => $clean,
            'unit_size' => $unitSize,
            'reference_price' => $refPriceStr,
            'normalized_ref_price' => $normalizedRef,
        ];
    }

    /**
     * Entfernt interne Marktguru-Steuertags (~twx...~) und überflüssige Leerzeichen.
     */
    public function stripInternalTags(string $text): string
    {
        $cleaned = preg_replace('/~[a-z0-9_]+~/i', '', $text) ?? $text;
        $cleaned = preg_replace('/\s{2,}/u', ' ', $cleaned) ?? $cleaned;
        return trim($cleaned);
    }
}

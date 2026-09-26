<?php

namespace Kai\Tools\Shared\Utils;

/**
 * Normalisiert Händlernamen aus Kassenbons, Kreditkartenabrechnungen und Buchungen
 * auf einheitliche, saubere Ketten- bzw. Markennamen (z.B. "REWE", "Globus", "Obi").
 */
final class MerchantNormalizer
{
    /**
     * Bekannte Ketten und Marken mit ihren kanonischen Namen und Erkennungsmustern.
     * Reihenfolge beachten: Spezifischere Muster vor allgemeinen Mustern.
     *
     * @var array<string, string> Regex => Kanonischer Name
     */
    private const array CHAIN_PATTERNS = [
        // Supermärkte & Discounter
        '/\brewe\b/i'                      => 'REWE',
        '/\bglobus\b/i'                    => 'Globus',
        '/\bobi\b/i'                       => 'Obi',
        '/\b(edeka|e-center|e\s+center)\b/i' => 'Edeka',
        '/\bnetto\b/i'                     => 'Netto',
        '/\blidl\b/i'                      => 'Lidl',
        '/\baldi\b/i'                      => 'Aldi',
        '/\bpenny\b/i'                     => 'Penny',
        '/\bkaufland\b/i'                  => 'Kaufland',
        '/\balnatura\b/i'                  => 'Alnatura',
        '/\b(denn\'?s|dennree)\b/i'        => 'Denns Biomarkt',
        '/\btegut\b/i'                     => 'tegut',
        '/\bnorma\b/i'                     => 'Norma',
        '/\bhit\s*(markt|handels|supermarkt)?\b/i' => 'HIT',

        // Baumärkte & Garten
        '/\bbauhaus\b/i'                   => 'Bauhaus',
        '/\bhornbach\b/i'                  => 'Hornbach',
        '/\btoom\b/i'                      => 'toom',
        '/\bhagebau(markt)?\b/i'           => 'Hagebau',
        '/\bdehner\b/i'                    => 'Dehner',

        // Drogerie & Gesundheit
        '/(^|\s|\b)dm(-|\s|$|drogerie)/i'   => 'dm',
        '/\brossmann\b/i'                  => 'Rossmann',
        '/\b(müller|mueller)\b/i'          => 'Müller',
        '/\b(budni|budnikowsky)\b/i'       => 'Budni',

        // Tierbedarf
        '/\bfressnapf\b/i'                 => 'Fressnapf',
        '/\b(das\s+)?futterhaus\b/i'       => 'Das Futterhaus',
        '/\bzooplus\b/i'                   => 'Zooplus',

        // Lieferdienste & Online
        '/\bflaschenpost\b/i'              => 'Flaschenpost',
        '/\bpicnic\b/i'                    => 'Picnic',
        '/\bknuspr\b/i'                    => 'Knuspr',
        '/\b(amazon|amzn)\b/i'             => 'Amazon',
        '/\blieferando\b/i'                => 'Lieferando',
        '/\bhellofresh\b/i'                => 'HelloFresh',
        '/\buber\s*eats\b/i'               => 'Uber Eats',

        // Möbel, Elektronik & Freizeit
        '/\bikea\b/i'                      => 'IKEA',
        '/\baction\b/i'                    => 'Action',
        '/\bwoolworth\b/i'                 => 'Woolworth',
        '/\btedi\b/i'                      => 'TEDi',
        '/\bmedia\s*markt\b/i'             => 'MediaMarkt',
        '/\bsaturn\b/i'                    => 'Saturn',
        '/\bdecathlon\b/i'                 => 'Decathlon',
        '/\bthalia\b/i'                    => 'Thalia',
        '/\bhugendubel\b/i'                => 'Hugendubel',
        '/\bfielmann\b/i'                  => 'Fielmann',
        '/\bapollo\b/i'                    => 'Apollo',

        // Tankstellen
        '/\baral\b/i'                      => 'Aral',
        '/\bshell\b/i'                     => 'Shell',
        '/\b(total|totalenergies)\b/i'     => 'Total',
        '/\bjet\b/i'                       => 'JET',
        '/\besso\b/i'                      => 'Esso',
        '/\bhem\b/i'                       => 'HEM',
        '/\bavia\b/i'                      => 'AVIA',
    ];

    /**
     * Regex für Rechtsformen und Gesellschaftsbezeichnungen.
     */
    private const string LEGAL_FORMS_PATTERN = '/\b(' .
        'gmbh\s*(&|\+)\s*co\.?(\s*(kg|kgaa|ohg))?|' .
        'ag\s*(&|\+)\s*co\.?\s*kg|' .
        'se\s*(&|\+)\s*co\.?\s*kgaa|' .
        '(&|\+)\s*co\.?\s*kg|' .
        '(&|\+)\s*co\.?|' .
        'gmbh|ggmbh|ug\s*\(haftungsbeschr[äa]nkt\)|ug|ag|se|kgaa|ohg|' .
        'kg|e\.?\s*k\.?|e\.?\s*v\.?|gbr|ltd\.?|limited|inc\.?' .
    ')\b/iu';

    /**
     * Regex für Filialzusätze, Marktbezeichnungen und Füllwörter.
     */
    private const string DESCRIPTORS_PATTERN = '/\b(' .
        'heimwerkermarkt|sb-warenhaus|warenhaus|supermarkt|verbrauchermarkt|' .
        'handelsgesellschaft|vertriebsgesellschaft|handelshof|filiale|' .
        'center|markt|store|shop|online\s*shop|onlineshop' .
    ')\b/iu';

    private function __construct()
    {
    }

    /**
     * Normalisiert einen Händlernamen auf den kanonischen Ketten- bzw. Markennamen.
     */
    public static function normalize(?string $rawName): string
    {
        $rawName = trim((string)$rawName);
        if ($rawName === '') {
            return 'Unbekannt';
        }

        // 1. Bekannte Ketten direkt abgleichen
        foreach (self::CHAIN_PATTERNS as $pattern => $canonicalName) {
            if (preg_match($pattern, $rawName) === 1) {
                return $canonicalName;
            }
        }

        // 2. Allgemeine Bereinigung für sonstige/unbekannte Händler
        $cleaned = $rawName;

        // Komma-getrennte Ortszusätze abschneiden (z.B. "Sachsen-Therme GmbH, Leipzig" -> "Sachsen-Therme GmbH")
        if (str_contains($cleaned, ',')) {
            $parts = explode(',', $cleaned);
            $cleaned = trim($parts[0]);
        }

        // Rechtsformen entfernen
        $cleaned = (string)preg_replace(self::LEGAL_FORMS_PATTERN, '', $cleaned);

        // Marktbezeichnungen/Deskriptoren entfernen
        $cleaned = (string)preg_replace(self::DESCRIPTORS_PATTERN, '', $cleaned);

        // Mehrfache Leerzeichen, Bindestriche, Punkte am Rand aufräumen
        $cleaned = (string)preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned, " \t\n\r\0\x0B.,-_&+/");

        return $cleaned !== '' ? $cleaned : $rawName;
    }

    /**
     * Fasst eine Liste von Händlern (z.B. aus DB-Reports) anhand des normalisierten Namens
     * zusammen, addiert Beträge und Anzahlen und sortiert absteigend nach Umsatz.
     *
     * @param array<int, array{merchant: string, total: float|int, count: int}> $merchants
     * @param int $limit Maximale Anzahl zurückgegebener Top-Händler
     * @return array<int, array{merchant: string, total: float, count: int}>
     */
    public static function consolidateTopMerchants(array $merchants, int $limit = 5): array
    {
        $map = [];

        foreach ($merchants as $m) {
            $raw = (string)($m['merchant'] ?? '');
            $name = self::normalize($raw);
            if ($name === '' || $name === 'Unbekannt') {
                continue;
            }

            if (!isset($map[$name])) {
                $map[$name] = [
                    'merchant' => $name,
                    'total'    => 0.0,
                    'count'    => 0,
                ];
            }

            $map[$name]['total'] += (float)($m['total'] ?? 0);
            $map[$name]['count'] += (int)($m['count'] ?? 0);
        }

        uasort($map, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

        $top = array_slice(array_values($map), 0, $limit);
        foreach ($top as &$item) {
            $item['total'] = round($item['total'], 2);
        }
        unset($item);

        return $top;
    }
}

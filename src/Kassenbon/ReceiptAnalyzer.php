<?php

namespace Kai\Tools\Kassenbon;

use Exception;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Utils\MerchantNormalizer;

class ReceiptAnalyzer
{
    private GeminiClient $aiClient;
    private Logger $logger;

    public function __construct()
    {
        $this->aiClient = new GeminiClient();
        $this->logger = new Logger(14);
    }

    // Die Methode nimmt jetzt optional ein Array der bisherigen Kategorien entgegen

    /**
     * @throws Exception
     */
    public function analyze(string $mimeType, string $base64Data, array $existingCategories = []): ?array
    {
        $this->logger->info("ReceiptAnalyzer: Fordere Datenextraktion bei Gemini an...");

        // Dynamischer Kontext für die Kategorien
        $categoryContext = empty($existingCategories)
            ? "Erstelle passende, standardisierte Kategorien (z.B. Molkereiprodukte & Eier, Obst & Gemüse, Frischetheke (Fleisch & Wurst, Käse), Brot & Backwaren, Müsli, Brotaufstriche & Kaffee/Tee, Nudeln & Reis, Konserven, Gewürze, Öle & Fertiggerichte, Süßwaren & Knabberartikel, Drogerie, Haushalt, Getränke, Spirituosen, Tiefkühlkost, Sonstiges)."
            : "WICHTIG: Ordne die Artikel zwingend einer dieser bekannten Kategorien zu, falls passend: [" . implode(', ', $existingCategories) . "]. Erfinde nur eine neue Kategorie, wenn wirklich absolut keine der vorhandenen passt.";

        $prompt = "Du bist ein präziser Datenextraktions-Assistent. Analysiere diesen Kassenbon. " .
            "Gib AUSSCHLIESSLICH valides JSON zurück. Formatiere es NICHT als Markdown (kein ```json). " .
            $categoryContext . " " .
            "WICHTIGE REGEL FÜR 'store': Gib als Händler ausschließlich den kurzen, bekannten Markennamen bzw. Kettennamen an " .
            "(z.B. 'REWE', 'Globus', 'Obi', 'Edeka', 'Netto', 'Lidl', 'Aldi', 'Penny', 'Kaufland', 'Flaschenpost', 'Fressnapf', 'dm', 'Rossmann', 'EnBW', 'Stadtwerke Leipzig', 'Vattenfall', 'Aral'). " .
            "Entferne Inhabernamen (wie Lucas Musculus oHG, e.K.), Rechtsformen (GmbH, AG, KG etc.), Filialangaben und Ortsnamen vollständig. " .
            "BESONDERHEIT BEI LADEABRECHNUNGEN / MOBILITÄT (z.B. EnBW mobility+, Stadtwerke Leipzig): " .
            "Erfasse jeden Einzelladevorgang als separaten Posten in 'items' mit Ladedatum, Ort und geladener kWh im Namen (z.B. 'Ladevorgang 20.09.2026 Ehrenfriedersdorf'), " .
            "'quantity' als geladene kWh (z.B. 17.40) und 'total_price' als Bruttobetrag dieses Ladevorgangs. " .
            "BESONDERHEIT BEI RECHNUNGEN & MOBILFUNK/FESTNETZ (z.B. Telekom, Vodafone, O2): " .
            "Setze 'document_type' auf 'mobile_bill' (bei Mobilfunkrechnungen) bzw. 'landline_bill' (bei Festnetz-/DSL-/Internet-Rechnungen) bzw. 'invoice' (bei sonstigen Rechnungen). " .
            "Extrahiere zusätzlich: 'invoice_number' (Rechnungsnummer), 'due_date' (angekündigtes Abbuchungs-/Zahlungsdatum YYYY-MM-DD), " .
            "'billing_period' (z.B. 'September 2026'), 'mandate_reference' (SEPA-Mandatsreferenz), 'customer_number' (Kundennummer), " .
            "'customer_account' (Kundenkonto), 'tax_amount' (USt.-Betrag), 'net_amount' (Nettobetrag). " .
            "Falls die Rechnung nach Mobilfunk-Rufnummern / SIM-Karten unterteilt ist, erstelle 'phone_profiles': " .
            "[ { \"phone_number\": \"0151...\", \"profile_number\": \"8-...\", \"tariff\": \"Tarifname\", \"total\": 10.00, \"items\": [ { \"name\": \"Postenname\", \"price\": 10.00 } ] } ]. " .
            "Rabatte/Vorteile (z.B. MagentaEINS Vorteil -5,00 €) in 'items' mit negativem unit_price und total_price erfassen. " .
            "Das JSON muss exakt dieses Format haben: " .
            "{ \"store\": \"Kettenname des Händlers\", \"date\": \"YYYY-MM-DD\", \"total\": 0.00, \"document_type\": \"receipt|mobile_bill|landline_bill|invoice\", " .
            "\"invoice_number\": null, \"due_date\": null, \"billing_period\": null, \"mandate_reference\": null, " .
            "\"customer_number\": null, \"customer_account\": null, \"tax_amount\": null, \"net_amount\": null, \"phone_profiles\": null, " .
            "\"items\": [ { \"name\": \"Artikelname\", \"quantity\": 1.0, \"unit_price\": 0.00, \"total_price\": 0.00, \"category\": \"Kategorie-Name\" } ] }";

        try {
            $result = $this->aiClient->generate($prompt, $mimeType, $base64Data, true);

            if ($result) {
                if (isset($result['store'])) {
                    $result['store'] = MerchantNormalizer::normalize($result['store']);
                }
                $this->logger->info("ReceiptAnalyzer: JSON-Daten erfolgreich extrahiert.");
                return $result;
            }

            $this->logger->error("ReceiptAnalyzer: KI lieferte leeres oder ungültiges Ergebnis.");
            return null;

        } catch (Exception $e) {
            $this->logger->error("ReceiptAnalyzer: Abbruch bei der KI-Analyse.", ['error' => $e->getMessage()]);
            throw new Exception("Fehler bei der Receipt-Analyse: " . $e->getMessage());
        }
    }
}
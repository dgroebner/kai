<?php

namespace Kai\Tools\Bank;

use Exception;
use Kai\Tools\Shared\AI\GeminiClient;
use Kai\Tools\Shared\Db\Database;
use Kai\Tools\Shared\Log\ActivityLogger;
use Kai\Tools\Shared\Log\Logger;

class FinancialReportService
{
    private FinancialReportRepository $repository;
    private FinancialReportAggregator $aggregator;
    private GeminiClient $geminiClient;
    private Logger $logger;
    private ActivityLogger $activityLogger;

    public function __construct(
        ?FinancialReportRepository $repository = null,
        ?FinancialReportAggregator $aggregator = null,
        ?GeminiClient $geminiClient = null,
        ?Logger $logger = null,
        ?ActivityLogger $activityLogger = null
    ) {
        $this->repository = $repository ?? new FinancialReportRepository();
        $this->aggregator = $aggregator ?? new FinancialReportAggregator();
        $this->geminiClient = $geminiClient ?? new GeminiClient();
        $this->logger = $logger ?? new Logger(14);
        $this->activityLogger = $activityLogger ?? new ActivityLogger(Database::getInstance());
    }

    /**
     * Lädt einen gespeicherten Bericht oder generiert ihn neu.
     *
     * @param string $periodType 'month' oder 'year'
     * @param string $periodTarget 'YYYY-MM' oder 'YYYY'
     * @param bool $forceRefresh Wenn true, wird trotz vorhandenem Cache neu aggregiert und analysiert
     * @param string|null $periodReference Optionaler Vergleichszeitraum
     * @return array
     * @throws Exception
     */
    public function getOrGenerateReport(
        string $periodType,
        string $periodTarget,
        bool $forceRefresh = false,
        ?string $periodReference = null
    ): array {
        if (!$forceRefresh) {
            $existing = $this->repository->getReport($periodType, $periodTarget);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->generateReport($periodType, $periodTarget, $periodReference);
    }

    /**
     * Führt eine neue Aggregation und KI-Finanzanalyse via Gemini durch und persistiert das Ergebnis.
     *
     * @throws Exception
     */
    public function generateReport(
        string $periodType,
        string $periodTarget,
        ?string $periodReference = null
    ): array {
        $this->logger->info("FinancialReportService: Starte Bericht-Generierung für $periodType / $periodTarget");

        // 1. Deterministische Aggregation der Backend-Daten
        $aggregatedData = $this->aggregator->aggregate($periodType, $periodTarget, $periodReference);
        $resolvedRef = $aggregatedData['metadata']['period_reference'];

        // 2. Gemini System-Prompt & User-Prompt aufbauen
        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = $this->buildPrompt($aggregatedData);

        // 3. Gemini KI-Aufruf
        $aiAnalysis = null;
        try {
            $parsed = $this->geminiClient->generate(
                prompt: $userPrompt,
                jsonMode: true,
                systemInstruction: $systemPrompt
            );

            if (is_array($parsed)) {
                $aiAnalysis = $this->normalizeAiAnalysis($parsed);
            } else {
                $this->logger->warn('FinancialReportService: Gemini lieferte kein valides JSON-Array zurück.');
            }
        } catch (Exception $e) {
            $this->logger->error('FinancialReportService: Fehler bei Gemini-Aufruf: ' . $e->getMessage());
        }

        if ($aiAnalysis === null) {
            $aiAnalysis = $this->buildFallbackAnalysis($aggregatedData);
        }

        // 4. In der Datenbank speichern
        $this->repository->saveReport(
            $periodType,
            $periodTarget,
            $resolvedRef,
            $aggregatedData,
            $aiAnalysis
        );

        // 5. Activity-Log
        $periodLabel = $periodType === 'year' ? "Jahr $periodTarget" : "Monat $periodTarget";
        $this->activityLogger->log(
            'financial_report_generated',
            "KI-Finanzreport für $periodLabel erstellt",
            "/bank/report.php?type=$periodType&period=$periodTarget"
        );

        return [
            'period_type' => $periodType,
            'period_target' => $periodTarget,
            'period_reference' => $resolvedRef,
            'aggregated_data' => $aggregatedData,
            'ai_analysis' => $aiAnalysis,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * System-Prompt nach Konzeptvorgabe concepts/future/finanzreport.md
     */
    private function getSystemPrompt(): string
    {
        return <<<PROMPT
Du bist ein persönlicher, verständlicher Finanzassistent für private Finanzen. Dein Ziel ist es, aus den voraggregierten Finanzdaten klare, alltagsnahe und leicht verständliche Erkenntnisse, Trends und praktische Tipps abzuleiten.

SPRACHE & TONFALL:
- Schreibe in einer einfachen, menschlichen und leicht verständlichen Alltagssprache.
- Verzichte komplett auf geschwollenes Banker- und Finanzjargon (keine Fachbegriffe wie „disjunkte Primäraggregationen“, „Konsumvolatilität“, „Periodendifferenzial“, „Budgetallokation“ etc.).
- Sprich den Nutzer direkt, freundlich und auf Augenhöhe an (z. B. „Diesen Monat hast du...“, „Deine festen Ausgaben liegen bei...“).
- Formuliere kurz, klar und auf den Punkt.

WICHTIGE REGEL ZU DEN SUMMEN:
Die Buchungsdaten nutzen ein Multi-Label-System. Einzelne Buchungen können mehreren Tags zugeordnet sein. 
- Nutze für Gesamtsalden, Sparquote und Realausgaben AUSSCHLIESSLICH die Zahlen aus `cashflow_totals`. Addiere NIEMALS die Werte aus `tag_breakdown` auf, da Buchungen mehrere Tags haben können und dies zu Doppelzählungen führt.
- Nutze `tag_breakdown` ausschließlich, um Schwerpunkte und Ausreißer zu erklären.

AUFGABEN:
1. Verfasse ein kurzes, ermutigendes und klares Fazit (maximal 3 einfache Sätze).
2. Erkläre einfach das Verhältnis von festen Kosten (Miete, Verträge) zu veränderbaren Ausgaben (Einkaufen, Freizeit).
3. Zeige auffällige Ausgabenkategorien verständlich auf und erkläre anhand der `overlap_tags`, warum die Ausgaben entstanden sind (z. B. „Mehr für Freizeit ausgegeben, vor allem wegen Urlaubsaktivitäten“).
4. Melde Unregelmäßigkeiten bei Verträgen direkt (z. B. wenn eine Abbuchung höher war als sonst oder eine Zahlung gefehlt hat).
5. Erkläre Auffälligkeiten bei Kassenbons und Einkäufen (wo wurde eingekauft, wurden bestimmte Produkte teurer, gab es viele kleine Spontankäufe).
6. Gib einen einfachen Ausblick auf den nächsten Zeitraum und 1 bis 3 konkrete, alltagstaugliche Tipps.

Antworte strikt im vorgegebenen JSON-Format ohne umschließende Markdown-Backticks.
PROMPT;
    }

    /**
     * Baut das Übergabe-Payload und die Schemaanweisung für Gemini.
     */
    private function buildPrompt(array $aggregatedData): string
    {
        $payloadJson = json_encode($aggregatedData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return <<<PROMPT
Hier sind die voraggregierten Finanzdaten für die Auswertung:

```json
$payloadJson
```

Erstelle die Auswertung in einfacher, verständlicher Sprache und antworte im folgenden JSON-Format:
{
  "summary": "Kurzes, verständliches Fazit (maximal 3 Sätze) darüber, wie der Monat oder das Jahr finanziell gelaufen ist.",
  "fixed_vs_variable": {
    "analysis": "Einfache Erklärung, wie viel Geld für feste Verträge und wie viel für alltäglichen Konsum draufging und wie die Sparquote einzuschätzen ist.",
    "status": "healthy"
  },
  "tag_anomalies": [
    {
      "tag_name": "Name der Kategorie / des Tags",
      "type": "spike",
      "observation": "Einfache Beobachtung (z. B. 'Diesen Monat hast du 120 € mehr für Essen ausgegeben als im Vormonat.')",
      "overlap_context": "Erklärung aus den Co-Tags (z. B. 'Vor allem durch Restaurantbesuche am Wochenende')"
    }
  ],
  "contract_findings": [
    {
      "contract_name": "Name des Vertrags",
      "severity": "warning",
      "description": "Einfache Erklärung der Abweichung (z. B. 'Die Abbuchung war um 5 € höher als vereinbart.')"
    }
  ],
  "receipt_insights_analysis": {
    "inflation_notes": "Verständlicher Hinweis zu Preissteigerungen bei Artikeln im Einkaufskorb",
    "merchant_notes": "Kurze Notiz zu den Haupt-Einkaufsorten und vielen kleinen Beträgen unter 10 €",
    "basket_split_notes": "Hinweis zu gemischten Einkäufen (z. B. Drogerie und Lebensmittel im selben Markt)"
  },
  "forecast": "Einfacher, realistischer Ausblick für den kommenden Monat.",
  "action_items": [
    "Praktischer Alltagstipp 1",
    "Praktischer Alltagstipp 2"
  ]
}
PROMPT;
    }

    /**
     * Normalisiert und sichert die Struktur der KI-Antwort ab.
     */
    private function normalizeAiAnalysis(array $data): array
    {
        return [
            'summary' => (string)($data['summary'] ?? 'Keine Zusammenfassung verfügbar.'),
            'fixed_vs_variable' => [
                'analysis' => (string)($data['fixed_vs_variable']['analysis'] ?? ''),
                'status' => in_array($data['fixed_vs_variable']['status'] ?? '', ['healthy', 'tight', 'critical'], true)
                    ? $data['fixed_vs_variable']['status']
                    : 'healthy',
            ],
            'tag_anomalies' => is_array($data['tag_anomalies'] ?? null) ? $data['tag_anomalies'] : [],
            'contract_findings' => is_array($data['contract_findings'] ?? null) ? $data['contract_findings'] : [],
            'receipt_insights_analysis' => [
                'inflation_notes' => (string)($data['receipt_insights_analysis']['inflation_notes'] ?? ''),
                'merchant_notes' => (string)($data['receipt_insights_analysis']['merchant_notes'] ?? ''),
                'basket_split_notes' => (string)($data['receipt_insights_analysis']['basket_split_notes'] ?? ''),
            ],
            'forecast' => (string)($data['forecast'] ?? ''),
            'action_items' => is_array($data['action_items'] ?? null) ? array_map('strval', $data['action_items']) : [],
        ];
    }

    /**
     * Deterministische Fallback-Auswertung, falls die externe KI nicht antworten konnte.
     */
    private function buildFallbackAnalysis(array $aggregatedData): array
    {
        $cashflow = $aggregatedData['cashflow_totals'] ?? [];
        $net = (float)($cashflow['net_balance'] ?? 0.0);
        $savings = (float)($cashflow['savings_rate_percent'] ?? 0.0);

        $summary = sprintf(
            'In diesem Zeitraum sind unterm Strich %+.2f € übrig geblieben. Deine Sparquote lag bei %.1f%%.',
            $net,
            $savings
        );

        $contractFindings = [];
        foreach (($aggregatedData['contract_deviations'] ?? []) as $dev) {
            $contractFindings[] = [
                'contract_name' => $dev['contract_name'],
                'severity' => 'warning',
                'description' => $dev['details'],
            ];
        }

        return [
            'summary' => $summary,
            'fixed_vs_variable' => [
                'analysis' => sprintf(
                    'Für feste Verträge und Abos gingen %.2f € ab, für alltägliche Ausgaben wurden %.2f € genutzt.',
                    (float)($cashflow['fixed_expenses_total'] ?? 0),
                    (float)($cashflow['variable_expenses_total'] ?? 0)
                ),
                'status' => $net >= 0 ? 'healthy' : 'tight',
            ],
            'tag_anomalies' => [],
            'contract_findings' => $contractFindings,
            'receipt_insights_analysis' => [
                'inflation_notes' => !empty($aggregatedData['receipt_insights']['top_price_increases'])
                    ? 'Bei einigen Artikeln im Einkaufswagen gab es Preisanstiege.'
                    : 'Keine auffälligen Preisanstiege bei den Einkäufen.',
                'merchant_notes' => '',
                'basket_split_notes' => '',
            ],
            'forecast' => 'Wenn die festen Kosten so bleiben, kannst du dich im nächsten Monat an deinen gewohnten Ausgaben orientieren.',
            'action_items' => [
                'Regelmäßige Verträge und Abbuchungen im Blick behalten.',
                'Auf ungeplante Spontankäufe und Kleinbeträge achten.',
            ],
        ];
    }
}

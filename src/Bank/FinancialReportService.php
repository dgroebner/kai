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
Du bist ein hochpräziser Finanzanalyst für private Finanzen. Dein Ziel ist es, aus voraggregierten Finanzdaten aussagekräftige Erkenntnisse, Trends, Anomalien und Handlungsbedarfe abzuleiten.

WICHTIGE REGEL ZU DEN SUMMEN:
Die Buchungsdaten nutzen ein Multi-Label-System. Einzelne Buchungen können mehreren Tags zugeordnet sein. 
- Nutze für Salden, Puffer- und Gesamtberechnungen AUSSCHLIESSLICH die Werte aus `cashflow_totals`. Addiere NIEMALS die Werte aus `tag_breakdown` auf, da dies zu Doppelzählungen führt.
- Nutze `tag_breakdown` ausschließlich zur Identifikation von Ausreißern, Trendwechseln und thematischen Schwerpunkten.

AUFGABEN:
1. Verfasse ein prägnantes Monats- bzw. Jahresfazit (maximal 3 Sätze).
2. Analysiere das Verhältnis von Fixkosten zu variablem Konsum.
3. Identifiziere signifikante Ausreißer in den Tags unter Einbeziehung der `overlap_tags` (z. B. Sonderausgaben durch Urlaub vs. reguläre Kosten).
4. Melde Unregelmäßigkeiten bei Verträgen (Preiserhöhungen, fehlende Buchungen).
5. Analysiere Kassenbondaten auf Artikelebene (z. B. Eigenpreis-Inflation, Spontankäufe, Händlerkonzentration).
6. Gib eine kurze, realistische Prognose für die Folgeperiode ab.

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
Hier sind die voraggregierten Finanzdaten für die Analyse:

```json
$payloadJson
```

Analysiere diese Daten präzise und antworte im folgenden JSON-Format:
{
  "summary": "Prägnantes Fazit (maximal 3 Sätze) über den Gesamterfolg des Zeitraums.",
  "fixed_vs_variable": {
    "analysis": "Bewertung des Verhältnisses von Fixkosten zu steuerbarem Konsum sowie der Sparquote.",
    "status": "healthy"
  },
  "tag_anomalies": [
    {
      "tag_name": "Name des Tags",
      "type": "spike",
      "observation": "Beobachtung zu auffälligem Anstieg, Rückgang oder Besonderheit",
      "overlap_context": "Erklärung basierend auf overlap_tags (z. B. Co-Tag Urlaub oder Haushalt)"
    }
  ],
  "contract_findings": [
    {
      "contract_name": "Name des Vertrags",
      "severity": "warning",
      "description": "Details zu Preiserhöhung, Abweichung oder fehlender Abbuchung"
    }
  ],
  "receipt_insights_analysis": {
    "inflation_notes": "Beobachtungen zu Preissteigerungen bei Artikeln",
    "merchant_notes": "Konzentration auf Händler und Kleinbuchungen",
    "basket_split_notes": "Auffälligkeiten bei Multi-Kategorie-Einkäufen"
  },
  "forecast": "Kurze, realistische Prognose für die Folgeperiode.",
  "action_items": [
    "Konkrete Handlungsempfehlung 1",
    "Konkrete Handlungsempfehlung 2"
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
            'Im Betrachtungszeitraum wurde ein Netto-Saldo von %+.2f € erzielt. Die Sparquote lag bei %.1f%%.',
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
                    'Fixkosten betrugen %.2f €, variable Konsumausgaben %.2f €.',
                    (float)($cashflow['fixed_expenses_total'] ?? 0),
                    (float)($cashflow['variable_expenses_total'] ?? 0)
                ),
                'status' => $net >= 0 ? 'healthy' : 'tight',
            ],
            'tag_anomalies' => [],
            'contract_findings' => $contractFindings,
            'receipt_insights_analysis' => [
                'inflation_notes' => !empty($aggregatedData['receipt_insights']['top_price_increases'])
                    ? 'Preissteigerungen bei erfassten Artikeln erkannt.'
                    : 'Keine signifikanten Preissteigerungen festgestellt.',
                'merchant_notes' => '',
                'basket_split_notes' => '',
            ],
            'forecast' => 'Aufgrund fehlender KI-Konnektivität steht keine automatisierte Prognose bereit.',
            'action_items' => [
                'Vertragsabweichungen in den Buchungsdetails prüfen.',
                'Budgetkontrolle für variable Konsumausgaben aufrechterhalten.',
            ],
        ];
    }
}

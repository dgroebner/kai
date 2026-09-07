# Implementierungskonzept: Standardisiertes KI-Finanz-Reporting

## 1. Zielstellung und Architekturübersicht

Ziel ist die Bereitstellung eines standardisierten Finanz-Reporting-Moduls. Das System bereitet historische und aktuelle
Finanzdaten (Girobuchungen, Kreditkartenabrechnungen, Verträge und E-Bons mit Positionsdaten) deterministisch im Backend
auf und übergibt ein aggregiertes JSON-Payload an das Gemini-Modell. Gemini fungiert rein als Analyse- und
Interpretationsebene und liefert ein streng definiertes JSON-Schema zurück, welches im Frontend visualisiert und in der
Datenbank persistiert wird.

### Kernprinzipien

* **Mathematische Disjunktion:** Das LLM führt keine eigenständigen Primäraggregationen oder Summenbildungen über
  Rohbuchungen durch. Alle Salden, Nettoausgaben und Tag-Volumina werden vorab im Backend berechnet.
* **Trennung von Real-Saldo und Dimensionen:** Da Girobuchungen über ein Multi-Label-System mehreren Kategorien
  angehören können, werden Gesamtsummen strikt getrennt von Tag-Summen ausgewiesen.
* **Persistenz & Standardisierung:** Jeder Analyse-Lauf (Monat oder Jahr) erzeugt dieselbe Ausgabestruktur. Berichte
  werden gecacht/gespeichert, um schnelle Ladezeiten und konsistente Vorperioden-Vergleiche zu ermöglichen.

---

## 2. Backend-Anforderungen: Datenaggregation (Pre-Processing)

Bevor der Aufruf an die Gemini-API erfolgt, aggregiert das Backend die Daten für den Zielzeitraum sowie für eine
definierte Referenzperiode (z. B. Vorjahreszeitraum oder Durchschnitt der letzten 3 Monate).

### 2.1 Daten-Konsolidierung

* **Kreditkarte & E-Bon Verschmelzung:** Verknüpfte Kreditkartenbuchungen und Kassenbons werden zu gemeinsamen
  Datensätzen zusammengeführt. Für die KI muss erkennbar sein, welcher Betrag durch welche konkreten Korb-Positionen
  (Artikel, Einzelpreis, Warengruppe) entstanden ist.
* **Vertragsabgleich:** Laufende Verträge werden als Soll-Werte den tatsächlichen Ist-Abbuchungen auf Giro- oder
  Kreditkartenkonten gegenübergestellt (Erkennung von Abweichungen).

### 2.2 Spezifikation des Übergabe-Payloads an Gemini

Das Backend generiert ein JSON-Objekt mit folgender Struktur:

* `metadata`:
    * `period_type`: "month" oder "year"
    * `period_target`: Betrachteter Zeitraum (z. B. "2026-08")
    * `period_reference`: Vergleichszeitraum (z. B. "2026-07" oder "average_3m")
* `cashflow_totals` (Disjunkte Buchhaltungsebene, jede Buchung exakt 1x gewertet):
    * `total_income`: Summe aller tatsächlichen Einnahmen
    * `total_expenses`: Summe aller tatsächlichen Ausgaben
    * `net_balance`: Differenz aus Einnahmen und Ausgaben
    * `savings_rate_percent`: Berechnete Sparquote
    * `fixed_expenses_total`: Summe vertraglich gebundener Fixkosten
    * `variable_expenses_total`: Summe des steuerbaren Konsums
* `tag_breakdown` (Dimensionale Ebene, Überlappungen durch Multi-Labeling erlaubt):
    * Array von Tags mit: `tag_name`, `target_sum`, `reference_sum`, `delta_absolute`, `delta_percent`, `overlap_tags`
      (häufigste Co-Tags zur Kontextualisierung).
* `contract_deviations`:
    * Liste von Verträgen, bei denen der Abbuchungsbetrag vom hinterlegten Soll-Beitrag abweicht oder fällige Buchungen
      fehlen.
* `receipt_insights`:
    * `top_merchants`: Händler nach Gesamtvolumen
    * `micro_transactions`: Anzahl und Summe von Kleinbuchungen (z. B. < 10 €)
    * `top_price_increases`: Artikelpositionen mit messbarem Preisanstieg gegenüber dem Referenzzeitraum (gleicher
      Artikel, höherer Einzelpreis)
    * `basket_splits`: Kassenbons, die mehrere unterschiedliche Warenbereiche abdecken (z. B. Drogerie-Artikel auf
      Supermarktbon)

---

## 3. Gemini-Schnittstelle & Prompting

Der Aufruf nutzt das Gemini-SDK mit gesetztem JSON-Ausgabemodus (`response_mime_type: "application/json"`).

### 3.1 System-Prompt

```text
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
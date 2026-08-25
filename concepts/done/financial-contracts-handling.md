# Konzept: Zentrales Vertrags- und Verpflichtungsmanagement (Manuell & Regelbasiert)

Dieses Konzept beschreibt die Implementierung eines zentralen Managements für Verträge, Abos, Abgaben und Kredite in
deiner PostgreSQL- und PHP-basierten Finanzanwendung. Das System führt Girokonto- und Kreditkartenbuchungen zusammen, um
wiederkehrende Verpflichtungen strukturiert zu erfassen. Die Zuordnung erfolgt bewusst über einen kontrollierten,
manuellen Prozess mit unterstützendem Regelwerk, um maximale Datenqualität zu gewährleisten.

---

## 1. Datenmodell & Architektur

### 1.1. Haupttabelle: `recurring_obligations`

Alle wiederkehrenden finanziellen Verpflichtungen werden in einer einheitlichen Tabelle geführt:

* **Typen (`type`):** `vertrag`, `abo`, `abgabe` (z. B. GEZ, Grundsteuer) und `kredit`.
* **Status (`status`):** `aktiv`, `pausiert`, `gekuendigt`, `beendet`.
* **Kernattribute:**
    * `id`: Primärschlüssel.
    * `name`: Sprechender Name (z. B. "Netflix", "Haftpflichtversicherung", "Autokredit").
    * `auftraggeber`: Name des Zahlungsempfängers/-absenders (für Girobuchungen).
    * `mandatsnummer`: SEPA-Mandatsreferenz vom Girokonto.
    * `betrag`: Erwarteter Zahlungsbetrag.
    * `frequenz`: Zahlungsrhythmus (`monatlich`, `vierteljaehrlich`, `halbjaehrlich`, `jaehrlich`, `einmalig`).
    * `variabel`: Boolean-Flag (True bei schwankenden Beträgen wie Strom oder variablen Kreditkartenabrechnungen).
    * `start_datum` & `end_datum`: Zeitfenster für Vorauschauen und automatische Auslauftermine.
    * `category_id`: Verknüpfung mit dem bestehenden Kategoriensystem. `bank_categories`
* **Taggingsystem**
    * Verknüpfung mit dem bestehenden Tagging-System der Giro-Transaktionen: `bank_tags`
    * Erstellung einer separaten Verknüpfungsrelation zur Verknüpfung mit den Tags der Verträge. Analog
      `bank_transaction_tags`.
    * Erstellung einer separaten Regeltabelle für die speicherung der Taggingregeln. Analog der `bank__tags_rules`.

### 1.2. Regeltabelle für das Matching: `contract_rules`

Da Kreditkartenbuchungen (z. B. über Aggregatoren wie `PAYPAL *CROWDFARM` oder `Google Play`) keine Mandatsnummern
besitzen, werden Verträge über ein flexibles, regelbasiertes System (analog zu den Giro-Kategorien) verknüpft:

* `id`: Primärschlüssel.
* `contract_id`: Fremdschlüssel zur Vertragstabelle.
* `pattern_type`: Art der Regel (`regex`, `exact_match`, `substring`).
* `pattern_value`: Suchmuster für den `merchant_name` der Kreditkarte oder den Verwendungszweck/Auftraggeber des
  Girokontos (z. B. `^PAYPAL\s*\*.*CROWDFARM`).
* `priority`: Gewichtung bei Überschneidungen.

---

## 2. Implementierungsschritte (Roadmap)

### Schritt 1: Datenbank-Schema in PostgreSQL anlegen

* Erstellung der Haupttabelle `recurring_obligations` inklusive Enums (Typ, Status, Frequenz).
* Anlegen der Regeltabelle `contract_rules` für das flexible Händler- und Text-Matching.

### Schritt 2: Manuelle Zuordnung & Regel-Editor im Dashboard

* Aufbau einer Administrations-Oberfläche analog zur Giro-Kategorisierung.
* **Kandidaten-Anzeige:** Das System zeigt unzugeordnete, wiederkehrende Buchungen (Giro & Kreditkarte) an.
* **Regel-Erstellung:** Du verknüpfst eine Buchung manuell mit einem Vertrag und definierst direkt das passende
  Regelwerk (z. B. Regex für den Kreditkarten-Händlertext oder Mandatsnummer für das Girokonto).

### Schritt 3: Transaktions-Matching & Überwachung

* Laufender, automatisierter Abgleich eingehender Buchungen gegen die aktiven Verträge anhand der hinterlegten Regeln
  und Mandatsnummern.
* Überprüfung, ob erwartete Zahlungen pünktlich eingehen oder Beträge unerwartet abweichen.

### Schritt 4: Zukünftige Ausbaustufe (Assistenz-Funktion)

* Integration einer leichten KI- oder Mustererkennungs-Assistenz: Wenn das System neue, unbekannte Buchungs-Cluster
  bemerkt, schlägt es diese im Dashboard als potenzielle neue Verträge oder Ergänzungen für den Regel-Editor vor.
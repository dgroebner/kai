# Konzept: Multi-Channel Dokumenten-Ingestion, Klassifizierung und Versand an die d.velop Postbox

## 1. Zielsetzung & Systemkontext

Dieses Konzept definiert eine modulare Service-Infrastruktur für das Projekt Kai, um Dokumente aus unterschiedlichen
Quellen (IMAP-Postfächer, externe Schnittstellen wie Comdirect oder interne Fachmodule) automatisiert entgegenzunehmen,
anhand flexibler Datenbank-Regeln zu identifizieren, einheitlich zu benennen und via E-Mail-Weiterleitung inklusive
Ordner-Tags an die persönliche d.velop Postbox zu übermitteln.

Die Steuerung der Dokumentenerkennung, der Zielordner und der Dateibenennung erfolgt vollständig datenbankbasiert über
eine dedizierte Administrations-Oberfläche, um eine wartungsarme und erweiterbare Plattform ohne starre
Umgebungs-Konfigurationen zu gewährleisten.

---

## 2. Architektur-Komponenten

### 2.1 Ingestion Layer (Eingangs-Schicht)

* **IMAP-Kanal:** Periodischer Abruf von E-Mails und Anhängen über die bestehende IMAP-Infrastruktur. Eingehende
  PDF-Anhänge werden an die Classification Engine übergeben.
* **API-Kanal:** Automatisierte Abholung von Dokumenten aus Drittsystemen (z. B. Kontoauszüge und Abrechnungen über die
  Comdirect-Schnittstelle). Sofern die Dokumentenklasse im API-Payload bereits eindeutig feststeht, kann die Zuweisung
  deterministisch erfolgen.
* **Interner Service-Kanal:** Direkte programmatische Übergabe von Dokumenten aus anderen Modulen des Kai-Toolsets (z.
  B. Belege, Reports).

### 2.2 Classification Engine (Klassifizierungs-Dienst)

* Zentrale Logik-Komponente zur Erkennung und Zuordnung von Dokumenten anhand definierter Kriterienkaskaden.
* Liest alle aktiven Regeln aus der Datenbank aus und wertet sie nach konfigurierter Priorität aus.
* Verantwortlich für die Extraktion von Textinhalten (insbesondere der ersten Seite von PDF-Dokumenten), den Abgleich
  von Absendern, Betreffzeilen sowie Pflicht- und Ausschlussbegriffen.
* Liefert bei erfolgreichem Match den passenden Postbox-Tag sowie das Ziel-Namensmuster zurück.

### 2.3 Temporärer Speicher & Lifecycle-Management

* Dokumente werden ausschließlich flüchtig im geschützten Pfad `/storage/postbox_sync/` abgelegt.
* Direkt nach erfolgreicher Weiterleitung oder im Fehlerfall greift eine Bereinigungsroutine, die temporäre Dateien
  rückstandslos entfernt.

### 2.4 Dispatcher & Mail-Outbound

* Nimmt das klassifizierte Dokument und die Metadaten entgegen.
* Generiert die E-Mail an die persönliche d.velop Inbound-Adresse.
* Platziert den führenden Zielordner-Tag im Betreff.
* Hängt das Dokument unter Berücksichtigung des aufgelösten Namensmusters an.

### 2.5 Audit-Trail & Duplikatschutz

* Berechnung und persistente Speicherung eines Dokumenten-Hashwerts (SHA-256) sowie des generierten Dateinamens.
* Verhindert Mehrfachversand identischer Dokumente bei wiederholten Sync-Läufen.
* Protokollierung aller Aktionen über den ActivityLogger.

---

## 3. Datenmodell

Für die dynamische Regelverwaltung und die Historisierung versendeter Dokumente werden zwei relationale Tabellen
benötigt.

### 3.1 Tabelle: `document_rules`

Verwaltet die Klassifizierungsregeln für eingehende Dokumente.

* **id:** Eindeutiger Primärschlüssel (Ganzzahl, Auto-Increment).
* **name:** Sprechende Bezeichnung der Regel (z. B. "Telekom Festnetzrechnung").
* **postbox_tag:** Ordner-Tag für d.velop postbox inklusive führender Raute (z. B. `#festnetzrechnung`).
* **naming_pattern:** Muster für den Ziel-Dateinamen unter Verwendung von Platzhaltern (z. B.
  `{Y-m-d}_Rechnung_Telekom.pdf`).
* **sender_pattern:** Filter auf Absender-Adresse oder Absender-Domain (z. B. `@rechnung.telekom.de` oder
  `@telekom.de`).
* **subject_keywords:** Kommagetrennte Liste von Begriffen, die im Betreff vorkommen müssen (ODER- oder UND-Verknüpfung
  konfigurierbar).
* **required_keywords:** Kommagetrennte Liste von Pflichtbegriffen, die im Text des Dokuments zwingend vorkommen müssen
  (UND-Verknüpfung).
* **exclude_keywords:** Kommagetrennte Liste von Ausschlussbegriffen; ist einer dieser Begriffe enthalten, greift die
  Regel nicht.
* **priority:** Ganzzahliger Wert zur Steuerung der Auswertungsreihenfolge (höhere Werte werden zuerst geprüft).
* **is_active:** Boolscher Statusindikator zur schnellen Deaktivierung einer Regel ohne Löschung.
* **created_at / updated_at:** Zeitstempel für Nachvollziehbarkeit und Auditing.

### 3.2 Tabelle: `postbox_sync_log`

Dient der Protokollierung und dem Duplikatschutz.

* **id:** Eindeutiger Primärschlüssel (Ganzzahl, Auto-Increment).
* **rule_id:** Fremdschlüssel auf `document_rules` (kann Null sein bei unklassifizierten Dokumenten).
* **file_hash:** SHA-256-Hashwert des Dokumenteninhalts.
* **source_channel:** Herkunft des Dokuments (IMAP, API, INTERNAL).
* **original_filename:** Ursprünglicher Dateiname beim Eingang.
* **dispatched_filename:** Tatsächlich verwendeter Dateiname beim Versand an die Postbox.
* **postbox_tag:** Verwendeter Tag im Betreff.
* **dispatched_at:** Zeitpunkt des erfolgreichen E-Mail-Versands.
* **status:** Versandstatus (SUCCESS, FAILED).
* **error_message:** Textuelle Fehlermeldung bei Übertragungsabbrüchen.

---

## 4. Klassifizierungs-Engine & Regelwerk

### 4.1 Ablauf der Regelauswertung

1. **Laden der Regelsätze:** Die Engine lädt alle Datensätze aus `document_rules`, bei denen `is_active` wahr ist,
   absteigend sortiert nach `priority`.
2. **Stufe 1 (Absenderabgleich):** Falls ein `sender_pattern` hinterlegt ist, wird geprüft, ob die
   E-Mail-Absenderadresse bzw. der Domainanteil dem Muster entspricht. Schlägt die Prüfung fehl, wird die Regel
   übersprungen.
3. **Stufe 2 (Betreffabgleich):** Falls `subject_keywords` hinterlegt sind, wird der E-Mail-Betreff geprüft. Schlägt die
   Prüfung fehl, wird die Regel übersprungen.
4. **Stufe 3 (Dokumentenanalyse):**
    * Extraktion des Textinhalts der ersten Seite des PDF-Dokuments.
    * Prüfung auf Vorhandensein aller in `required_keywords` definierten Begriffe.
    * Prüfung auf Abwesenheit aller in `exclude_keywords` definierten Begriffe.
5. **Entscheidung:**
    * Die erste Regel, bei der alle Stufen positiv durchlaufen werden, gewinnt (*First-Match-Win*).
    * Werden keine passenden Regeln gefunden, greift eine Fallback-Strategie: Das Dokument wird ohne Ordner-Tag
      übertragen und landet im Standard-Posteingang der Postbox.

### 4.2 Standard-Regelsets (Initialbefüllung)

* **#visaabrechnung:**
    * *Kriterien:* Signalwörter wie "Kreditkartenabrechnung", "Visa", Kartennummern-Maskierung.
    * *Namensmuster:* `{Y-m-d}_Visa_Abrechnung.pdf`
* **#festnetzrechnung:**
    * *Kriterien:* Provider-Domain, Pflichtbegriffe wie "Festnetz", "Internet", "DSL" oder Festnetz-Rufnummer;
      Ausschlussbegriff "Mobilfunk".
    * *Namensmuster:* `{Y-m-d}_Telekom_Festnetz.pdf`
* **#mobilfunkrechnung:**
    * *Kriterien:* Provider-Domain, Pflichtbegriffe wie "Mobilfunk", Rufnummernformate mobiler Netze; Ausschlussbegriff
      "Festnetz".
    * *Namensmuster:* `{Y-m-d}_Telekom_Mobilfunk.pdf`
* **#alarmrechnung:**
    * *Kriterien:* Absender der Sicherheitsfirma, Begriffe wie "Aufschaltung", "Sicherheitsdienst", "Servicepauschale".
    * *Namensmuster:* `{Y-m-d}_Rechnung_Alarmsystem.pdf`
* **#comdirectauszug:**
    * *Kriterien:* Deterministischer Import über Comdirect-Schnittstelle oder Absender-Verifizierung mit Signalwörtern
      "Finanzreport", "Kontoauszug".
    * *Namensmuster:* `{Y-m-d}_Comdirect_Finanzreport.pdf`

### 4.3 Namensmuster-Auflösung (Naming-Pattern-Engine)

Die Engine unterstützt dynamische Platzhalter zur Vereinheitlichung der Archiv-Dateinamen vor dem Versand:

* `{Y}`, `{m}`, `{d}`: Aktuelles Verarbeitungsdatum (Jahr, Monat, Tag).
* `{Y-m-d}`: ISO-Datum des Imports.
* `{doc_date}`: Aus dem PDF-Text extrahiertes Belegdatum (Fallback auf Verarbeitungsdatum).
* `{original_name}`: Basis-Dateiname ohne Endung bei Eingang.

---

## 5. Administrations-Oberfläche (Admin UI)

Die Konfiguration wird über ein dediziertes Modul innerhalb der Administration von Kai bereitgestellt.

### 5.1 Regel-Übersicht (Listenansicht)

* Tabellarische Darstellung aller angelegten Dokumentenregeln.
* Spalten: Priorität, Name, d.velop Tag, Zieldateiname, Absender-Filter, Status (Aktiv/Inaktiv), Aktionen.
* Möglichkeit zur manuellen Sortierung bzw. Prioritätsänderung.
* Direkter Umschalter (Toggle) zur Aktivierung/Deaktivierung einzelner Regeln ohne Öffnen der Bearbeitungsmaske.
* Filter- und Suchleiste zur schnellen Durchsuchung vorhandener Regeln.

### 5.2 Regel-Editor (Erstellen / Bearbeiten)

* Klare Formularstrukturierung in drei logische Abschnitte:
    * **Basisdaten & Ziel:** Name der Regel, d.velop Tag (mit Validierung auf führendes Rautezeichen und Ausschluss von
      Leerzeichen), Zieldateimuster mit interaktiver Vorschau der Platzhalter.
    * **Eingangsfilter (E-Mail):** Absender-Muster, Betreff-Schlagworte.
    * **Inhaltsfilter (PDF-Volltext):** Pflichtbegriffe (AND-Verknüpfung) und Ausschlussbegriffe (NOT-Verknüpfung) über
      intuitive Tag-Inputs.
* Validierung gegen Zirkelbezüge und unzulässige Sonderzeichen in Dateinamen.

### 5.3 Test- & Simulationsbereich (Dry-Run Modus)

* Interaktives Diagnose-Werkzeug zur Überprüfung von Regelsätzen vor der Produktivnahme.
* **Eingabemöglichkeiten:** Upload einer realen PDF-Datei sowie optionale manuelle Angabe von Absender-Adresse und
  Betreff.
* **Auswertungsergebnis:**
    * Anzeige des extrahierten Textinhalts der ersten Seite.
    * Detailliertes Match-Log: Welche Regeln wurden geprüft, an welchem Kriterium ist eine Regel gescheitert, welche
      Regel hat gewonnen.
    * Vorschau des resultierenden E-Mail-Betreffs (inklusive Tag) und des generierten Dateinamens.

---

## 6. Technische Richtlinien & Systemgrenzen (d.velop Inbound)

* **Tag-Formatierung:** Das Tag muss zwingend mit einem Rautezeichen (`#`) beginnen und darf keinerlei Leerzeichen
  enthalten. Pro E-Mail-Betreff wertet die d.velop Postbox ausschließlich das erste erkannte Tag aus.
* **Größenbeschränkung:** Das Gesamtvolumen einer E-Mail inklusive aller Anhänge darf maximal 20 MB betragen.
* **Dateianhänge:** Es werden ausschließlich reguläre Dateianhänge importiert. Inline-Bilder oder reiner Mailtext ohne
  Anhang werden von der Postbox verworfen.
* **Geheimhaltung:** Die persönliche Inbound-E-Mail-Adresse der Postbox fungiert als Autorisierungsschlüssel und muss
  streng geschützt in den Umgebungsvariablen verwahrt werden.

---

## 7. Antigravity Implementierungs-Roadmap

Die Umsetzung gliedert sich in fünf strukturierte Arbeitspakete, die sequenziell umgesetzt werden können.

### Paket 1: Datenbank & Datenzugriffsschicht

* Erstellung der Datenbankmigration für `document_rules` und `postbox_sync_log` unter Berücksichtigung von Indizes auf
  `priority`, `file_hash` und `is_active`.
* Implementierung des `DocumentRuleRepository` für lesende und schreibende Operationen (Prioritätssortierung, Filterung
  aktiver Regeln, CRUD).
* Implementierung des `PostboxSyncRepository` zur Protokollierung und Duplikatsprüfung via Hashwert.

### Paket 2: Classification Engine & Pattern Resolver

* Implementierung des `DocumentClassifier` zur Orchestrierung der Regelauswertung nach Priorität.
* Implementierung eines `PdfTextExtractor` basierend auf dem bestehenden PDF-Parser zur Extraktion von Text der ersten
  Seite.
* Implementierung eines `NamingPatternResolver` zur Auflösung dynamischer Platzhalter in Zieldateinamen.
* Unit-Tests für die Klassifizierungslogik (Prioritätsreihenfolge, Treffer bei Pflichtbegriffen, Ausschlusskriterien).

### Paket 3: Refaktorisierung Ingestion & Dispatcher-Integration

* Entkopplung der Erkennungslogik aus dem `MailDispatcher` und Anbindung des neuen `DocumentClassifier`.
* Integration der Duplikatsprüfung (`file_hash`) vor Versand.
* Implementierung des `PostboxMailClient` zur sauberen Kapselung des Versands mit Tag-Betreff und Zieldateinamen.
* Sicherstellung des Dateilöschungs-Cleanups im temporären Verzeichnis in allen Ausführungspfaden (Erfolg und Fehler).
* Anbindung des ActivityLoggers für versendete Dokumente.

### Paket 4: Admin-Oberfläche & Regelverwaltung

* Erstellung der Routing-Einträge und des Controllers für die Regelverwaltung im Administrationsbereich.
* Realisierung der Übersichts-View mit Tabellendarstellung, Status-Toggle und Sortierfunktion.
* Realisierung des Regel-Editors für Neuanlage und Bearbeitung inklusive Eingabevalidierung.

### Paket 5: Dry-Run Diagnose-Tooling & Integrationstests

* Implementierung des Dry-Run-Endpunkts und der dazugehörigen Upload- und Testmaske.
* Integrationstest über den gesamten Workflow: Eingang (Mock-Mail/PDF) -> Klassifizierung -> Umbenennung -> Logging ->
  Cleanup.
* Bereitstellung von initialen Migrationsdaten (Seeders) für die fünf Standard-Regeln (#visaabrechnung,
  #festnetzrechnung, #mobilfunkrechnung, #alarmrechnung, #comdirectauszug).
# 🛠️ Implementierungsplan: Girokonto-Regelsystem & Visual Regex Builder

Ein modulares Regelsystem zur automatischen Vorkategorisierung von Girokonto-Umsätzen inklusive visueller Regex-Erstellung, Live-Matches und nahtloser UI-Integration.

---

## 📅 Übersicht der Phasen & Arbeitspakete

[Phase 1: DB & Migration] ➔ [Phase 2: Backend-Logik] ➔ [Phase 3: Visual Builder UI] ➔ [Phase 4: Retroaktiv & Import]

---

## 🗄️ Phase 1: Datenbank-Schema & Migration

* [x] **1.1 Schema-Erweiterung in `database/schema.sql`**
  * `bank_giro_transactions`: Spalte `matched_rule_id INT NULL` mit Foreign Key `FOREIGN KEY (matched_rule_id) REFERENCES bank_tag_rules(id) ON DELETE SET NULL` hinzufügen.
  * Tabelle `bank_tag_rules` absichern (`id`, `payee_pattern`, `text_pattern`, `tag_ids` JSON, `priority`, `created_at`).
* [x] **1.2 Migration auf Live-Datenbank ausführen**
  * Migration-Script für `ALTER TABLE bank_giro_transactions ADD COLUMN matched_rule_id...` bereitstellen und ausführen.

---

## 🧠 Phase 2: Backend-Logik & API (`bank/api.php` / Services)

* [x] **2.1 Rule Matching Engine (PHP-Klasse / Service)**
  * Serviceklasse `RuleMatcher` erstellen, die `text_pattern` und `payee_pattern` per `preg_match()` auswertet.
  * *First-Match-Wins-Prinzip* nach Priorität / ID umsetzen.
* [x] **2.2 API-Endpunkte für Regelerstellung & Verwaltung**
  * `action: 'save_rule'`: Regel anlegen/aktualisieren, Tags in `bank_tag_rules` speichern und geänderte Regel-ID an Transaktion binden.
  * `action: 'delete_rule'`: Regel löschen und Verknüpfungen lösen.
  * `action: 'test_rule_pattern'`: Nimmt Regex-Muster entgegen und liefert die Anzahl & IDs der Umsätze zurück, die im aktuellen Datenbestand matchen würden.

---

## 🎨 Phase 3: Frontend & Visual Regex Builder (`bank.js` & Modal)

* [x] **3.1 Indikator in der Transaktionstabelle (`public/bank/index.php`)**
  * Neue Spalte/Icon in jeder Tabellenzeile:
    * 🪄 *Keine Regel:* graues Zauberstab-Icon (Klick = Neue Regel erstellen).
    * ⚡ *Regel aktiv:* hervorgehobener Blitz mit Tooltip (Klick = Regel bearbeiten).
* [x] **3.2 Modal-Layout für den "Visual Regex Builder"**
  * Anzeige des vollständigen Buchungstextes & Empfängers.
  * Multi-Tag-Auswahlfeld (automatisch vorausgefüllt mit den aktuellen Tags der Zeile).
* [x] **3.3 Interaktiver Text-Marker & Regex-Helper**
  * Buchungstext als wählbare Segmente/Klartext darstellen.
  * Helper-Buttons zur Muster-Generierung:
    * 🔤 *Exakter Text:* Escaped Sonderzeichen automatisch (`preg_quote` / JS-Equivalent).
    * 🔤 *Startet mit:* Fügt `^` hinzu.
    * 🔢 *Zahlen-Platzhalter:* Ersetzt Zahlenreihen durch `\d+`.
    * 🔤 *Wildcard:* Setzt `.*`.
* [x] **3.4 Live-Match Indikator im Modal**
  * Bei jeder Änderung am Muster führt das JS einen schnellen Live-Check durch: *„Diese Regel matcht aktuell X Transaktionen“*.

---

## 🔄 Phase 4: Retroaktive Anwendung & Import-Integration

* [x] **4.1 Retroaktive Anwendung beim Speichern**
  * Beim Erstellen/Ändern einer Regel werden alle regel-losen Transaktionen der Datenbank geprüft und bei Match automatisch mit `matched_rule_id` und den neuen Tags versehen.
  * Beim Verschärfen/Löschen einer Regel werden betroffene Verknüpfungen sauber bereinigt.
* [x] **4.2 Einbindung in den E-Mail- / CSV-Import**
  * Beim Importieren neuer Buchungen läuft die `RuleMatcher`-Engine automatisch drüber, setzt `matched_rule_id` und weist die Tags direkt beim Import zu.
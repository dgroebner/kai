# 🛠️ Implementierungsplan: Sync-Mechanismus Girokonto <-> Kreditkarten-Abrechnung

Automatisches Matching von Kreditkarten-Abrechnungen (`bank_cc_statements`) mit den Abbuchungen auf dem Girokonto (`bank_giro_transactions`) inklusive visueller Status-Indikatoren und Verlinkungen in beiden Ansichten.

---

## 📅 Übersicht der Phasen & Arbeitspakete

[Phase 1: DB & Matcher-Logik] ➔ [Phase 2: Import- & Sync-Hooks] ➔ [Phase 3: UI Kreditkartenansicht] ➔ [Phase 4: UI Girokontoansicht]

---

## 🗄️ Phase 1: Datenbank-Verknüpfung & Matching-Engine

* [x] **1.1 Matcher Service-Klasse erstellen (`src/Tools/Bank/StatementMatcher.php`)**
  * Neue Klasse `StatementMatcher` anlegen.
  * Methode `syncUnlinkedStatements()` zum automatischen Abgleich aller noch unverknüpften Abrechnungen (`bank_transaction_id IS NULL`) erstellen.
  * **Matching-Kriterien:**
    * **Betrag:** Exakt identischer Betrag (`ABS(bank_cc_statements.total_amount) == ABS(bank_giro_transactions.amount)` mit negativem Vorzeichen auf dem Girokonto).
    * **Text-Muster:** `merchant_raw` enthält Regex-Muster wie `/(Solaris|ADAC|Kreditkarte.*Abrechnung)/i`.
    * **Zeitfenster:** Buchungsdatum auf dem Girokonto liegt im Bereich `statement_date` bis max. 14 Tage nach `statement_date`.
  * Bei Treffer: `UPDATE bank_cc_statements SET bank_transaction_id = :giro_id WHERE id = :statement_id`.

---

## 🔄 Phase 2: Integration in Import & Background-Tasks

* [x] **2.1 Hook beim CSV-Import des Girokontos (`BankGiroService.php`)**
  * Nach dem Importieren neuer Girokonto-Umsätze automatisch `$statementMatcher->syncUnlinkedStatements()` aufrufen.
  * Dadurch wird eine neu eingehende Abbuchung sofort mit einer bereits existierenden Abrechnung verknüpft.
* [x] **2.2 Manual Trigger via API (`public/bank/api.php`)**
  * Neue Aktion `action: 'sync_cc_statements'` bereitstellen, um den Sync manuell auszulösen oder bei Bedarf beim Öffnen der Kreditkarten-Übersicht im Hintergrund zu triggern.

---

## 💳 Phase 3: UI-Erweiterung Kreditkarten-Ansichten (`public/bank/creditcard.php` & `detail.php`)

* [x] **3.1 Abbuchungs-Status in der Übersicht (`public/bank/creditcard.php`)**
  * In der Liste der Abrechnungen neue Status-Spalte / Badge hinzufügen:
    * 🟢 **Abgebucht:** Zeigt ein grünes Badge `Abgebucht am DD.MM.YYYY` (Datum der Giro-Transaktion).
    * 🟡 **Offen / Ausstehend:** Zeigt ein gelbes Badge `Offen` (Abbuchung noch nicht auf dem Girokonto eingegangen).
* [x] **3.2 Status & Verlinkung in der Detailansicht (`public/bank/detail.php`)**
  * Im Kopfbereich der Abrechnung den Abbuchungsstatus anzeigen.
  * Falls verknüpft: Direct-Link `[ 🔗 Zur Girokonto-Buchung ]` einbauen, der zu `public/bank/index.php?highlight_tx=:bank_transaction_id` führt.

---

## 🏦 Phase 4: UI-Erweiterung Girokonto-Ansicht (`public/bank/index.php`)

* [ ] **4.1 SQL-Query Erweiterung (`public/bank/index.php`)**
  * `LEFT JOIN bank_cc_statements s ON t.id = s.bank_transaction_id` in die Transaktions-Abfrage aufnehmen, um `s.id AS linked_statement_id` und `s.statement_date` mitzuladen.
* [ ] **4.2 Visueller Link in der Tabelle**
  * Wenn ein Giro-Umsatz mit einer Abrechnung verknüpft ist, im Buchungstext ein dezentes Badge / Icon rendern:
    * `💳 Kreditkarten-Abrechnung (MM/YYYY)` als Link zu `public/bank/detail.php?id=:linked_statement_id`.
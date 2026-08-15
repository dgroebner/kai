# 🛠️ Implementierungskonzept: Sync & Verlinkung E-Bons (Kassenbons) <-> Abbuchungen

Automatischer Abgleich von E-Bons (`receipts`) mit den jeweiligen Abbuchungen auf dem Girokonto (`bank_giro_transactions`) und der Kreditkarte (`bank_cc_transactions`) inklusive visueller Status-Indikatoren und bidirektionaler Verlinkungen in allen Ansichten.

---

## 📅 Übersicht der Phasen & Arbeitspakete

[Phase 1: DB-Schema & Matcher-Engine] ➔ [Phase 2: Sync-Hooks & Automation] ➔ [Phase 3: UI Kassenbon-Ansichten] ➔ [Phase 4: UI Banking-Ansichten]

---

## 🗄️ Phase 1: Datenbank-Erweiterung & ReceiptMatcher Service

* [x] **1.1 DB-Migration / Fremdschlüssel erweitern (`database/schema.sql`)**
  * Tabelle `receipts` um zwei neue Fremdschlüssel-Spalten zur eindeutigen Zuordnung ergänzen:
    * `bank_giro_transaction_id INT NULL DEFAULT NULL`
    * `bank_cc_transaction_id INT NULL DEFAULT NULL`
* [x] **1.2 Matcher Service-Klasse erstellen (`src/Tools/Kassenbon/ReceiptMatcher.php`)**
  * Neue Klasse `ReceiptMatcher` unter `Kai\Tools\Kassenbon` anlegen.
  * Methode `syncUnlinkedReceipts()` für den automatischen Abgleich aller Kassenbons mit `bank_giro_transaction_id IS NULL AND bank_cc_transaction_id IS NULL`.
  * **Matching-Kriterien (Szenario A: Girokonto):**
    * **Betrag:** Exakt identischer Betrag (`ABS(receipts.total_amount) == ABS(bank_giro_transactions.amount)` mit negativem Vorzeichen auf dem Girokonto).
    * **Zeitfenster:** Buchungsdatum auf dem Girokonto liegt zwischen `receipts.purchased_at` und `receipts.purchased_at + 14 Tage`.
    * **Händler-Prüfung:** `merchant_raw` matcht per Name/Ähnlichkeit zum Bon-Händler (z. B. `REWE`, `ALDI`, `Lidl`, `Kaufland`).
    * **Ergebnis:** `UPDATE receipts SET bank_giro_transaction_id = :giro_id WHERE id = :receipt_id`.
  * **Matching-Kriterien (Szenario B: Kreditkarte):**
    * **Betrag:** Exakt identischer Betrag (`ABS(receipts.total_amount) == ABS(bank_cc_transactions.amount)`).
    * **Zeitfenster:** Buchungsdatum auf der Kreditkarte liegt zwischen `receipts.purchased_at` und `receipts.purchased_at + 14 Tage`.
    * **Händler-Prüfung:** `merchant_name` matcht zum Bon-Händler.
    * **Ergebnis:** `UPDATE receipts SET bank_cc_transaction_id = :cc_id WHERE id = :receipt_id`.

---

## 🔄 Phase 2: Integration in Import, Mail-Scanner & API-Endpoints

* [x] **2.1 Hook beim E-Bon-Scan / Parse (`src/Tools/Kassenbon/ScannerTask.php` oder Mail-Dispatcher)**
  * Nach dem erfolgreichen Einlesen eines neuen Kassenbons direkt `$receiptMatcher->syncUnlinkedReceipts()` aufrufen.
* [x] **2.2 Hook beim Giro- & Kreditkarten-Import (`BankGiroService.php` / `CreditCardService.php`)**
  * Nach dem Import neuer Buchungen automatisch `$receiptMatcher->syncUnlinkedReceipts()` ausführen.
* [x] **2.3 API-Aktion & Auto-Sync beim Seitenaufruf (`public/kassenbon/api.php` & `index.php`)**
  * Aktion `action: 'sync_receipts'` in `public/kassenbon/api.php` bereitstellen.
  * Beim Öffnen der Kassenbon-Übersicht (`public/kassenbon/index.php`) den Sync automatisch im Hintergrund ausführen.

---

## 🧾 Phase 3: UI-Erweiterung Kassenbon-Ansichten (`public/kassenbon/index.php` & `detail.php`)

* [x] **3.1 Abbuchungs-Status in der Bon-Übersicht (`public/kassenbon/index.php`)**
  * In der Liste aller Kassenbons Status-Badge analog zur Kreditkarten-Übersicht einbauen:
    * 🟢 **Giro Konto abgebucht (DD.MM.YYYY):** Link zur Girokonto-Buchung.
    * 🟢 **Kreditkarte abgebucht (DD.MM.YYYY):** Link zur Kreditkarten-Abrechnung bzw. Position.
    * 🟡 **Zahlung offen:** Wenn noch keine Abbuchung zugeordnet werden konnte.
* [x] **3.2 Status & Direct-Link in der Bon-Detailansicht (`public/kassenbon/detail.php`)**
  * Im Header der Bon-Detailansicht den Verknüpfungsstatus mit Direct-Link anzeigen:
    * `[ 🔗 Zur Girokonto-Buchung ]` bzw. `[ 🔗 Zur Kreditkarten-Abrechnung ]`.

---

## 🏦 Phase 4: UI-Erweiterung Banking-Ansichten (`Girokonto` & `Kreditkarte`)

* [x] **4.1 Verlinkung auf dem Girokonto (`public/bank/index.php`)**
  * SQL-Abfrage erweitern: `LEFT JOIN receipts r ON bt.id = r.bank_giro_transaction_id`.
  * Wenn ein Bon verknüpft ist, im Buchungstext ein Badge rendern:
    * `🧾 E-Bon vorhanden (#ID)` als Link zu `public/kassenbon/detail.php?id=:receipt_id`.
* [x] **4.2 Verlinkung auf der Kreditkarten-Detailseite (`public/bank/detail.php`)**
  * SQL-Abfrage erweitern: `LEFT JOIN receipts r ON t.id = r.bank_cc_transaction_id`.
  * In der Positionen-Tabelle bei passender Zeile ein Badge rendern:
    * `🧾 E-Bon vorhanden (#ID)` als Link zu `public/kassenbon/detail.php?id=:receipt_id`.
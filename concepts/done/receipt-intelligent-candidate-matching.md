# 🛠️ Implementierungskonzept: Kandidaten-Matching & Manuelle Zuordnung von E-Bons

Das automatische Matching von Kassenbons scheitert bei Abweichungen zwischen Bon-Summe und Bank-Abbuchung (z. B. durch eingelöstes Guthaben/Rabatte oder Bargeldauszahlungen an der Kasse). Dieses Konzept führt ein Kandidaten-Matching mit manueller Auswahllogik und Auto-Tagging im UI ein.

---

## 🎯 Ziele

1. Intelligente Kandidaten-Findung: Suche nach Buchungen im Zeitfenster (0 bis +10 Tage) mit Händler-Übereinstimmung, auch wenn der Betrag abweicht.
2. Kandidaten-Badge im UI: Anzeige von `🟡 1 Kandidat` bzw. `🟡 N Kandidaten` statt des statischen `🟡 Offen`.
3. Interaktiver Zuordnungs-Dialog: Modal/Popover zur manuellen Auswahl der passenden Abbuchung per Klick.
4. Auto-Aktionen bei Differenzen:
   - Bargeld-Erkennung: Abbuchung > Bon-Summe -> Bietet an, die Differenz direkt mit dem Tag Bargeld zu versehen.
   - Rabatt-Erkennung: Abbuchung < Bon-Summe -> Speichert den Bezug trotz Summenabweichung.

---

## 🗄️ Phase 1: Service-Erweiterung (ReceiptMatcher.php)

Erweiterung der Klasse Kai\Tools\Kassenbon\ReceiptMatcher um Methoden zur Kandidatensuche und manuelle Verknüpfung.

### 1.1 Kandidaten-Suche (getCandidatesForReceipt)

Ablauf der Suche nach Zuordnungskandidaten für einen offenen Bon:
- Zeitfenster: Kaufdatum des Bons bis +10 Tage (Buchungstage auf dem Konto).
- Händler-Matching: LIKE-Suche auf merchant_raw (Giro) und merchant_name (Kreditkarte).
- Prüfung auf Exaktheit: Berücksichtigung des Kaufdatums im Buchungstext (z. B. 03.07 17.11 im REWE-Verwendungszweck).

PHP-Logik für die Suche:
- Lädt den Bon (Datum, Total, Store) aus kb_receipts.
- Ermittelt nicht-verknüpfte Giro-Transaktionen aus bank_giro_transactions im Zeitraum (booking_date BETWEEN purchase_date AND purchase_date + 10 Tage) mit LIKE %store%.
- Ermittelt nicht-verknüpfte Kreditkarten-Transaktionen aus bank_cc_transactions im selben Zeitraum mit LIKE %store%.
- Liefert ein Array ['giro' => [...], 'cc' => [...]] zurück.

### 1.2 Manuelle Verknüpfungs-Methode (linkReceiptManually)

- Verknüpft kb_receipts mit bank_giro_transaction_id oder bank_cc_transaction_id.
- Wenn die optionale Flag apply_cash_tag gesetzt ist (Abbuchung > Bon-Summe), wird der Transaktion automatisch das Tag Bargeld (bzw. entsprechende tag_id) in bank_transaction_tags zugewiesen.

---

## 🔌 Phase 2: API Endpoints (public/kassenbon/api.php)

Erweiterung der API um zwei neue Aktionen:

### 2.1 action: get_candidates
- Input: { "action": "get_candidates", "receipt_id": 123 }
- Output: { "success": true, "candidates": { "giro": [...], "cc": [...] } }

### 2.2 action: link_manual
- Input: { "action": "link_manual", "receipt_id": 123, "tx_id": 456, "account_type": "giro", "apply_cash_tag": true }
- Output: { "success": true }

---

## 💻 Phase 3: Frontend UI (public/kassenbon/index.php & kassenbon.js)

### 3.1 Anpassung der Bon-Übersicht (index.php)
Erweiterung der Query zur Ermittlung der Anzahl passender Kandidaten pro Bon für den Status-Button:
- Wenn bank_giro_transaction_id oder bank_cc_transaction_id gesetzt -> 🟢 Girokonto / Kreditkarte.
- Wenn unlinked, aber Kandidaten vorhanden -> 🟡 N Kandidat(en) (Button löst Modal aus).
- Wenn unlinked und 0 Kandidaten -> 🟡 Offen (Button löst ebenfalls Modal/Suchdialog aus).

### 3.2 Zuordnungs-Modal (kassenbon.js)

Beim Klick auf das gelbe Status-Badge öffnet sich ein Modal mit folgender Funktionalität:
1. Kopfzeile: Bon-Details (Händler, Kaufdatum, Bon-Betrag z. B. 31,97 €).
2. Kandidaten-Liste:
   - Zeigt gefundene Buchungen mit Datum, Buchungstext und Abbuchungsbetrag.
   - Berechnet direkt die Differenz:
     - Differenz > 0: Eingelöstes Guthaben / Rabatt: X,XX €
     - Differenz < 0: Mögliche Bargeldauszahlung: X,XX €
3. Aktions-Buttons pro Kandidat:
   - Verknüpfen
   - Verknüpfen & Tag "Bargeld" setzen (falls Bargeld erkannt wurde).

---

## 📅 Fahrplan / Checkliste

- [ ] Phase 1: ReceiptMatcher.php um Kandidaten-Suchlogik und manuelle Verknüpfung erweitern.
- [ ] Phase 2: API-Aktionen (get_candidates, link_manual) in public/kassenbon/api.php einbauen.
- [ ] Phase 3: Modal-Dialog und Event-Handling in public/js/kassenbon.js implementieren.
- [ ] Phase 4: public/kassenbon/index.php um Kandidaten-Badge erweitern.
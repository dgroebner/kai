# Vollständiges Implementierungskonzept: Migration comdirect API, Code-Cleanup & Sichere Credential-Speicherung

## 1. Zielsetzung und Rahmenbedingungen
- **Ablösung des CSV-Imports:** Ersetzung des manuellen Datei-Uploads durch eine direkte API-Anbindung[cite: 3].
- **Trigger:** Manueller "Sync"-Button im Frontend auf der Girokontoseite.
- **Authentifizierung:** Nutzung der photoTAN-App zur Freigabe (keine manuelle TAN-Eingabe im Code notwendig, da photoTAN-Push unterstützt wird)[cite: 3].
- **Konten-Umfang:** Girokonto und Tagesgeldkonto, inklusive Speicherung der Echtzeitsalden[cite: 3].
- **Sichere Credential-Speicherung:** Comdirect-Zugangsdaten (Zugangsnummer & PIN) werden niemals im Klartext in der `.env` abgelegt, sondern einmalig per Sync-PIN verschlüsselt (AES-256 / Sodium) in der Datenbank gespeichert und zur Laufzeit temporär entschlüsselt.
- **Beibehaltung bestehender Logiken:** Einbindung von Kategorisierung, Aktivitäts-Log (`activity_log`) und E-Bon-Zuordnung (`kb_receipts`)[cite: 1, 5].
- **AGENTS.MD:** Unbedingte Einhaltung der Regeln aus der AGENTS.MD Datei des Projektes.
- **Datenbankanpassung:** Da die Datenbank bereits in Benutzung ist, werden manuelle Migrationsskripte im Ordner `database/` bereitgestellt. Die `schema.sql` wird so angepasst, dass ein vollständiges Initialsetup möglich ist (kein direkter Quellcode im Konzept, sondern rein konzeptionelle Beschreibung).
- **comdirect API** Die comdirect API ist in der beiliegenden `comdirect_rest_api_swagger.json` dokumentiert.
- **TAN-Verfahren** Das eingesetzte TAN-Verfahren ist photoTAN-Push. Nach 2 Fehlversuchen darf kein dritter Versuch erfolgen. Es muss der Hinweis bestätigt werden, dass eine erfolgreiche TAN-Bestätigung auf der comdirect-Webseite erfolgen muss um eine Kontosperrung zu verhindern.

---

## 2. Datenbank-Anpassungen & Schema-Erweiterung
- [x] **2.1 Erweiterung von `bank_giro_transactions`:** Ergänzung um eine Account-Verknüpfung (`account_id`) sowie die API-eigene Transaktions-ID (`transaction_id`) als eindeutiger Schlüssel zur automatischen Dublettenvermeidung.
- [x] **2.2 Erweiterung von `bank_accounts`:** Hinzufügen von Spalten für die verschlüsselten Zugangsdaten sowie den aktuellen Kontostand (`current_balance`).
- [x] **2.3 Initialisierung:** Anlegen der Standard-Konten für Girokonto (`checking`) und Tagesgeld (`savings`) und Zuordnung der historischen Buchungen.

---

## 3. Konfiguration & Sichere Credential-Persistenz (.env & Cache)
- [ ] **3.1 Statische App-Konfiguration (`.env`):** Es werden *keine* Benutzerdaten oder Passwörter in der `.env` hinterlegt, sondern lediglich allgemeine App-Konfigurationen und Client-IDs (COMDIRECT_CLIENT_ID, COMDIRECT_CLIENT_SECRET) [cite: 1].
- [ ] **3.2 Dynamische Tokens:** Speicherung von `access_token` und `refresh_token` in einer geschützten Cache-Datei (`storage/tokens.json`), um den OAuth2-Flow mit Token-Refresh abzusichern[cite: 3].
- [ ] **3.3 Verschlüsselte Credentials:** Die Zugangsdaten (comdorect Zugangsnummer und Zugangs-pin) werden über eine Admin-/Setup-Maske mit einer geheimen Sync-PIN verschlüsselt (AES-256 / Sodium) in der Datenbank hinterlegt. Bei der Ausführung werden sie temporär in der PHP-Session gehalten und nach dem API-Aufruf sofort aus dem RAM gelöscht.

---

## 4. Bereinigungs-Plan (Code-Cleanup)
- [ ] **4.1 Entfernung:** Löschung der Parser-Klasse für CSV-Dateien sowie aller dazugehörigen CSV-spezifischen Hilfsfunktionen[cite: 1].
- [ ] **4.2 Entfernung:** Löschung des alten Datei-Upload-Endpunkts und der entsprechenden Frontend-Oberflächenelemente für den manuellen CSV-Import.
- [ ] **4.3 Refactoring:** Das Transaktions-Repository wird von jeglichen Dateisystem-Abhängigkeiten befreit und ruft die Daten direkt über den neuen API-Client ab[cite: 1].

---

## 5. Ablauf des API-Syncs (Button-Auslösung)
- [ ] **5.1 Authentifizierung / Session:** Der Nutzer löst den Sync per Button aus und gibt die Sync-PIN ein. Der Client validiert die Tokens, führt bei Bedarf einen Refresh durch oder initialisiert den Login über den passwortbasierten Flow[cite: 3].
- [ ] **5.2 Konten- & Salden-Abgleich:** Abruf der Konten und Salden über die entsprechende API-Ressource und Aktualisierung der Salden in der Account-Tabelle[cite: 3].
- [ ] **5.3 Transaktions-Import:** Abruf der Transaktionen für Giro- und Tagesgeldkonto, Transformation und Speicherung in der Datenbank unter Nutzung der API-Transaktions-IDs zur Dublettenvermeidung[cite: 3].
- [ ] **5.4 Nachgelagerte Services:** Automatische Ausführung des Regel-Gedächtnisses zur Kategorisierung, Zuordnung von E-Bons und Dokumentation des Vorgangs im Aktivitäts-Log[cite: 1, 5].
- [ ] **5.5 Visualisierung des Sync-Prozesses** Die einzelnen Schritte des Sync-Prozesses sollen in der Sync-Maske dargestellt werden, dabei werden alle Punkte einzeln in einer Liste aufgeführt und bei Abschluss mit einem grünen Haken versehen. Bei Fehlern bricht der Syncprozess ab und der Schritt wird mit einem roten x versehen. Dadurch ist der Fortschritt des Syncprozesses transparent.

---

## 6. UI-Erweiterung für Multi-Konto-Banking (Giro & Tagesgeld)
- [ ] **6.1 Dynamische Tabs:** Erweiterung der Banking-Oberfläche, um über ein dynamisches Tab-System (basierend auf den Einträgen in `bank_accounts`) nahtlos zwischen Girokonto und Tagesgeldkonto zu wechseln.
- [ ] **6.2 Daten-Isolation:** Anpassung der Datenabfragen im Backend, sodass die Ansichten und Paginierungen strikt nach dem jeweils ausgewählten Konto gefiltert werden.
- [ ] **6.3 System-Konfiguration** Umwandlung der "Aktivitäts-Log" in eine "System"-Karte mit dem bisherigen Aktivitäts-Log auf der Einstiegsseite, aber einen Reiterwechsel wie in der Bankseite für eine Setup-Maske. Hier als erstes Konfigurationssegment die Erfassung der comdirect Zugangsdaten und Sync-PIN Konfiguration hinterlegen.
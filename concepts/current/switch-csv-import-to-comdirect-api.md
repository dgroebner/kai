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

## 3. Konfiguration & Sichere Credential-Persistenz
- [x] **3.1 Statische App-Konfiguration (`.env`):** Es werden *keine* Benutzerdaten oder Passwörter in der `.env` hinterlegt, sondern lediglich allgemeine App-Konfigurationen und Client-IDs (`COMDIRECT_CLIENT_ID`, `COMDIRECT_CLIENT_SECRET`).
- [x] **3.2 Token-basierte Persistenz & Sicherheit:** 
  - **Keine dauerhafte PIN-Speicherung:** Um das Risiko im Falle eines Datenlecks zu minimieren, werden die sensiblen Bank-Zugangsdaten (Zugangsnummer & PIN) *nicht* dauerhaft in der Datenbank gespeichert. Sie werden ausschließlich beim initialen Setup-Login im Arbeitsspeicher (RAM) verarbeitet, um den OAuth2-Flow und die photoTAN-Freigabe anzustoßen, und danach sofort verworfen.
  - [x] **Verschlüsselte Token-Speicherung (`bank_accounts.api_credentials`):** Persistiert werden lediglich die dynamischen OAuth2-Tokens (`access_token` und `refresh_token`) als verschlüsselter JSON-Blob in der Datenbank. 
  - **Verschlüsselungsverfahren:** Die Tokens werden mittels moderner Kryptografie (Sodium/AES-256-GCM) geschützt. Der automatisierte Cron-Job nutzt im Alltag ausschließlich den `refresh_token`, um neue `access_tokens` abzurufen, ohne jemals Zugriff auf die PIN zu benötigen.

---

## 4. Bereinigungs-Plan (Code-Cleanup)
- [ ] **4.1 Entfernung:** Löschung der Parser-Klasse für CSV-Dateien sowie aller dazugehörigen CSV-spezifischen Hilfsfunktionen[cite: 1].
- [ ] **4.2 Entfernung:** Löschung des alten Datei-Upload-Endpunkts und der entsprechenden Frontend-Oberflächenelemente für den manuellen CSV-Import.
- [ ] **4.3 Refactoring:** Das Transaktions-Repository wird von jeglichen Dateisystem-Abhängigkeiten befreit und ruft die Daten direkt über den neuen API-Client ab[cite: 1].

---

## 5. Ablauf des API-Syncs (Manuell & Automatisiert)
- [ ] **5.1 Auslöser (Manuell & Cron-Job):** Generell besteht neben einem automatisierten Prozess (Cron-Job) stets die manuelle Sync-Möglichkeit per Button auf der Girokontoseite.
- [ ] **5.2 Automatisierter Sync (Cron-Job):** Ein Cron-Job aktualisiert die Salden und Buchungen automatisch im Hintergrund. Dieser Job führt den Sync jedoch nur aus, solange der gespeicherte `refresh_token` noch gültig ist. Der Cronjob wird extern verwaltet. Stelle nur den API-Endpunkt mit GET und dem bereits vorhandenen API-Token aus der Umgebung bereit.
- [ ] **5.3 Token-Ablauf & Fehlerbehandlung:** Nur bei einem gültigen Token wird der eigentliche Datenabruf gestartet. Läuft der `refresh_token` ab, bricht der Cron-Job ab und schreibt eine eindeutige Meldung in das Aktivitäts-Log (`activity_log`). Diese Meldung enthält einen direkten Link zur Setup-Maske, damit der Nutzer das Token per manuellem Login aktualisieren kann.
- [ ] **5.4 Authentifizierung / Session (Manuell):** Der Nutzer löst den Sync per Button aus. Läuft das Refresh-Token innerhalb der nächsten 10 Minuten aus, wird die Erfassung der comdirect Zugangsdaten eingefordert[cite: 17]. Der Client validiert die Tokens, führt bei Bedarf einen Refresh durch oder initialisiert den Login über den passwortbasierten Flow[cite: 17].
- [ ] **5.5 Konten- & Salden-Abgleich:** Abruf der Konten und Salden über die entsprechende API-Ressource und Aktualisierung der Salden in der Account-Tabelle[cite: 17].
- [ ] **5.6 Transaktions-Import:** Abruf der Transaktionen für Giro- und Tagesgeldkonto, Transformation und Speicherung in der Datenbank unter Nutzung der API-Transaktions-IDs zur Dublettenvermeidung[cite: 17]. Zur Vermeidung von Dubletten aus dem CSV-Import zu vermeiden, werden nur Transaktionen mit Buchungsdatum **nach dem 15.08.2026** über die API verarbeitet. Ältere Buchungen werden ignoriert.
- [ ] **5.7 Nachgelagerte Services:** Automatische Ausführung des Regel-Gedächtnisses zur Kategorisierung, Zuordnung von E-Bons und Dokumentation des Vorgangs im Aktivitäts-Log[cite: 17].
- [ ] **5.8 Visualisierung des manuellen Sync-Prozesses:** Die einzelnen Schritte des Sync-Prozess werden im modalen Dialag anhand einer Checkliste visualisiert.

---

## 6. UI-Erweiterung für Multi-Konto-Banking (Giro & Tagesgeld)
- [ ] **6.1 Dynamische Tabs:** Erweiterung der Banking-Oberfläche um eine Übersicht für das Tagesgeldkonto.
- [ ] **6.2 Daten-Isolation:** Anpassung der Datenabfragen im Backend, sodass die Ansichten und Paginierungen strikt nach dem jeweils ausgewählten Konto gefiltert werden.
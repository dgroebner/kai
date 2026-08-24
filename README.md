# kai — Privates Web-Tool-Set (Dashboard)

**kai** ist eine private, PHP-basierte Heimanwendung für den Eigengebrauch. Sie bündelt mehrere unabhängige Werkzeuge unter einer gemeinsamen Authentifizierungs- und Infrastrukturschicht.

## 🚀 Die Module im Überblick

| Modul | Einstiegspunkt | Beschreibung |
|---|---|---|
| 🛒 **eBons (Kassenbons)** | `kassenbon/index.php` | Automatische KI-Auswertung von Haushalts-Kassenbons per E-Mail (IMAP) mit Einzelpreis- und Kategorie-Erfassung über Google Gemini. Erfasste Bons werden automatisch mit der passenden Giro- oder Kreditkartenbuchung verknüpft (inkl. Bargeld-Erkennung). |
| 📈 **Bon-Auswertung** | `kassenbon/auswertung.php` | Dashboard zur grafischen Visualisierung der erfassten Einkäufe nach Zeiträumen und Kategorien (Donut-Chart, Kategorie-Drilldown). |
| 🏦 **Finanzen (Bank)** | `bank/index.php` | Girokonto-Umsätze über die **comdirect REST API** (photoTAN-Push-Login), automatische Verschlagwortung per Regelsystem und KI-Tag-Klassifizierung, Tag-Auswertung nach Zeitraum sowie Erkennung wiederkehrender Verträge. |
| 💳 **Kreditkarte** | `bank/creditcard.php` | Einlesen und Auswertung von Visa-Kreditkartenabrechnungen (PDF-Parsing per Gemini) inklusive Umsatzübersicht je Abrechnungszeitraum und automatischer Verknüpfung mit der Giro-Lastschrift. |
| 📄 **Verträge** | `bank/contracts.php` | Verwaltung wiederkehrender Zahlungen (Verträge) mit eigenem Regel-Editor und Zuordnung der zugehörigen Buchungen. |
| ⚡ **Energie-Dashboard** | `pvcharge/index.php` | Live-Telemetrie der Photovoltaikanlage (PV-Leistung, Hauslast, Netzbezug/-einspeisung, Batterie-SoC) mit Energiefluss-Diagramm sowie Ertragsprognose über die forecast.solar API inkl. Soll-/Ist-Vergleich. |
| 🚐 **VW ID.Buzz Telemetrie** | `car/index.php` | Live-Fahrzeugstatus (Ladestand, Reichweite, Temperaturen, Verriegelung) und Verlaufshistorie inkl. Effizienz-Auswertung. |
| ⚙️ **System & Verwaltung** | `system/index.php` | Aktivitäts-Log aller System-Ereignisse sowie Pflege globaler Parameter (z. B. Strom-Bezugs- und Einspeisepreise) über den `system_settings`-Key-Value-Store. |

### Maschinen- & Cron-Endpunkte

Diese Endpunkte besitzen keine Benutzersession und werden über den `CRON_TOKEN` abgesichert
(Query-Parameter `?token=`, Header `X-API-Key` oder `Authorization: Bearer`).

| Endpunkt | Methode | Zweck |
|---|---|---|
| `shared/mail.php` | GET | Zentraler Cron-Trigger: liest das IMAP-Postfach und verteilt Kassenbons, Kreditkartenabrechnungen und Bankdaten über den `MailDispatcher`. Läuft nach dem Senden der Antwort asynchron weiter. |
| `pvcharge/cron_forecast.php` | GET | Holt die Tages- und Stundenprognose von forecast.solar und schreibt sie in die Datenbank. |
| `pvcharge/ingest.php` | POST | Nimmt Live- und Telemetriedaten der PV-Anlage entgegen (`{"type":"live\|telemetry","data":{…}}`). Spaltennamen werden gegen eine Allowlist geprüft. |
| `car/telemetry/index.php` | POST | Nimmt die Telemetrie des VW ID.Buzz entgegen. Akzeptiert den Token **nur** per Header, nicht als Query-Parameter. |

---

## 🛠️ Systemarchitektur & Verzeichnisstruktur

Das Projekt folgt einer strikten Trennung zwischen öffentlich erreichbarem Code und geschützter Server-Logik:

```
kai_root/
├── public/                  ← Document Root des Webservers (einziges öffentlich erreichbares Verzeichnis)
│   ├── .htaccess            ← Erzwingt HTTPS und setzt Security-Header (CSP, HSTS)
│   ├── index.php            ← Haupt-Dashboard (Kachel-Übersicht aller Module)
│   ├── login.php            ← Google OAuth Login-Controller (inkl. Logout & state-Prüfung)
│   ├── css/                 ← Zentrales Styleschema (style.css)
│   ├── js/                  ← Frontend-Logik je Modul (Event Delegation, keine Secrets)
│   │                          http.js stellt KaiHttp (CSRF-POST) und KaiHtml (Escaping) bereit
│   ├── shared/              ← Domainübergreifende Endpunkte (mail.php als Cron-Trigger)
│   ├── bank/                ← Controller für Giro, Kreditkarte, Verträge und die Bank-API
│   ├── car/                 ← Controller für das VW ID.Buzz Modul
│   │   └── telemetry/       ← JSON-Endpunkt zur Entgegennahme der Fahrzeug-Telemetrie
│   ├── kassenbon/           ← Controller & API für Kassenbons
│   ├── pvcharge/            ← Controller, Live-API, Ingest- und Cron-Endpunkt der PV-Anlage
│   └── system/              ← Aktivitäts-Log und globale Systemeinstellungen
│
├── src/                     ← Core-Servercode (nicht öffentlich erreichbar, PSR-4: Kai\Tools\)
│   ├── Shared/              ← Domainübergreifende Komponenten
│   │   ├── AI/              ← GeminiClient
│   │   ├── Db/              ← Database (PDO-Singleton)
│   │   ├── Log/             ← Logger (Datei-Log), ActivityLogger (Aktivitäts-Log in der DB)
│   │   ├── Mail/            ← ImapClient, MailDispatcher
│   │   └── Security/        ← Auth (Guards, CSRF, Cron-Token), Sanitizer, TokenEncryptionService
│   ├── Bank/                ← Giro- & Kreditkarten-Logik, Regel-/Vertrags-Matching, ComdirectClient
│   │   └── Parser/          ← VisaPdfParser
│   ├── Car/                 ← Repositories für das ID.Buzz Modul
│   ├── Kassenbon/           ← Bon-Analyse (Gemini), Kategorie-Auswertung und Buchungs-Matching
│   ├── PVCharge/            ← Solarprognose und Telemetrie-Ingest der PV-Anlage
│   └── System/              ← Aktivitäts-Log-Repository und System-Einstellungen
│
├── database/
│   └── schema.sql           ← Versioniertes Datenbankschema (MariaDB/MySQL)
├── concepts/                ← Konzept- und Planungsdokumente (vom Deployment ausgeschlossen)
├── storage/                 ← Lokale Logs (vom Deployment ausgeschlossen)
├── AGENTS.md                ← Verbindlicher Leitfaden für KI-Agenten (Architektur & Konventionen)
├── bootstrap.php            ← Zentraler Einstiegspunkt (.env laden, Session starten, APP_VERSION)
├── composer.json
└── .env.example             ← Vorlage für die lokale .env-Datei
```

> [!IMPORTANT]
> **Strikte Trennung von Backend & Browser:**  
> Das Verzeichnis `public/` ist der einzige Ort, den der Webserver direkt ausliefern darf. PHP-Dateien in `public/` dienen ausschließlich als dünne **Controller**: Sie prüfen die Berechtigung, nehmen Requests entgegen, delegieren an Klassen in `src/` und antworten (HTML oder JSON). Führe niemals Datenbankabfragen oder Business-Logik direkt im `public/`-Ordner aus.

### Domain-Trennung

Jede fachliche Domäne ist ein eigenständiges Modul: Namespace `Kai\Tools\{Domain}\`, Servercode in
`src/{Domain}/`, Einstiegspunkte in `public/{domain}/`. Aktuelle Domains sind **Bank**, **Car**,
**Kassenbon**, **PVCharge** und **System**. Domains dürfen **nicht** direkt Klassen einer anderen
Domain importieren — geteilte Funktionalität wird ausschließlich über `src/Shared/` bezogen.
Die wenigen bewusst gesetzten Ausnahmen (z. B. Bon-zu-Buchung-Matching) sind in AGENTS.md § 4 dokumentiert.

> Die vollständigen Architektur-, Namens- und Sicherheitskonventionen sind in **[AGENTS.md](AGENTS.md)** beschrieben.

---

## 💻 Installation & Lokales Setup

### Voraussetzungen
* PHP 8.4 oder höher mit den Erweiterungen `curl`, `pdo_mysql`, `mbstring`, `json`, `sodium`
  (`sodium` wird für die verschlüsselte Ablage der comdirect-API-Tokens benötigt)
* MySQL oder MariaDB Datenbank
* Composer zur Paketverwaltung
* Google Cloud Console Projekt (für Google OAuth)
* Google AI Studio Account (für Gemini API)
* Optional: comdirect-Entwicklerzugang (nur für den Girokonto-Sync)

### Setup-Schritte
1. **Repository klonen**
2. **Abhängigkeiten installieren:**
   ```bash
   composer install
   ```
3. **Datenbank einrichten:**  
   Erstelle eine MySQL/MariaDB-Datenbank und importiere das Schema aus `database/schema.sql`.
4. **Konfiguration:**  
   Kopiere `.env.example` zu `.env` und passe alle Zugangsdaten und Pfade an:
   ```bash
   cp .env.example .env
   ```
   Den Schlüssel für die Token-Verschlüsselung (`BANK_ENCRYPTION_KEY`) erzeugst du mit:
   ```bash
   php -r "echo base64_encode(random_bytes(32));"
   ```
5. **Webserver einrichten:**  
   Konfiguriere deinen lokalen Webserver (z. B. Apache) so, dass der Document Root auf den Ordner `public/` zeigt.

### Codeprüfung

Das Projekt besitzt keine automatisierte Testsuite. Änderungen werden vor dem Commit syntaktisch geprüft:

```bash
php -l pfad/zur/datei.php      # PHP-Dateien in public/ und src/
node --check public/js/datei.js # JavaScript-Dateien
```

---

## 🗄️ Datenmodell (Kurzüberblick)

Alle Tabellen liegen in `database/schema.sql` (Single Source of Truth, kein Migrations-Tool).

| Bereich | Tabellen |
|---|---|
| Kassenbon | `kb_receipts`, `kb_items` |
| Bank | `bank_accounts`, `bank_giro_transactions`, `bank_cc_statements`, `bank_cc_transactions`, `bank_categories`, `bank_tags`, `bank_transaction_tags`, `bank_tag_rules`, `bank_contracts`, `bank_contract_rules` |
| PV-Anlage | `pv_live`, `pv_telemetry`, `pv_forecast_daily`, `pv_forecast_hourly` |
| Fahrzeug | `vehicle_state`, `vehicle_telemetry_log` |
| System | `activity_log`, `system_settings` |

---

## 🔒 Security Guidelines (für Entwickler)

Beim Hinzufügen neuer Features müssen folgende Sicherheitsrichtlinien zwingend beachtet werden:

### 1. Absicherung der Endpunkte
Jeder Endpunkt in `public/` (ausgenommen `login.php`) muss als **erstes** die Autorisierung über die
Klasse `Kai\Tools\Shared\Security\Auth` prüfen — niemals über handgeschriebene Session-Abfragen:

```php
Auth::requirePage();          // HTML-Seiten: Redirect nach login.php
Auth::requireApi();           // JSON-Endpunkte: antwortet mit 401
Auth::requireMethod('POST');  // erzwingt die HTTP-Methode (405)
Auth::requireCronToken();     // Cron-/Maschinen-Endpunkte über CRON_TOKEN
```

Der Zugang ist zusätzlich durch eine E-Mail-Allowlist (`ALLOWED_USERS`) beschränkt; der OAuth-Flow
prüft einen `state`-Parameter zeitkonstant und ruft nach dem Login `session_regenerate_id(true)` auf.

### 2. Vermeidung von SQL-Injections
Verwende für **alle** Datenbankoperationen PDO Prepared Statements. Setze niemals Werte per String-Interpolation direkt in SQL-Queries ein.
```php
// ✅ Richtig
$stmt = $pdo->prepare("SELECT * FROM kb_items WHERE id = :id");
$stmt->execute([':id' => $id]);
```
Tabellen- und Spaltennamen lassen sich nicht als Parameter binden. Wo sie dynamisch sein müssen
(z. B. beim PV-Ingest), ist eine **Allowlist** die einzige zulässige Absicherung — siehe
`PvIngestService::ALLOWED_COLUMNS`.

### 3. CSRF-Schutz
Alle POST-Anfragen (Formulare und AJAX-Endpunkte), die den Systemzustand verändern, müssen durch einen CSRF-Token gesichert werden.
* Token bereitstellen: `Auth::csrfToken()` — als `<meta name="csrf-token">` (AJAX) oder Hidden-Field (Formular).
* AJAX-Aufrufe laufen ausschließlich über `KaiHttp.postJson()` aus `public/js/http.js`; der Helfer hängt den Header `X-CSRF-Token` automatisch an.
* Serverseitig zeitkonstant prüfen: `Auth::requireCsrfToken($payload)` bzw. `Auth::isValidCsrfToken($token)`.

### 4. Keine rohen Fehlermeldungen (Information Disclosure)
Fehlermeldungen oder Stack-Traces dürfen niemals an den Browser übergeben werden.
`$e->getMessage()` gehört ausschließlich ins Log:
```php
} catch (\Throwable $e) {
    $logger->error('modul/datei.php: Beschreibung', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}
```
`bootstrap.php` registriert zusätzlich einen globalen `set_exception_handler` als Auffangnetz.

### 5. Cross-Site Scripting (XSS) verhindern
Jede Ausgabe von dynamischen Inhalten (z. B. vom Benutzer manipulierte Strings oder KI-Ergebnisse) in HTML muss escaped werden:

```php
<td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
```

* **In JavaScript:** Werte, die in `innerHTML`-Templates oder HTML-Attribute eingesetzt werden, immer über `KaiHtml.escape()` führen (aus `public/js/http.js`). Jede Seite, die ein Modul-Skript lädt, muss `http.js` **davor** einbinden.
* **Farbwerte** landen in `style`-Attributen, wo `htmlspecialchars()` nicht ausreicht. Sie werden per Whitelist normalisiert: `Sanitizer::hexColor()` (PHP) bzw. `KaiHtml.hexColor()` (JS).
* **Keine Inline-Event-Handler** (`onclick="…"`) und keine `<script>`-Blöcke im HTML — die Content-Security-Policy (`script-src-attr 'none'`) blockiert sie. Events werden per Event Delegation in `public/js/` gebunden.


---

---

## 🌐 Externe Dienste

| Dienst | Zweck | Übermittelte Daten |
|---|---|---|
| Google OAuth | Authentifizierung | E-Mail-Adresse, Name |
| Google Gemini API | KI-Analyse (Kassenbons, Kreditkarten-PDFs, Tag-Klassifizierung) | Bon- und Abrechnungsinhalte, Buchungstexte |
| comdirect REST API | Abruf von Girokonto-Umsätzen und Kontostand | Zugangsnummer/PIN beim Login, photoTAN-Freigabe |
| forecast.solar | Solarertragsprognose | GPS-Koordinaten und Anlagenparameter |
| IMAP-Postfach | Eingang von E-Bons und Abrechnungen | E-Mail-Inhalte und Anhänge |
| Hosting-Anbieter | Server & Datenbank | Alle gespeicherten Daten |

> Neue Drittanbieter sind zusätzlich in `AGENTS.md` (Abschnitt 7.3) einzutragen, bevor sie in den Code integriert werden.

---

## 🚀 Deployment (CI/CD)

Das Deployment erfolgt automatisiert über GitHub Actions (`.github/workflows/deploy.yml`) bei jedem Push auf den `main`-Branch:
1. Checkout inkl. vollständiger Git-Historie; `git-restore-mtime` stellt die echten Zeitstempel wieder her, damit nur wirklich geänderte Dateien übertragen werden.
2. PHP 8.4 wird eingerichtet und `composer install --no-dev --optimize-autoloader` erzeugt ein frisches `vendor/` (das Repo-`vendor/` wird nie deployed).
3. Die Übertragung läuft **inkrementell per `rsync` über SSH** (`sshpass`) in das Zielverzeichnis `kai_root/`. Durch `--delete` werden auf dem Server entfernte Dateien ebenfalls gelöscht.
4. Sensible Daten wie FTP-/SSH-Verbindungsdaten sind als **GitHub Secrets** hinterlegt (`FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER`) und stehen niemals im YAML.
5. Vom Upload ausgeschlossen sind: `.git*`, `.github*`, `.env`, `.ftpignore`, `storage/` und `concepts/`.

> [!NOTE]
> Wegen `--delete` muss `storage/` serverseitig außerhalb des Sync-Pfads liegen oder als Exclude
> gesetzt bleiben — andernfalls würden die Laufzeit-Logs bei jedem Deployment entfernt.


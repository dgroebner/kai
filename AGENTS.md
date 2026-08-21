# AGENTS.md — KI-Leitfaden für das Projekt „kai"

Diese Datei dient als verbindliche Referenz für KI-Agenten (z. B. GitHub Copilot,
Antigravity, Cursor), die an diesem Projekt arbeiten. Sie beschreibt die Architektur,
die Konventionen und die Sicherheitsprinzipien, nach denen neuer Code gestaltet
werden muss.

---

## 1. Projektübersicht

**kai** ist ein privates, PHP-basiertes Web-Tool-Set für den Eigengebrauch. Es bündelt
mehrere unabhängige Werkzeuge (Domains) unter einer gemeinsamen Authentifizierungs-
und Infrastrukturschicht.

**Laufzeitumgebung:** PHP 8.4 · MySQL/MariaDB · Apache (Shared Hosting)
**Deployment:** Automatisch via GitHub Actions → SFTP auf den Webserver  
**Hosting:** Shared Hosting — Details nicht im Repo dokumentiert  
**Authentifizierung:** Google OAuth 2.0 mit E-Mail-Allowlist (kein öffentlicher Zugang)

---

## 2. Verzeichnisstruktur & Architekturprinzip

```
kai_root/
├── public/          ← Document Root des Webservers (einziges öffentlich erreichbares Verzeichnis)
│   ├── .htaccess
│   ├── index.php
│   ├── login.php
│   ├── css/
│   ├── js/
│   ├── shared/      ← Domainübergreifende Endpunkte (z. B. Cron-Trigger mail.php)
│   ├── bank/        ← Öffentliche Einstiegspunkte der Domain "Bank"
│   ├── car/         ← Öffentliche Einstiegspunkte der Domain "Car"
│   ├── kassenbon/   ← Öffentliche Einstiegspunkte der Domain "Kassenbon"
│   └── pvcharge/    ← Öffentliche Einstiegspunkte der Domain "PVCharge"
│
├── src/             ← Servercode (nicht öffentlich erreichbar, PSR-4 autoloaded)
│   ├── Shared/      ← Domainübergreifende Infrastruktur
│   │   ├── AI/      ← GeminiClient
│   │   ├── Db/      ← Database (PDO Singleton)
│   │   ├── Log/     ← Logger
│   │   └── Mail/    ← IMAP-Zugriff
│   ├── Bank/        ← Business-Logik der Domain "Bank" (inkl. Parser/)
│   ├── Car/         ← Business-Logik der Domain "Car"
│   ├── Kassenbon/   ← Business-Logik der Domain "Kassenbon"
│   └── PVCharge/    ← Business-Logik der Domain "PVCharge"
│
├── database/
│   └── schema.sql   ← Datenbankschema (versioniert, kein Migrations-Tool)
├── concepts/        ← Konzept- und Planungsdokumente (Markdown); nicht deployed
├── storage/         ← Laufzeitdaten (Logs); nicht im Repo, nicht deployed
├── bootstrap.php    ← Globaler Einstiegspunkt: .env laden, Session starten
├── composer.json
└── .env.example     ← Vorlage für die lokale .env-Datei (niemals echte Werte)
```

---

## 3. Strikte Trennung: Backend vs. Browser

> **Dies ist die wichtigste Architekturvorgabe des Projekts.**

Das Verzeichnis `public/` ist der **einzige** Ort, der vom Webserver direkt ausgeliefert
wird. Alles außerhalb (`src/`, `bootstrap.php`, `vendor/`, `database/`, `.env`) ist
für den Browser **nicht erreichbar**.

### Regeln

| Regel | Begründung |
|---|---|
| Business-Logik, DB-Zugriffe und API-Calls gehören ausschließlich in `src/` | Kein serverseitiger Code darf öffentlich abrufbar sein |
| PHP-Dateien in `public/` sind reine **Controller**: validieren, delegieren, antworten | Dünne Einstiegspunkte, keine fachliche Logik |
| JavaScript in `public/js/` darf **keine Secrets** enthalten | JS wird 1:1 an den Browser übermittelt |
| AJAX-Endpunkte in `public/` prüfen als **erstes** Auth und HTTP-Methode | Kein Request darf je ungefiltert in die Logikschicht gelangen |

### Konkretes Muster für einen `public/`-Controller

```php
<?php
require_once __DIR__ . '/../../bootstrap.php';

use Kai\Tools\DomainX\SomeRepository;
use Kai\Tools\Shared\Log\Logger;
use Kai\Tools\Shared\Security\Auth;

header('Content-Type: application/json; charset=utf-8');

// 1. Auth-Check — immer zuerst
Auth::requireApi();

// 2. HTTP-Methoden-Check
Auth::requireMethod('POST');

// 3. Input validieren & bereinigen (inkl. CSRF-Token)
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    Auth::sendJsonError(400, 'Ungültige Anfrage');
}
Auth::requireCsrfToken($input);

$value = filter_var($input['value'] ?? null, FILTER_VALIDATE_INT);
if ($value === false || $value === null) {
    Auth::sendJsonError(400, 'Ungültige Parameter');
}

// 4. Logik delegieren (nie direkt DB-Queries im Controller)
try {
    $repo = new SomeRepository();
    $result = $repo->doSomething($value);

    // 5. Antwort ausgeben
    echo json_encode(['success' => true, 'data' => $result]);
} catch (\Throwable $e) {
    (new Logger())->error('domainx/api.php: Fehler.', ['error' => $e->getMessage()]);
    Auth::sendJsonError(500, 'Interner Fehler');
}
```

---

## 4. Modularer Aufbau — Domain-Trennung

Jede fachliche Domäne ist ein eigenständiges Modul mit klaren Grenzen:

- **Namespace:** `Kai\Tools\{DomainName}\`  (z. B. `Kai\Tools\Kassenbon\`)
- **Src-Verzeichnis:** `src/{DomainName}/`
- **Public-Verzeichnis:** `public/{domainname}/`

Domains **dürfen nicht** direkt in die Klassen einer anderen Domain importieren.
Geteilte Funktionalität (DB, Logger, AI) wird ausschließlich über `src/Shared/` bezogen.

### Neue Domain anlegen

1. `src/NewDomain/` erstellen mit mindestens einem Repository und ggf. einem Service
2. `public/newdomain/index.php` als Controller anlegen
3. Namespace `Kai\Tools\NewDomain\` in `composer.json` unter `autoload.psr-4` eintragen
4. Tabellen in `database/schema.sql` hinzufügen (mit `CREATE TABLE IF NOT EXISTS`)

---

## 5. Wiederverwendbarer Shared Code (`src/Shared/`)

Shared-Klassen sind die einzige Stelle für domainübergreifende Infrastruktur.

| Klasse | Verwendung |
|---|---|
| `Kai\Tools\Shared\Db\Database` | PDO-Singleton. Aufruf: `Database::getInstance()->getConnection()` |
| `Kai\Tools\Shared\AI\GeminiClient` | Google Gemini API. Konstruktor akzeptiert optionales Modell |
| `Kai\Tools\Shared\Log\Logger` | Dateibasierter Logger. Methoden: `->info()`, `->error()` |
| `Kai\Tools\Shared\Mail\*` | IMAP-Zugriff für E-Mail-Verarbeitung |
| `Kai\Tools\Shared\Security\Auth` | Zugriffskontrolle für alle `public/`-Endpunkte (Session, CSRF, Cron-Token) |

### 5.1 Verbindliche Nutzung von `Auth`

Jeder Endpunkt in `public/` verwendet ausschließlich die Guards der Klasse
`Kai\Tools\Shared\Security\Auth` — keine handgeschriebenen Session-Prüfungen mehr.

| Methode | Einsatz |
|---|---|
| `Auth::requirePage()` | HTML-Seiten: leitet nicht angemeldete Besucher nach `login.php` um |
| `Auth::requireApi()` | JSON-Endpunkte: antwortet mit `401` statt einer Weiterleitung |
| `Auth::requireMethod('POST')` | Erzwingt die HTTP-Methode (antwortet mit `405`) |
| `Auth::requireCsrfToken($payload)` | Erzwingt einen gültigen CSRF-Token (Header `X-CSRF-Token` oder Body) |
| `Auth::csrfToken()` | Liefert/erzeugt den Session-CSRF-Token für `<meta>` bzw. Hidden-Field |
| `Auth::isValidCsrfToken($token)` | Zeitkonstante Prüfung, z. B. für klassische Formular-POSTs |
| `Auth::requireCronToken()` | Schützt Cronjob-Endpunkte über `CRON_TOKEN` (zeitkonstant, `hash_equals`) |
| `Auth::cronTokenMatches(false)` | Token-Prüfung ohne Query-Parameter (nur Header) für Maschinen-APIs |
| `Auth::sendJsonError($status, $msg)` | Beendet den Request mit generischer JSON-Fehlerantwort |

**Neue Shared-Klassen anlegen,** wenn dieselbe Infrastruktur von mehr als einer Domain
benötigt wird. Einzeldomänen-Utilities bleiben in der jeweiligen Domain.

### 5.2 Globale Systemparameter

Globale Systemparameter können in der Tabelle `system_settings` abgelegt werden. Diese dient als globaler Key-Value-Store.

---

## 6. Security by Design

### 6.1 Secrets & Konfiguration

- Alle Credentials und Konfigurationswerte kommen ausschließlich aus `$_ENV` (geladen via `.env` / phpdotenv)
- **Kein Hardcoding** von Zugangsdaten, URLs, API-Keys oder Tokens im Quellcode
- Die Datei `.env` ist in `.gitignore` und wird **niemals committet**
- `.env.example` mit Platzhalterwerten ist die einzige Referenz im Repository

### 6.2 Datenbankzugriff

- **Ausschließlich PDO Prepared Statements** — niemals String-Interpolation in SQL
- `PDO::ATTR_EMULATE_PREPARES => false` ist global gesetzt (echte native Prepared Statements)
- Fehler werden in das interne Log geschrieben; der Browser erhält **niemals** rohe
  DB-Fehlermeldungen oder Stack-Traces

```php
// ✅ Richtig
$stmt = $pdo->prepare("SELECT * FROM receipts WHERE id = :id");
$stmt->execute([':id' => $id]);

// ❌ Falsch — SQL Injection möglich
$pdo->query("SELECT * FROM receipts WHERE id = $id");
```

### 6.3 Input-Validierung

- Jeder externe Input (`$_GET`, `$_POST`, `php://input`, E-Mail-Inhalte, API-Antworten)
  gilt als **nicht vertrauenswürdig** und muss vor der Verwendung validiert werden
- Für numerische Werte: `filter_var($val, FILTER_VALIDATE_INT)` oder expliziter Cast mit anschließender Bereichsprüfung
- Für Strings: Länge prüfen, Whitelist-Validierung bevorzugen
- KI-generierte Ausgaben (Gemini-Antworten) sind ebenfalls als externe Eingaben zu behandeln

### 6.4 Authentifizierung & Session

- Jeder `public/`-Endpunkt der nicht öffentlich zugänglich sein soll, ruft als **erstes**
  `Auth::requirePage()` (HTML) bzw. `Auth::requireApi()` (JSON) auf
- Der OAuth-Flow verwendet einen `state`-Parameter, der beim Callback zeitkonstant geprüft wird
- Nach erfolgreichem Login wird `session_regenerate_id(true)` aufgerufen (Session Fixation Schutz)
- Session-Cookies sind konfiguriert: `httponly=1`, `secure=1`, `samesite=Lax`,
  zusätzlich `session.use_strict_mode=1` und `session.use_only_cookies=1`
  (`Lax` ist zwingend, damit der OAuth-Callback von Google die Session behält;
  Cross-Site-POSTs bleiben blockiert und sind zusätzlich CSRF-Token-geschützt)
- Die Allowlist (`ALLOWED_USERS`) ist die einzige Zugangskontrolle; sie wird aus `$_ENV`
  gelesen und beim Vergleich normalisiert (trim + lowercase, strikter Vergleich)
- Maschinen-Endpunkte (Cronjobs, Telemetrie-Upload) nutzen `Auth::requireCronToken()`
  bzw. `Auth::cronTokenMatches()` mit zeitkonstantem `hash_equals`-Vergleich

### 6.5 CSRF-Schutz

- State-verändernde Aktionen (POST, Datenbankschreibzugriffe) erfordern einen CSRF-Token
- CSRF-Token werden pro Session generiert (`Auth::csrfToken()`) und im `<form>`-Hidden-Field
  oder als `<meta name="csrf-token">` für AJAX bereitgestellt
- AJAX-Aufrufe nutzen ausschließlich `KaiHttp.postJson()` aus `public/js/http.js`;
  dieser Helfer hängt den Header `X-CSRF-Token` automatisch an
- Serverseitig wird der Token zeitkonstant über `Auth::requireCsrfToken()` bzw.
  `Auth::isValidCsrfToken()` gegen `$_SESSION['csrf_token']` geprüft

### 6.6 Ausgabe & XSS

- Alle Werte, die in HTML ausgegeben werden, müssen mit `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` escaped werden
- **Keine Inline-Event-Handler:** Event-Handler wie `onclick="..."`, `onchange="..."` oder `<script>`-Blöcke im HTML-Body sind **verboten**, da sie von der Content Security Policy (`script-src-attr`) blockiert werden.
- **Event Delegation:** Interaktionen (z. B. Inline-Editing, Tooltips) müssen ausschließlich über externe JavaScript-Dateien in `public/js/` per Event Delegation (`document.addEventListener(...)`) eingebunden werden.
- `echo $variable` ohne Escaping ist verboten, wenn die Variable aus externen Quellen stammt
- Content-Security-Policy und XSS-Protection-Header sind in `.htaccess` gesetzt

### 6.7 Fehlerbehandlung

- Im Produktivbetrieb werden Fehler **geloggt**, aber **nicht** an den Browser ausgegeben
- `display_errors` ist in `bootstrap.php` deaktiviert und zusätzlich auf dem Server abgeschaltet
- `bootstrap.php` registriert einen globalen `set_exception_handler`, der jede nicht
  abgefangene Ausnahme loggt und dem Browser nur eine generische Meldung zeigt
- Catch-Blöcke geben dem Browser generische Fehlermeldungen zurück:
  ```php
  } catch (\Throwable $e) {
      $logger->error("Beschreibung", ['error' => $e->getMessage()]);
      Auth::sendJsonError(500, 'Interner Fehler');
  }
  ```
- `$e->getMessage()` darf **niemals** an den Browser gelangen — nur ins Log

---

## 7. DSGVO / GDPR-Konformität

Dieses Projekt verarbeitet ausschließlich **eigene personenbezogene Daten** des Betreibers
(keine Nutzerdaten Dritter). Dennoch gelten folgende Prinzipien:

### 7.1 Datensparsamkeit (Art. 5 Abs. 1 lit. c DSGVO)

- Es werden nur Daten gespeichert, die für die Funktion des Tools notwendig sind
- Keine Tracking-Pixel, Analytics-Dienste oder externe Fonts mit Tracking-Potenzial
- `vehicle_telemetry_log` speichert Fahrzeugdaten mit Zeitstempel — Aufbewahrungsdauer
  sollte dokumentiert und ggf. begrenzt werden

### 7.2 Technische und organisatorische Maßnahmen (Art. 32 DSGVO)

- HTTPS erzwungen (HSTS, 1 Jahr, inkl. Subdomains)
- Datenbankverbindung nur über verschlüsselte Verbindung
- Zugang ausschließlich über Google OAuth + Allowlist
- Logs werden nach `LOG_RETENTION_DAYS` Tagen automatisch gelöscht

### 7.3 Drittanbieter

| Dienst | Zweck | Datenweitergabe |
|---|---|---|
| Google OAuth | Authentifizierung | E-Mail-Adresse, Name |
| Google Gemini API | KI-Analyse (Kassenbons, PV-Planung) | Kassenboninhalt, Energiedaten |
| Hosting-Anbieter | Server & Datenbank | Alle gespeicherten Daten |
| forecast.solar | Solarertragsprognose | GPS-Koordinaten (falls konfiguriert) |

> **Neue Drittanbieter** müssen in dieser Tabelle dokumentiert werden, bevor sie
> in den Code integriert werden.

---

## 8. Clean Code — Konventionen

### 8.1 Allgemein

- **Sprache:** Kommentare und Dokumentation auf **Deutsch**, Code (Variablen, Methoden,
  Klassen) auf **Englisch**
- **PHP-Standard:** PSR-4 Autoloading, PSR-12 Coding Style (soweit praktikabel)
- **Dateilänge:** Klassen mit mehr als ~200 Zeilen überdenken und ggf. aufteilen
- Keine toten Code-Blöcke, keine auskommentierten Funktionen im Main-Branch

### 8.2 Benennung

```
Klassen:      PascalCase      → ReceiptRepository, GeminiClient
Methoden:     camelCase       → fetchReceipts(), getConnection()
Variablen:    camelCase       → $carCapturedAt, $socPercent
Konstanten:   UPPER_SNAKE     → APP_URL
DB-Spalten:   snake_case      → car_captured_at, soc_percent
```

### 8.3 Klassen-Design

- **Single Responsibility:** Eine Klasse, eine Aufgabe
  - `*Repository`: Datenbankzugriffe für eine Entität
  - `*Service` / `*Collector`: Externe API-Aufrufe, Orchestrierung
  - `*Analyzer` / `*Task`: Verarbeitungslogik
- **Dependency Injection bevorzugen** gegenüber statischen Aufrufen (Ausnahme: `Database::getInstance()` als etabliertes Singleton)
- Keine globalen Variablen (`global $var` ist verboten)

### 8.4 Datenbankschema

- `schema.sql` ist die einzige Wahrheitsquelle für das Datenbankschema
- Neue Tabellen: immer `CREATE TABLE IF NOT EXISTS`
- Alle Tabellen: `ENGINE=InnoDB`, `CHARSET=utf8mb4`, `COLLATE=utf8mb4_unicode_ci`
- Indizes für häufig gefilterte Spalten dokumentieren und anlegen

### 8.5 CSS & Frontend-Styles (Zentrales Styleschema)

- **Zentrale Vorgabe:** Für alle UI-Elemente ist ausschließlich das zentrale Styleschema in `public/css/style.css` zu verwenden.
- **Keine Inline-Styles & `<style>`-Blöcke:** PHP- und HTML-Dateien dürfen weder lokale `<style>`-Blöcke noch `style="..."`-Attribute enthalten (Ausnahme: dynamisch berechnete Werte wie Prozentbreiten von Ladebalken oder Farbcodes).
- **Einheitliche Layout-Komponenten:**
  - Standard-Header: Immer `.page-header` mit rechtsbündigem Action-Button (`.btn .btn-outline`).
  - Standard-Dashboards: Tabellen- und KPI-Elemente nutzen universelle Klassen (`.kpi-grid`, `.mobile-dashboard-grid`, `.table-responsive`).
  - Responsive Breakpoints: Alle Media Queries (`@media (max-width: 768px)` / `< 600px`) werden zentral am Ende von `public/css/style.css` gepflegt und strikt abgekapselt.
- **Erweiterungsprinzip:** Wenn neue Styles für ein Feature benötigt werden, dürfen diese nicht ad-hoc im Controller abgelegt werden. Sie müssen das zentrale Styleschema in `public/css/style.css` an geeigneter Stelle ergänzen und dokumentieren, sodass sie für zukünftige Entwicklungen im gesamten Projekt zur Verfügung stehen.
- **Stack-Tabellen und data-labels** verwenden um die Tabellen in mobilen Ansichten übersichtlich darzustellen.

---

## 9. Deployment-Pipeline

Die CI/CD-Pipeline (`/.github/workflows/deploy.yml`) deployt automatisch bei jedem
Push auf `main` via SFTP auf den Produktiv-Webserver.

### Was deployed wird

- Alle Dateien aus dem Repository **außer**: `.git/`, `.github/`, `.env`, `.ftpignore`, `storage/`, `concepts/`
- `vendor/` wird im CI neu gebaut (`composer install --no-dev`) und mitdeployed

### Was **nicht** deployed werden darf

| Ausgeschlossen | Grund |
|---|---|
| `.env` | Enthält Produktiv-Secrets |
| `storage/` | Enthält Laufzeit-Logs (serverseitig persistent) |
| `concepts/` | Reine Konzept-/Planungsdokumente, für den Betrieb irrelevant |
| `vendor/` aus dem Repo | Wird im CI frisch gebaut |

### Credentials für die Pipeline

Alle Credentials sind als **GitHub Secrets** hinterlegt:
- `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_SERVER`

**Niemals** Credentials direkt in die YAML-Datei schreiben.

---

## 10. Checkliste für neue Features

Bevor ein neues Feature als fertig gilt, müssen folgende Punkte erfüllt sein:

- [ ] Kein Secret oder Credential im Quellcode
- [ ] Alle DB-Zugriffe über Prepared Statements
- [ ] Alle `public/`-Endpunkte prüfen Auth als erstes
- [ ] Alle HTML-Ausgaben sind mit `htmlspecialchars()` escaped
- [ ] Fehler werden geloggt, nicht an den Browser weitergegeben
- [ ] Neue Tabellen in `schema.sql` dokumentiert
- [ ] Neue Drittanbieter in AGENTS.md Abschnitt 7.3 eingetragen
- [ ] JavaScript enthält keine serverseitigen Credentials oder Logik
- [ ] Domain-Grenzen eingehalten (kein domainübergreifender direkter Klassenimport)
- [ ] Keine redundanten `<style>`-Blöcke oder Inline-Styles verwendet (zentrales Styleschema aus `public/css/style.css` genutzt oder erweitert)
- [ ] Minorversion in der APP_VERSION Variable der bootstrap.php erhöhen wenn CSS- oder JS-Dateien geändert worden sind.
- [ ] APP_VERSION ist an alle CSS- und JS-Referenzen angehängt: `?v=<?= APP_VERSION ?>`
- [ ] Keine Inline-JavaScript-Event-Handler (`onclick` etc.) oder `<script>`-Blöcke in HTML/PHP verwendet (CSP-Konformität).
- [ ] Externe JS-Logik bindet Events über Event Delegation (`e.target.closest(...)`) ein.
- [ ] Keine `<style>`-Blöcke in PHP-Dateien verwendet — alle Layout-Klassen sind zentral in `public/css/style.css` organisiert.
- [ ] Standard-Layouts (`.page-header`, `.kpi-grid`, `.table-responsive`) für konsistentes Look & Feel eingehalten.

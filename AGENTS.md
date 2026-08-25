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
**Deployment:** Automatisch via GitHub Actions → rsync über SSH auf den Webserver  
**Hosting:** Shared Hosting — Details nicht im Repo dokumentiert  
**Authentifizierung:** Google OAuth 2.0 mit E-Mail-Allowlist (kein öffentlicher Zugang)
**PWA:** Progressive Web App — installierbar auf Desktop und Mobilgeräten (Service Worker, manifest.json)
**Tests/Linting:** Keine automatisierte Testsuite. Prüfung über `php -l <datei>` und
`node --check public/js/<datei>.js`

---

## 2. Verzeichnisstruktur & Architekturprinzip

```
kai_root/
├── public/          ← Document Root des Webservers (einziges öffentlich erreichbares Verzeichnis)
│   ├── .htaccess
│   ├── index.php
│   ├── login.php
│   ├── manifest.json    ← PWA Web App Manifest (Name, Icons, Theme-Farbe)
│   ├── sw.js            ← PWA Service Worker (Caching, Offline-Fallback)
│   ├── offline.html     ← PWA Offline-Fallback-Seite
│   ├── css/
│   ├── js/          ← http.js stellt KaiHttp (CSRF-POST) und KaiHtml (Escaping) bereit
│   │                   pwa-register.js registriert den Service Worker
│   ├── shared/      ← Domainübergreifende Endpunkte (z. B. Cron-Trigger mail.php)
│   │                   head-pwa.php: zentraler PWA-Head-Include für alle Seiten
│   ├── bank/        ← Öffentliche Einstiegspunkte der Domain "Bank"
│   ├── car/         ← Öffentliche Einstiegspunkte der Domain "Car"
│   ├── kassenbon/   ← Öffentliche Einstiegspunkte der Domain "Kassenbon"
│   ├── pvcharge/    ← Öffentliche Einstiegspunkte der Domain "PVCharge"
│   └── system/      ← Öffentliche Einstiegspunkte der Domain "System"
│
├── src/             ← Servercode (nicht öffentlich erreichbar, PSR-4 autoloaded)
│   ├── Shared/      ← Domainübergreifende Infrastruktur
│   │   ├── AI/        ← GeminiClient
│   │   ├── Db/        ← Database (PDO Singleton)
│   │   ├── Log/       ← Logger (Datei-Log), ActivityLogger (Aktivitäts-Log in der DB)
│   │   ├── Mail/      ← IMAP-Zugriff
│   │   └── Security/  ← Auth, Sanitizer, TokenEncryptionService
│   ├── Bank/        ← Business-Logik der Domain "Bank" (inkl. Parser/)
│   ├── Car/         ← Business-Logik der Domain "Car"
│   ├── Kassenbon/   ← Business-Logik der Domain "Kassenbon"
│   ├── PVCharge/    ← Business-Logik der Domain "PVCharge"
│   └── System/      ← Business-Logik der Domain "System" (Aktivitäts-Log, Einstellungen)
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
Geteilte Funktionalität (DB, Logger, AI, Security) wird ausschließlich über `src/Shared/` bezogen.

#### Erlaubte Integrationspunkte (Ausnahmen)

Die folgenden domainübergreifenden Zugriffe sind bewusst gesetzt und dokumentiert. Sie sind die
**einzigen** zulässigen Ausnahmen — jede weitere Kopplung ist neu zu begründen und hier zu ergänzen:

| Stelle | Zugriff auf | Begründung |
|---|---|---|
| `Shared\Mail\MailDispatcher` | `Bank\*`, `Kassenbon\*` | Orchestrator: verteilt eingehende Mails an die zuständige Domain. Alle Abhängigkeiten werden **per Konstruktor injiziert**, nicht selbst instanziiert. |
| `Bank\BankGiroService` | `Kassenbon\ReceiptMatcher` | Nach dem Import neuer Umsätze werden offene Kassenbons den Buchungen zugeordnet. |
| `Bank\CreditCardService` | `Kassenbon\ReceiptMatcher` | Analog für importierte Kreditkartenabrechnungen. |
| `public/pvcharge/index.php` | `System\SystemSettingsService` | Liest die globalen Strom-Bezugs- und Einspeisepreise aus `system_settings`. |

Die Kopplung verläuft dabei stets **in eine Richtung** (Bank → Kassenbon, PVCharge → System);
Rückwärts- oder Zirkelbezüge sind unzulässig.

### Aktuelle Domains

| Domain | Namespace | Src | Public |
|---|---|---|---|
| Bank | `Kai\Tools\Bank\` | `src/Bank/` (inkl. `Parser/`) | `public/bank/` |
| Car | `Kai\Tools\Car\` | `src/Car/` | `public/car/` |
| Kassenbon | `Kai\Tools\Kassenbon\` | `src/Kassenbon/` | `public/kassenbon/` |
| PVCharge | `Kai\Tools\PVCharge\` | `src/PVCharge/` | `public/pvcharge/` |
| System | `Kai\Tools\System\` | `src/System/` | `public/system/` |

### Neue Domain anlegen

1. `src/NewDomain/` erstellen mit mindestens einem Repository und ggf. einem Service
2. `public/newdomain/index.php` als Controller anlegen
3. Tabellen in `database/schema.sql` hinzufügen (mit `CREATE TABLE IF NOT EXISTS`)

> `composer.json` benötigt **keinen** neuen Eintrag: Der PSR-4-Prefix `Kai\Tools\ → src/`
> deckt alle Domains ab. Nach dem Anlegen genügt `composer dump-autoload`.

---

## 5. Wiederverwendbarer Shared Code (`src/Shared/`)

Shared-Klassen sind die einzige Stelle für domainübergreifende Infrastruktur.

| Klasse | Verwendung |
|---|---|
| `Kai\Tools\Shared\Db\Database` | PDO-Singleton. Aufruf: `Database::getInstance()->getConnection()` |
| `Kai\Tools\Shared\AI\GeminiClient` | Google Gemini API. Konstruktor akzeptiert optionales Modell |
| `Kai\Tools\Shared\Log\Logger` | Dateibasierter Logger. Methoden: `->info()`, `->warn()`, `->error()`, `->debug()` |
| `Kai\Tools\Shared\Log\ActivityLogger` | Schreibt fachliche Ereignisse in die Tabelle `activity_log` (Anzeige unter `public/system/`) |
| `Kai\Tools\Shared\Mail\*` | IMAP-Zugriff für E-Mail-Verarbeitung |
| `Kai\Tools\Shared\Security\Auth` | Zugriffskontrolle für alle `public/`-Endpunkte (Session, CSRF, Cron-Token) |
| `Kai\Tools\Shared\Security\Sanitizer` | Whitelist-Normalisierung von Werten für HTML-Attribute (aktuell `hexColor()`) |
| `Kai\Tools\Shared\Security\TokenEncryptionService` | Ver-/Entschlüsselung von API-Tokens (XChaCha20-Poly1305 via libsodium) |

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
Der Zugriff erfolgt über die Domain "System": `System\SystemSettingsRepository` (roher Key-Value-Zugriff)
bzw. `System\SystemSettingsService` (typisierte Getter, z. B. `getGridImportPrice()`).
Gepflegt werden die Werte im UI unter `public/system/index.php` (Tab „Einstellungen").

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

- Tabellen- und Spaltennamen lassen sich **nicht** als Parameter binden. Wo sie dynamisch sein
  müssen, ist eine **Allowlist** die einzige zulässige Absicherung — niemals der Rohwert aus dem
  Request. Referenzimplementierung: `PvIngestService::ALLOWED_COLUMNS`
  (Spalten aus dem Ingest-Payload) und `ReceiptMatcher::transactionExists()` (Tabellenwahl).

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
- **In JavaScript:** Werte, die in `innerHTML`-Templates oder HTML-Attribute eingesetzt werden, laufen ausschließlich über `KaiHtml.escape()` aus `public/js/http.js`. Jede Seite, die ein Modul-Skript lädt, muss `http.js` **davor** einbinden (beide mit `defer`).
- **Farbwerte** aus der Datenbank oder von Nutzereingaben landen in `style`- bzw. `value`-Attributen, wo `htmlspecialchars()` keine ausreichende Absicherung ist. Sie werden per Whitelist normalisiert: `Sanitizer::hexColor()` (PHP) bzw. `KaiHtml.hexColor()` (JS) — sowohl beim Schreiben in die DB als auch bei der Ausgabe.
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
| Google Gemini API | KI-Analyse (Kassenbons, Kreditkarten-PDFs, Tag-Klassifizierung) | Kassenboninhalt, Abrechnungsinhalt, Buchungstexte |
| comdirect REST API | Abruf von Girokonto-Umsätzen und Kontostand | Zugangsnummer & PIN beim Login, photoTAN-Freigabe; API-Tokens werden verschlüsselt gespeichert |
| IMAP-Postfach | Eingang von E-Bons und Abrechnungen | E-Mail-Inhalte und Anhänge |
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
- **Cleanup-Pflicht bei Refactoring**: Wenn bestehende Dateien umgebaut oder entfernt werden, sind die dazugehörigen, nicht mehr benötigten Klassen, Skripte oder Codeverweise zu entfernen, um Code-Müll zu vermeiden.

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
- **Cleanup-Pflicht bei Refactoring**: Wenn bestehende Features oder PHP-Views umgebaut oder entfernt werden, sind die dazugehörigen, nicht mehr benötigten Klassen aus der public/css/style.css zu entfernen, um Code-Müll zu vermeiden.

---

## 9. Progressive Web App (PWA)

**kai** ist als PWA implementiert und kann auf Desktop- und Mobilgeräten installiert
werden. Die PWA-Eigenschaft basiert auf drei Kerndateien in `public/`:

| Datei | Zweck |
|---|---|
| `manifest.json` | App-Metadaten (Name, Icons, Theme-Farbe, `start_url`) |
| `sw.js` | Service Worker: Caching-Strategie und Offline-Fallback |
| `offline.html` | Gestaltete Fehlerseite bei fehlender Netzwerkverbindung |
| `js/pwa-register.js` | Registriert den Service Worker beim Seitenaufruf |
| `shared/head-pwa.php` | Zentraler Include: Manifest-Link, Theme-Color, iOS-Meta-Tags |

### 9.1 Caching-Strategie des Service Workers

| Anfrage-Typ | Strategie | Begründung |
|---|---|---|
| HTML-Seiten (PHP) | **Network-First** | Seiten sind serverseitig gerendert, Daten müssen aktuell sein |
| CSS / JS / Bilder | **Cache-First** | Statische Assets ändern sich selten, Cache-Busting via `APP_VERSION` |
| POST-Requests | **Kein Caching** | Schreibzugriffe dürfen niemals im Cache landen |
| API-Endpunkte (`/api*`, `/ingest*`, `/cron_*`) | **Network-Only** (Bypass) | Immer Live-Daten erforderlich |
| Andere Origins (Google OAuth) | **Network-Only** (Bypass) | Fremde Origins liegen außerhalb des Service-Worker-Scopes |

### 9.2 Regeln für Weiterentwicklungen

> **Diese Regeln müssen bei jeder Änderung, die neue Seiten oder Assets einführt,
> beachtet werden, um die PWA-Eigenschaft nicht zu beschädigen.**

#### Neue HTML-Seite (PHP-Datei mit `<head>`) anlegen

1. Den PWA-Head-Include **direkt nach dem `<link rel="stylesheet">`-Tag** einbinden:
   ```php
   <link rel="stylesheet" href="../css/style.css?v=<?= APP_VERSION ?>">
   <?php include __DIR__ . '/../shared/head-pwa.php'; ?>
   </head>
   ```
   Für Root-Seiten (`public/*.php`) lautet der Pfad `'/shared/head-pwa.php'` (ohne `/../`).

2. Der Include muss auf **jeder** Seite vorhanden sein, die als eigenständige View
   im Browser-Tab geöffnet werden kann (auch Login-Seite).

#### Neue statische Asset-Typen (z. B. Webfonts, neue Icon-Formate)

- Den MIME-Type in `.htaccess` ergänzen (Abschnitt `mod_mime.c`), falls er noch nicht
  gesetzt ist.
- Sicherstellen, dass der Service Worker (`sw.js`) den Dateityp in seinem
  `isStaticAsset`-Regex erfasst:
  ```javascript
  const isStaticAsset = url.pathname.match(/\.(css|js|png|jpg|jpeg|svg|ico|webp|woff2?)$/);
  ```
  Neue Endungen dort ergänzen.

#### Neue API-Endpunkte in `public/`

- POST-Requests werden automatisch vom Service Worker durchgereicht (kein Caching).
- GET-API-Endpunkte (z. B. neue `/api_*.php`-Dateien) müssen **explizit** in der
  Bypass-Bedingung des Service Workers berücksichtigt sein, falls sie keine gecachten
  Antworten vertragen:
  ```javascript
  if (url.pathname.includes('/api') || url.pathname.includes('/ingest') || ...) {
      return; // direkt ans Netzwerk
  }
  ```

#### CSS oder JS-Dateien ändern

- `APP_VERSION` in `bootstrap.php` auf die nächste Minorversion erhöhen (z. B.
  `1.5.0` → `1.5.1`). Der Service Worker erkennt am geänderten Cache-Namen
  (`kai-v1`) **keine** automatische Invalidierung — der Cache-Busting-Mechanismus
  über den Query-Parameter `?v=APP_VERSION` in den HTML-Links ist der einzige
  Mechanismus, der sicherstellt, dass Nutzer die aktuellen Dateien erhalten.
- Werden komplett neue CSS/JS-Dateien hinzugefügt, die bereits beim App-Start
  benötigt werden: die Datei in die `PRECACHE_ASSETS`-Liste in `sw.js` aufnehmen.

#### Service Worker (`sw.js`) selbst ändern

- `sw.js` ist in `.htaccess` mit `Cache-Control: no-cache, no-store, must-revalidate`
  konfiguriert. Änderungen am Service Worker werden beim nächsten Seitenaufruf
  **sofort** vom Browser erkannt und nach `skipWaiting()` aktiviert.
- Den `CACHE_VERSION`-String in `sw.js` (z. B. `'v1'` → `'v2'`) erhöhen, wenn die
  Caching-Strategie grundlegend geändert wird. Dadurch werden alle alten Caches
  beim `activate`-Event automatisch gelöscht.

#### Icons oder Manifest-Metadaten ändern

- Icons liegen direkt in `public/` als `android-chrome-192x192.png` (192 × 192 px)
  und `android-chrome-512x512.png` (512 × 512 px). Ein `apple-touch-icon.png` ist
  ebenfalls vorhanden.
- Änderungen an `manifest.json` (z. B. neuer `name`, neue `theme_color`) werden
  von installierten PWAs erst beim nächsten Browser-Start oder nach einer Neuinstallation
  übernommen.

### 9.3 Nicht erlaubte Muster

| Muster | Begründung |
|---|---|
| PWA-Meta-Tags direkt in einzelnen PHP-Dateien statt via `head-pwa.php` | Erzeugt Redundanz und führt zu inkonsistentem Verhalten beim Installieren |
| `site.webmanifest` als Alternative zu `manifest.json` | Wurde durch `manifest.json` ersetzt; darf nicht wiederhergestellt werden |
| Schreibzugriffe (POST/PUT/DELETE) in den SW-Cache aufnehmen | Sicherheitsrisiko und funktionale Fehler |
| `sw.js` selbst in die `PRECACHE_ASSETS`-Liste aufnehmen | Browser verwalten Service-Worker-Updates eigenständig |

---

## 10. Deployment-Pipeline


Die CI/CD-Pipeline (`/.github/workflows/deploy.yml`) deployt automatisch bei jedem
Push auf `main` per **rsync über SSH** (`sshpass`) in das Verzeichnis `kai_root/` des
Produktiv-Webservers. Durch `--delete` werden serverseitig entfernte Dateien mitgelöscht.

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

## 11. Checkliste für neue Features

Bevor ein neues Feature als fertig gilt, müssen folgende Punkte erfüllt sein:

- [ ] Kein Secret oder Credential im Quellcode
- [ ] Alle DB-Zugriffe über Prepared Statements; dynamische Tabellen-/Spaltennamen nur über eine Allowlist
- [ ] Alle `public/`-Endpunkte prüfen Auth als erstes
- [ ] Alle HTML-Ausgaben sind mit `htmlspecialchars()` escaped
- [ ] Werte in JS-`innerHTML`-Templates laufen über `KaiHtml.escape()`, Farbwerte über `Sanitizer::hexColor()` / `KaiHtml.hexColor()`
- [ ] Fehler werden geloggt, nicht an den Browser weitergegeben (`$e->getMessage()` niemals in der Antwort)
- [ ] Neue Tabellen in `schema.sql` dokumentiert
- [ ] Neue Drittanbieter in AGENTS.md Abschnitt 7.3 eingetragen
- [ ] JavaScript enthält keine serverseitigen Credentials oder Logik
- [ ] Domain-Grenzen eingehalten (kein neuer domainübergreifender Klassenimport außerhalb der in Abschnitt 4 dokumentierten Ausnahmen)
- [ ] Keine redundanten `<style>`-Blöcke oder Inline-Styles verwendet (zentrales Styleschema aus `public/css/style.css` genutzt oder erweitert)
- [ ] Minorversion in der APP_VERSION Variable der bootstrap.php erhöhen wenn CSS- oder JS-Dateien geändert worden sind.
- [ ] APP_VERSION ist an alle CSS- und JS-Referenzen angehängt: `?v=<?= APP_VERSION ?>`
- [ ] `http.js` wird vor jedem Modul-Skript eingebunden, das `KaiHttp`/`KaiHtml` verwendet
- [ ] Keine Inline-JavaScript-Event-Handler (`onclick` etc.) oder `<script>`-Blöcke in HTML/PHP verwendet (CSP-Konformität).
- [ ] Externe JS-Logik bindet Events über Event Delegation (`e.target.closest(...)`) ein.
- [ ] Keine `<style>`-Blöcke in PHP-Dateien verwendet — alle Layout-Klassen sind zentral in `public/css/style.css` organisiert.
- [ ] Standard-Layouts (`.page-header`, `.kpi-grid`, `.table-responsive`) für konsistentes Look & Feel eingehalten.
- [ ] Geänderte PHP-Dateien mit `php -l` und geänderte JS-Dateien mit `node --check` geprüft.
- [ ] Bei neuen HTML-Seiten (PHP-Dateien) wurde `shared/head-pwa.php` direkt nach dem Stylesheet eingebunden.
- [ ] Bei neuen GET-API-Endpunkten wurde geprüft, ob sie in der Bypass-Bedingung von `sw.js` eingetragen werden müssen.
- [ ] Neue Asset-Typen (z. B. Webfonts) wurden in `sw.js` (Regex `isStaticAsset`) und falls nötig in `.htaccess` (MIME-Type) hinterlegt.


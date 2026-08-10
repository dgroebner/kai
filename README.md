# kai — Privates Web-Tool-Set (Dashboard)

**kai** ist eine private, PHP-basierte Heimanwendung für den Eigengebrauch. Sie bündelt mehrere unabhängige Werkzeuge unter einer gemeinsamen Authentifizierungs- und Infrastrukturschicht.

## 🚀 Die Module im Überblick

1. **🛒 eBons (Kassenbons):** Automatische KI-Auswertung von Haushalts-Kassenbons per E-Mail (IMAP) und Einzelpreis-Erfassung für die Haushaltsplanung mittels Google Gemini.
2. **📈 Bon-Auswertung:** Dashboard zur grafischen Visualisierung und Analyse der erfassten Einkäufe nach Zeiträumen und Kategorien.
3. **☀️ PV-Solarprognose:** Ertragsprognose der Photovoltaikanlage für die kommenden Tage basierend auf GPS-Daten und forecast.solar API.
4. **🚐 VW ID.Buzz Telemetrie:** Live-Fahrzeugstatus (Ladestand, Reichweite, Temperaturen) und Verlaufshistorie mittels API-Schnittstelle.

---

## 🛠️ Systemarchitektur & Verzeichnisstruktur

Das Projekt folgt einer strikten Trennung zwischen öffentlich erreichbarem Code und geschützter Server-Logik:

```
kai_root/
├── public/          ← Document Root des Webservers (einziges öffentlich erreichbares Verzeichnis)
│   ├── .htaccess    ← Erzwingt HTTPS und setzt Security-Header (CSP, HSTS)
│   ├── index.php    ← Haupt-Dashboard
│   ├── login.php    ← Google OAuth Login-Controller
│   ├── car/         ← Controller & API für das VW ID.Buzz Modul
│   ├── kassenbon/   ← Controller & Cron-Trigger für Kassenbons
│   └── pvcharge/    ← Controller & Cron-Trigger für die Solarprognose
│
├── src/             ← Core-Servercode (nicht öffentlich erreichbar, PSR-4 autoloaded)
│   ├── Shared/      ← Domainübergreifende Komponenten (Datenbank, AI-Client, Logger, Mail)
│   ├── Car/         ← Business-Logik & Repositories für das ID.Buzz Modul
│   ├── Kassenbon/   ← Business-Logik & ScannerTask für Kassenbons
│   └── PVCharge/    ← Business-Logik für die Solarprognose
│
├── database/
│   └── schema.sql   ← Versioniertes Datenbankschema (MariaDB/MySQL)
├── storage/         ← Lokale Logs (vom Deployment ausgeschlossen)
└── bootstrap.php    ← Zentraler Einstiegspunkt (.env laden, Session starten)
```

> [!IMPORTANT]
> **Strikte Trennung von Backend & Browser:**  
> Das Verzeichnis `public/` ist der einzige Ort, den der Webserver direkt ausliefern darf. PHP-Dateien in `public/` dienen ausschließlich als dünne **Controller**: Sie prüfen die Berechtigung, nehmen Requests entgegen, delegieren an Klassen in `src/` und antworten (HTML oder JSON). Führe niemals Datenbankabfragen oder Business-Logik direkt im `public/`-Ordner aus.

---

## 💻 Installation & Lokales Setup

### Voraussetzungen
* PHP 8.2 oder höher mit den Erweiterungen `curl`, `pdo_mysql`, `mbstring`, `iconv`
* MySQL oder MariaDB Datenbank
* Composer zur Paketverwaltung
* Google Cloud Console Projekt (für Google OAuth)
* Google AI Studio Account (für Gemini API)

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
5. **Webserver einrichten:**  
   Konfiguriere deinen lokalen Webserver (z. B. Apache) so, dass der Document Root auf den Ordner `public/` zeigt.

---

## 🔒 Security Guidelines (für Entwickler)

Beim Hinzufügen neuer Features müssen folgende Sicherheitsrichtlinien zwingend beachtet werden:

### 1. Absicherung der Endpunkte
Jeder Endpunkt in `public/` (ausgenommen `login.php`) muss als erstes die Autorisierung prüfen:
```php
if (!isset($_SESSION['user_email'])) {
    http_response_code(401);
    exit;
}
```

### 2. Vermeidung von SQL-Injections
Verwende für **alle** Datenbankoperationen PDO Prepared Statements. Setze niemals Werte per String-Interpolation direkt in SQL-Queries ein.
```php
// ✅ Richtig
$stmt = $pdo->prepare("SELECT * FROM kb_items WHERE id = :id");
$stmt->execute([':id' => $id]);
```

### 3. CSRF-Schutz
Alle POST-Anfragen (Formulare und AJAX-Endpunkte), die den Systemzustand verändern, müssen durch einen CSRF-Token gesichert werden.
* Generiere einen Token in der Session.
* Übergib ihn im Formular (`<input type="hidden" name="csrf_token" value="...">`) oder als Request-Header (`X-CSRF-Token`).
* Validiere den Token im Controller gegen `$_SESSION['csrf_token']`.

### 4. Keine rohen Fehlermeldungen (Information Disclosure)
Fehlermeldungen oder Stack-Traces dürfen niemals an den Browser übergeben werden.
* Verwende `try-catch`-Blöcke.
* Protokolliere Detail-Fehler serverseitig über die Klasse `Logger`.
* Gib dem Browser eine neutrale Fehlermeldung aus.

### 5. Cross-Site Scripting (XSS) verhindern
Jede Ausgabe von dynamischen Inhalten (z. B. vom Benutzer manipulierte Strings oder KI-Ergebnisse) in HTML muss mit `htmlspecialchars()` escaped werden:
```html
<td><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></td>
```

---

## 🚀 Deployment (CI/CD)

Das Deployment erfolgt automatisiert über GitHub Actions (`.github/workflows/deploy.yml`) bei jedem Push auf den `main`-Branch:
1. Der Workflow baut das Projekt, installiert Abhängigkeiten mit `composer install --no-dev`.
2. Die Dateien werden inkrementell per SFTP übertragen.
3. Sensible Daten wie FTP-Verbindungsdaten sind als **GitHub Secrets** hinterlegt.
4. Dateien wie `.env`, `.git/` und `storage/` werden explizit vom Upload ausgeschlossen.

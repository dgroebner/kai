# Implementierungskonzept: Tägliche Weisheit für Kai

## 1. Zielsetzung & Funktionsweise
Auf der zentralen Übersichtsseite von Kai (`public/index.php`) wird oberhalb des App-Button-Rasters eine dezente Karte für die „Weisheit des Tages“ integriert. Der Spruch wird einmal täglich automatisiert über eine KI-Schnittstelle (Google Gemini API via `GeminiClient`) erzeugt, in der Datenbank festgeschrieben und für alle Nutzer synchron angezeigt. Eine fortlaufende Historie verhindert inhaltliche Dopplungen.

---

## 2. Datenhaltung & Schema
* **Tabelle `daily_wisdoms`:**
  * Eindeutiges Tagesdatum (`wisdom_date DATE NOT NULL UNIQUE`) zur Verhinderung von Mehrfacheinträgen am selben Tag.
  * Spalte für den vollständigen Text der Weisheit (`content TEXT NOT NULL`).
  * Technischer Zeitstempel für den Erstellungszeitpunkt (`created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`).
* **Historienfunktion:**
  * Das Schema dient gleichzeitig als chronologisches Archiv.
  * Beim Erstellen eines neuen Eintrags wird gezielt auf die Einträge der letzten 30 bis 60 Tage zugegriffen (`getRecentWisdomTexts(60)`).

---

## 3. Backend-Architektur & Generierungsablauf

### Klassenstruktur (`src/System/`)
* **`DailyWisdomRepository`**:
  * Abfrage nach Datum (`getByDate($date)`, `getToday()`).
  * Abruf der letzten N Weisheitstexte (`getRecentWisdomTexts($limit)`).
  * Persistierung (`save($date, $content)` mit `ON DUPLICATE KEY UPDATE`).
* **`DailyWisdomService`**:
  * Orchestriert Abruf, Erzeugung und Fallback-Verhalten.
  * `getWisdomForToday(bool $allowGenerateOnDemand = true)`: Liefert den Spruch für das Dashboard.
  * `ensureTodayWisdom()`: Für Cronjob-Aufrufe.
  * `generateAndSaveWisdom($date, $force, $timeout)`: Prompt-Bau mit Vermeidung von Überschneidungen zu den letzten Sprüchen, Aufruf von `GeminiClient`, Normalisierung auf „Konfuzius sagt: ...“ und Speicherung.
  * `getFallbackWisdom($date)`: Deterministische Rotation über `FALLBACK_WISDOMS` im Notfall.

### Automatisierter Hintergrundprozess (CLI / Cron)
* **CLI-Runner:** `bin/generate_wisdom.php` für nächtliche Ausführung kurz nach Mitternacht (z. B. via `5 0 * * * php bin/generate_wisdom.php`).
* **Web-Cron:** `public/shared/wisdom_cron.php` geschützt über `Auth::requireCronToken()`.
* **Periodischer Cronjob:** Integriert in `public/shared/mail.php` (Schritt 0), sodass auch ohne separate Crontab täglich automatisch ein Spruch generiert wird.
* **Prüfung:** Das Script verifiziert zuerst, ob für das aktuelle Tagesdatum bereits ein Datensatz vorliegt.
* **Kontextaufbereitung:**
  * Auslesen der letzten N Sprüche aus der Datenbank.
  * Strikte Vorgabe an das Modell, thematische Überschneidungen und ähnliche Pointen zu vermeiden.
  * **Zielgruppe & Tonalität:** Warmherzig, kinder- und frauenfreundlich, lebensnah und für nicht technik-affine Menschen verständlich. Fokus auf Familie, Zusammenhalt, Achtsamkeit, kleine Alltagsfreuden und Humor über alltägliche Kleinigkeiten (keine Nerd- oder Tech-Metaphern).
  * Formatvorgabe: Beginnend mit „Konfuzius sagt:“.
* **Persistierung:** Speichern der Antwort direkt in der Datenbank.

### Resilienz & Fallback-Strategie (Lazy Evaluation)
* **Netzwerk- oder API-Fehler:** Schlägt der nächtliche Cronjob fehl oder ist das Kontingent erschöpft, fängt das Dashboard den leeren Zustand ab.
* **On-the-fly Trigger:** Beim ersten Laden der Übersichtsseite am Morgen wird bei fehlendem Tageseintrag ein synchroner Fallback-Aufruf mit kurzem Timeout (8s) gestartet.
* **Statischer Notfall-Puffer:** Sollte die API dauerhaft nicht erreichbar sein oder ein Timeout eintreten, greift die Anwendung auf lokal hinterlegte Standardsprüche zurück, ohne die Seite zu blockieren.

---

## 4. Integration in die UI (Zentrale Übersichtsseite)

* **Platzierung:**
  * Direkt oberhalb des Rasters der App- und Modul-Buttons (`public/index.php`).
  * Volle Breite des Inhaltsbereichs, responsive Anpassung auf Mobilgeräten.
* **Visuelle Gestaltung:**
  * Kachel im einheitlichen Card-Stil von Kai mit dezentem Schatten und Rahmen (`daily-wisdom-card`).
  * Badge/Label: `✨ Weisheit des Tages`.
  * Typografie: „Konfuzius sagt:“ farblich hervorgehoben (`daily-wisdom-author`), Weisheitstext kursiv in Anführungszeichen (`daily-wisdom-text`).
* **Performance:**
  * Der Text wird direkt im Initial-Render des Dashboards über den bestehenden Controller bereitgestellt (kein zusätzlicher AJAX-Roundtrip).

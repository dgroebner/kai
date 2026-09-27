# Implementierungskonzept: Daily-Briefing-Popup mit Widgets (Kai Toolset)

Dieses Konzept definiert die Spezifikation, Architektur und schrittweise Umsetzung eines personalisierten
Daily-Briefing-Popups beim Start des Kai Toolsets[cite: 5]. Ziel ist es, dem Benutzer unmittelbar nach dem Öffnen der
Progressive Web App (PWA) die wichtigsten handlungsrelevanten Kennzahlen und Symbole auf einen Blick zu
präsentieren[cite: 3, 5].

---

## 1. Zielsetzung & Kernverhalten

* **Single-Momentum-Briefing:** Beim erstmaligen Laden der Applikation öffnet sich ein zentriertes Modal mit
  aggregierten Statuskacheln (Widgets)[cite: 3, 5].
* **Symbolik vor Text:** Schnelle Erfassbarkeit durch Piktogramme, Statusfarben und reduzierte Kennzahlen statt
  Fließtext.
* **Intelligente Kontextfilterung:** Widgets ohne aktuellen Informationsgehalt oder während definierter Ruhephasen
  werden serverseitig nicht ausgeliefert.
* **Session-Lebenszyklus:**
    * Das Modal öffnet sich pro Browsersitzung exakt einmal automatisch beim Aufruf des Haupt-Dashboards
      (`public/index.php`)[cite: 5].
    * Die Session-Prüfung erfolgt im Frontend über den Web-Storage (`sessionStorage`).
    * Über ein Aktionssymbol im Header des Dashboards kann das Modal jederzeit manuell wieder eingeblendet
      werden[cite: 5].
* **Drill-Down:** Jede Widget-Kachel ist interaktiv und führt per Klick direkt in das jeweilige Modul des
  Toolsets[cite: 5].
* **Personalisierung:** Benutzer können in ihrem Profil festlegen, welche Widgets aktiv sind und in welcher Reihenfolge
  sie gerendert werden[cite: 3].

---

## 2. Fachliche Widget-Spezifikation

### 2.1. Wetter & Bekleidung

* **Verfügbarkeit:** Dauerhaft aktiv.
* **Zeitfenster:** Betrachtung der kommenden 6 Stunden ab Aufruf.
* **Symbolik & Logik:**
    * Schirm-Piktogramm bei Regenwahrscheinlichkeit über dem Grenzwert.
    * Jacken-Piktogramm bei niedrigen Temperaturen oder starkem Wind.
    * Sonnenbrillen- oder T-Shirt-Piktogramm bei heiterem bzw. warmem Wetter.
    * Anzeige der prognostizierten Temperaturspanne (Minimum / Maximum).
* **Ziel:** Detailansicht des Wetter-Moduls.

### 2.2. Schule & Aufgaben

* **Verfügbarkeit:** Zeit- und tagesgesteuert.
* **Ruhezeiten:** Vollständig ausgeblendet von Freitag ab 13:00 Uhr bis Sonntag 12:00 Uhr sowie ganztägig an Feiertagen
  und während der sächsischen Schulferien[cite: 1].
* **Vormittagsmodus (bis 15:00 Uhr):**
    * Unterrichtsende des aktuellen Tages.
    * Gesamtzahl der Fächer.
    * Auffällige Kennzeichnung von Vertretungen, Raumwechseln oder Unterrichtsausfällen.
* **Nachmittagsmodus (ab 15:00 Uhr):**
    * Anzahl offener Hausaufgaben für den Folgetag.
    * Warnsymbol bei anstehenden Leistungskontrollen oder Klassenarbeiten am Folgetag.
* **Ziel:** Stundenplan- und Hausaufgabenübersicht.

### 2.3. Energie & Fahrzeug (PV-Anlage & VW ID.Buzz kombiniert)

* **Verfügbarkeit:** Dauerhaft aktiv[cite: 5].
* **Kombinierte Auswertung:**
    * Verschneidung von Solarertragsprognose (forecast.solar) und Batterieladestand (State of Charge, SoC) des
      ID.Buzz[cite: 3, 5].
    * *Lade-Empfehlung:* Visuell hervorgehobenes Aktionssymbol, wenn die PV-Ertragsprognose des Tages den definierten
      Schwellenwert übersteigt und das Fahrzeug nicht voll geladen ist[cite: 3, 5].
    * *Sicherheitsstatus:* Rotes Warnsymbol, falls das Fahrzeug als unverschlossen gemeldet ist[cite: 5].
    * *Standardzustand:* Ertragsprognose in Kilowattstunden, aktueller SoC in Prozent und Restreichweite.
    * *Abendmodus:* Sobald für den aktuellen Tag kein prognostizierter Solarertrag mehr ansteht (nach Sonnenuntergang), schaltet das Widget automatisch auf die Ertragsprognose und Lade-Empfehlung für **morgen** um.
* **Ziel:** Energie-Dashboard (`pvcharge/index.php`) bzw. Fahrzeugansicht (`car/index.php`).

### 2.4. Einkaufsliste (Dringlichkeits-Fokus)

* **Verfügbarkeit:** Nur sichtbar, wenn offene Artikel auf einer Einkaufsliste vorliegen[cite: 1, 4].
* **Darstellung:**
    * Prominente Darstellung offener Sofortbedarfe (Ad-hoc-Artikel) mit Prioritätsmarkierung[cite: 1, 4].
    * Anzeige der Gesamtanzahl ausstehender Artikel des regulären Wocheneinkaufs (aufgeteilt nach Rewe und
      Globus)[cite: 1].
    * Bleibt bei null offenen Posten komplett verborgen[cite: 1].
* **Ziel:** Einkaufslistenmodul[cite: 1].

### 2.5. Jubiläen & Geburtstage

* **Verfügbarkeit:** Strikt bedarfsgesteuert[cite: 3].
* **Bedingungen:**
    * Nur aktiv, wenn der Benutzer die Jubiläumsbenachrichtigung in seinen Profilpräferenzen aktiviert hat[cite: 3].
    * Nur sichtbar, wenn in den kommenden drei Tagen (heute, morgen, übermorgen) mindestens ein Ereignis stattfindet.
    * Besondere visuelle Hervorhebung bei Ereignissen am heutigen Kalendertag.
* **Ziel:** Kalender- bzw. Kontaktübersicht.

### 2.6. Finanzen

* **Verfügbarkeit:** Berechtigungsabhängig für freigeschaltete Profile[cite: 3].
* **Darstellung:**
    * Aktueller Gesamtsaldo des Girokontos[cite: 5].
    * Kumulierte Summe der anstehenden Ein- und Ausgänge der nächsten drei Tage basierend auf erfassten Fixkosten und
      Verträgen[cite: 2, 5].
* **Ziel:** Finanzübersicht (`bank/index.php`)[cite: 5].

---

## 3. Architektur & Datenfluss

### 3.1. Backend-Struktur (PSR-4: `Kai\Tools\System`)

* Gemäß Projektleitfaden wird die Aggregationslogik in der Domäne `System` angesiedelt[cite: 5].
* **Zentraler Aggregator (`BriefingService`):**
    * Initialisiert über Dependency Injection die Repositories der benötigten Domänen (`PVCharge`, `Car`, `Bank`,
      `System`, `Shopping`)[cite: 5].
    * Fragt die Daten für jedes Widget sequenziell über optimierte Aggregationsmethoden der Repositories ab[cite: 5].
    * Prüft Zeitfenster, Ferien- und Feiertagskalender für Sachsen sowie Null-Zustände serverseitig[cite: 1].
    * Behandelt Ausfälle einzelner Datenquellen isoliert, sodass ein Teilausfall nicht das gesamte Briefing
      blockiert[cite: 5].

### 3.2. Persistenz & Benutzerkonfiguration

* Erweiterung der Tabelle `user_profiles` in `database/schema.sql`[cite: 3, 5]:
    * Ergänzung eines JSON-Feldes für die Widget-Präferenzen (aktivierte Widget-Schlüssel und deren numerische
      Reihenfolge)[cite: 3].
* Ergänzung des `UserProfileRepository` um Lese- und Schreibmethoden für die Widget-Konfiguration[cite: 3].

### 3.3. Schnittstelle (API)

* Neuer Action-Handler in `public/system/api.php`[cite: 3, 5]:
    * Nimmt GET- oder POST-Anfragen zur Bereitstellung des aggregierten Briefing-Payloads entgegen.
    * Erzwingt Authentifizierung über den zentralen Auth-Guard[cite: 5].
    * Liefert ausschließlich ein bereinigtes Datenpaket der aktiven, nicht gefilterten Widgets zurück.
* **PWA Service Worker:**
    * Der neue API-Endpunkt muss in `public/sw.js` in die Bypass-Liste (Network-Only) aufgenommen werden, um veraltete
      Cachedaten beim App-Start auszuschließen[cite: 5].

---

## 4. Frontend- & Interaktionskonzept

### 4.1. Lifecycle, Cooldown & Session-Steuerung

* Beim Laden von `public/index.php` führt die modulübergreifende JavaScript-Logik folgende Prüfungen durch:
    * Abfrage des letzten Anzeige-Zeitstempels (`kai_briefing_last_seen`) und des konfigurierten Cooldowns im `localStorage`.
    * Ist der Cooldown abgelaufen (Standard: 3 Stunden) oder liegt noch kein Zeitstempel vor, wird der Briefing-Endpunkt asynchron aufgerufen.
    * Liefert das Backend handlungsrelevante Widgets zurück und ist das automatische Popup im Profil aktiviert, wird das Modal gerendert und angezeigt.
    * Sobald das Modal angezeigt oder geschlossen wird, wird der aktuelle Zeitstempel im `localStorage` hinterlegt.
* Ein Button im Kopfbereich des Dashboards erlaubt das erneute Abrufen und Einblenden des Modals zu jedem Zeitpunkt der
  Sitzung (überschreibt den Cooldown).

### 4.2. UI-Komponente & Styling

* **Modal-Container:** Angelehnt an die bestehenden Overlays des Toolsets (`public/css/style.css`), vollständig im Dark
  Mode gehalten.
* **Kachel-Raster:** Zweispaltiges CSS-Grid auf Desktop- und Tablet-Geräten, automatischer Umbruch in eine
  Einspalten-Ansicht auf Mobilgeräten.
* **Interaktion & Event-Delegation:**
  * Keine Inline-JavaScript-Attribute (vollständige Konformität zur Content Security Policy).
  * Event-Listener werden zentral an den Modal-Container gebunden und verarbeiten Klicks auf Widgets oder
    Schließen-Elemente.

### 4.3. Konfiguration im Benutzerprofil

* Ergänzung eines Abschnitts für das Start-Briefing im Profilbereich (`public/profile.php`):
  * Schalter zur Deaktivierung / Aktivierung des automatischen Popups.
  * Auswahl des Wiederholungs-Intervalls (Cooldown: 1, 2, 3, 4, 6, 8 oder 24 Stunden).
  * Bedienelemente zur Definition der Anzeigereihenfolge (Pfeil-Buttons ⬆️ / ⬇️).
  * Speicherung synchron über Formular-POST oder asynchron über die System-API.

---

## 5. Phasen der Umsetzung

1. **Schema & Repository:**
    * Datenbankschema um Standard-Widget-Präferenzen in `user_profiles` anpassen[cite: 3, 5].
    * `UserProfileRepository` um Lese- und Update-Methoden für Widget-Einstellungen erweitern[cite: 3].
2. **Backend-Service:**
    * `BriefingService` im Namespace `Kai\Tools\System` erstellen[cite: 5].
    * Anbindung der Domänen-Repositories (Wetter, Schulzeiten/Ferien, PV/Car-Kombination, Finanzen, Einkäufe,
      Jubiläen)[cite: 1, 3, 5].
    * Filterregeln für Ruhezeiten und Leerzustände implementieren.
3. **API-Integration:**
    * Handler in `public/system/api.php` einhängen[cite: 3, 5].
    * API-Pfad im Service Worker (`public/sw.js`) vom Caching ausschließen[cite: 5].
4. **Frontend-Logik:**
    * CSS-Klassen für Modal, Grid und Widget-Karten in `public/css/style.css` definieren[cite: 5].
    * JavaScript-Modul zur Initialisierung, `sessionStorage`-Verwaltung und Event-Delegation anbinden[cite: 5].
    * Manuellen Aufruf-Button in `public/index.php` integrieren[cite: 5].
5. **Verwaltungs-UI:**
    * Widget-Konfiguration in den Systemeinstellungen bereitstellen[cite: 3, 5].
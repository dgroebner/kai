# Implementierungskonzept: ÖPNV Live-Abfahrten & Pendler-Monitor (LVB / MDV)

Dieses Konzept beschreibt die Spezifikation, Architektur und Umsetzung einer personalisierten ÖPNV-Echtzeit-Anbindung für die Leipziger Verkehrsbetriebe (LVB) und den Mitteldeutschen Verkehrsverbund (MDV) innerhalb des Kai-Toolsets.

---

## 1. Ausgangslage & Motivation

Im Familienalltag existieren stark divergierende Mobilitätsmuster:
* **Berufspendler (Eltern):** Pendelt an festen Wochentagen (z. B. Mo, Mi, Do) auf einer Direktverbindung (z. B. Meusdorf $\leftrightarrow$ Augustusplatz mit Tram 15). Der morgendliche Start ist relativ fest, während der Feierabend nachmittags/abends zeitlich stark variiert.
* **Schüler (Kinder):** Pendeln an Schultagen (Mo–Fr), jedoch **nicht in den Ferien**. Es existieren Umsteigeverbindungen mit individuellen Komfort-Präferenzen (z. B. bevorzugte Straßenbahnnutzung mit kurzem Fußweg statt Bus; Vermeidung von anfälligen Umstiegen).
* **Dynamik im Alltag:** 
  * Variable Feierabendzeiten beim Berufspendler erfordern eine punktgenaue Live-Auskunft am Schreibtisch oder Smartphone (*„Muss ich zur Haltestelle rennen oder kann ich noch 8 Minuten warten?“*).
  * Da die Berufsschule der Tochter kein digitales Vertretungssystem (wie Indiware/beste.schule) anbietet, liegen in Kai keine automatischen Ausfalldaten vor. Ihr Schulweg basiert daher auf einem festen Soll-Zeitfenster an Schultagen – mit automatischer Ferienpause in den sächsischen Schulferien sowie manueller Ad-hoc-Verschiebung im UI bei Bedarf.

Standard-Apps (wie LeipzigMOVE oder DB Navigator) decken diese individuelle Familien- und Hauslogik nicht ab. Kai kann dank der bereits vorhandenen Ferien- und Feiertagslogik (`SchoolService`), der individuellen Präferenzfilter und des personalisierten Daily Briefings einen maßgeschneiderten Pendler-Assistenten bereitstellen.

---

## 2. API- & Datenquellen-Strategie

Da die alte DB-HAFAS-Schnittstelle dauerhaft abgeschaltet wurde und die LVB keine freie REST-API für Entwickler anbietet, wird eine **hybride Datenquellen-Architektur** gewählt:

| Datenquelle | Rolle in Kai | Stärken |
|---|---|---|
| **INSA HAFAS (NASA / MDV)** (`reiseauskunft.insa.de/bin/mgate.exe`) | **Primäre Live-Quelle** | Direkt an das Leitsystem der LVB angebunden; liefert sekundengenaue Verspätungsminuten (`+X Min`), tatsächliche Fahrtausfälle, Steigangaben und amtliche Störungstexte. |
| **Transitous API** (`api.transitous.org/api/v1/`) | **Routing & Fallback** | Offene REST-Schnittstelle (MOTIS-Engine); ideal für Haltestellensuche, Geocoding, Fußwegberechnung und als stabiler Fallback bei HAFAS-Wartungen. |

---

## 3. Architektur-Entscheidung: On-Demand im PHP-Backend statt Raspi-Push

Es wurde geprüft, ob die API-Abfragen über einen lokalen Heimserver (Raspberry Pi) laufen sollten. Die Entscheidung fällt eindeutig für den **direkten Aufruf im PHP-Backend (Pull-Prinzip on demand)** aus:

1. **Spontaner Feierabend & variable Zeiten:** Beim Verlassen des Büros wird der Abfahrtsmonitor ad-hoc auf dem Smartphone aufgerufen. Das PHP-Backend liefert den Live-Status innerhalb von < 150 ms für die exakte Minute des Aufbruchs.
2. **Schulweg & Ferienlogik (ohne Vertretungsplan):** Da für die Berufsschule kein digitaler Vertretungsplan vorliegt, greift Kai auf das konfigurierte Soll-Zeitfenster (Mo–Fr) zurück und pausiert das Profil über `SchoolService` vollautomatisch während der sächsischen Schulferien und an Feiertagen. Ein starrer Cron-Push auf einem Raspi könnte weder ad-hoc-Aufrufe noch diese Ferienintelligenz sauber abbilden.
3. **Keine Netzwerkbarrieren:** Kein Einrichten von Reverse-Tunneln, VPNs oder Portweiterleitungen vom Webserver zum Heim-Raspi.
4. **Kein Rate-Limit-Risiko:** Das Abfragevolumen beschränkt sich auf wenige gezielte Aufrufe pro Tag (< 20 Calls). Ein **60-Sekunden-Transient-Cache** in Kai verhindert Mehrfachabfragen bei schnellen Seiten-Reloads.

---

## 4. Fachliche Modellierung

### 4.1. Pendlerprofile (`transit_profiles`)
Jedes Profil repräsentiert eine regelmäßige Verbindung einer Person:

* **Personenbezug:** Verknüpfung mit Benutzerkonto (`user_email`) oder Schüler (`school_students.id`).
* **Start- & Zielhaltestelle:** Name und HAFAS/IBNR-Stations-ID (z. B. `Leipzig, Meusdorf` $\rightarrow$ `Leipzig, Augustusplatz`).
* **Wochentage:** Bitmaske oder JSON-Array (z. B. `[1, 3, 4]` für Mo, Mi, Do).
* **Schultags- & Ferien-Kopplung (`only_school_days`):** Ist dieses Flag aktiv, wird das Profil automatisch an Wochenenden, Feiertagen und während der sächsischen Schulferien stummgeschaltet (gekoppelt an `SchoolService`).
* **Manuelle Zeitverschiebung (UI):** Da an der Berufsschule kein automatischer Vertretungsplan vorliegt, bietet das UI für den Morgen eine Schnellauswahl (z. B. *„Heute 1 Std. später“*), um das Abfragefenster bei bekannten Ausfällen ad-hoc anzupassen.
* **Verkehrsmittel- & Routenpräferenzen:**
  * `transport_modes`: z. B. `["tram"]` (schließt Busse explizit aus).
  * `preferred_lines`: z. B. `["15", "2"]`.
  * `max_transfers`: Maximale Anzahl Umstiege (z. B. `1`).
  * `min_transfer_buffer_minutes`: Mindestumsteigezeit (z. B. `4 Min`), um knappe Sprint-Umstiege zu verhindern.

### 4.2. Umstiegs- & Anschlussüberwachung (z. B. Schulweg Tochter)
Für Verbindungen mit Umstieg überwacht Kai beide Teilstrecken parallel:
$$\text{Live-Puffer} = \text{Ist-Abfahrt}_{\text{Tram 2}} - (\text{Ist-Ankunft}_{\text{Tram 1}} + \text{Fußweg})$$
* **Grün ($\ge 3$ Min):** Umstieg gesichert.
* **Gelb ($1 - 2$ Min):** Umstieg gefährdet (spurt erforderlich).
* **Rot ($< 0$ Min):** Anschluss weg! Kai blendet sofort die nächste Folge-Tram oder Ausweichroute ein.

---

## 5. UI- & Integrationspunkte im Kai-Toolset

### 5.1. Daily Briefing Widget (`BriefingService`)
* Erscheint morgens im Briefing-Modal auf dem Dashboard (`public/index.php`), sofern am aktuellen Tag eine Fahrt für den Nutzer oder Schüler ansteht.
* **Kompakte Darstellung:**
  * Status-Piktogramm (🟢 Pünktlich / 🟡 Verzögert / 🔴 Ausfall).
  * Nächste 2 Abfahrten mit Soll-Zeit und Live-Delta: `07:38 (+0)`, `07:48 (+2)`.
  * Ein-Klick-Absprung in das Transit-Modul.

### 5.2. Dediziertes PWA-Modul (`public/transit/`)
* **Live-Abfahrtsmonitor:** Großanzeige für Meusdorf mit dynamischem Minuten-Countdown.
* **Richtungstausch-Button (`⇄`):** Schaltet per Klick zwischen Hin- und Rückfahrt um (automatische Vorbelegung je nach Tageszeit: bis 13:00 Uhr Hinfahrt, ab 13:00 Uhr Rückfahrt).
* **Kompakte Haltestellen-Favoriten:** Schneller Wechsel zwischen Meusdorf, Augustusplatz, Hauptbahnhof etc.
* **Störungsmeldungen:** Anzeige von aktuellen Baustellen- und Havariemeldungen der LVB auf den relevanten Linien.

### 5.3. Sprachassistent (`AssistantService`)
* Sprachbefehl über Google Assistant / Home Assistant:
  * *„Wann fährt die nächste Bahn?“* $\rightarrow$ Kai erkennt anhand der Uhrzeit und des Wochentags die passende Richtung und antwortet:  
    *„Die nächste Tram 15 fährt um 07:41 Uhr ab Meusdorf, aktuell pünktlich.“*

### 5.4. Proaktiver Verspätungs-Alarm (Web Push / Cron)
* Über den Kai-Cronjob (`public/shared/mail.php`) wird an aktiven Pendlertagen 20 Minuten vor der Regelfahrt ein Check ausgeführt.
* **Stille bei Normalbetrieb** (kein Benachrichtigungs-Rauschen).
* **Push-Nachricht nur bei Problemen:** *„Achtung: Tram 15 ab Meusdorf hat +9 Min Verspätung. Nimm die frühere Bahn um 07:32 Uhr!“*

---

## 6. Datenbank-Design

```sql
CREATE TABLE IF NOT EXISTS transit_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(191) NULL,
    student_id INT UNSIGNED NULL,
    profile_name VARCHAR(100) NOT NULL,
    origin_station_name VARCHAR(100) NOT NULL,
    origin_station_id VARCHAR(50) NOT NULL,
    destination_station_name VARCHAR(100) NOT NULL,
    destination_station_id VARCHAR(50) NOT NULL,
    preferred_line VARCHAR(50) NULL,
    transport_modes JSON NULL,
    active_days JSON NOT NULL,
    morning_window_start TIME NOT NULL DEFAULT '07:00:00',
    morning_window_end TIME NOT NULL DEFAULT '08:30:00',
    evening_window_start TIME NOT NULL DEFAULT '16:00:00',
    evening_window_end TIME NOT NULL DEFAULT '18:30:00',
    only_school_days TINYINT(1) NOT NULL DEFAULT 0,
    min_transfer_minutes INT UNSIGNED NOT NULL DEFAULT 4,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_email (user_email),
    INDEX idx_student_id (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transit_cache (
    cache_key VARCHAR(191) PRIMARY KEY,
    payload LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 7. Phasenplan zur Umsetzung

1. **Phase 1: Kern-Client & Live-Abfrage Meusdorf**
   * Implementierung von `InsaClient` mit HAFAS-mgate-Payload für Haltestellenabfahrten.
   * Caching-Mechanismus (`transit_cache`) mit 60-Sekunden TTL.
   * Erster Testendpunkt zur Verifikation von Live-Zeiten der Linien 15, 2 etc.

2. **Phase 2: Profilverwaltung & Schulferien-Kopplung**
   * CRUD für Pendlerprofile in `TransitProfileRepository`.
   * Anbindung an `SchoolService` zur Schultags- und sächsischen Ferien-Erkennung (automatische Ferienpause).
   * Schnellauswahl für manuelle Abfahrtsverschiebungen im UI.

3. **Phase 3: Daily Briefing & UI-Integration**
   * Integration des `Transit`-Widgets in `BriefingService`.
   * Responsive PWA-Oberfläche unter `public/transit/index.php`.

4. **Phase 4: Anschlussüberwachung & Sprachassistent**
   * Berechnung der Umstiegsrisiken für die Schulweg-Verbindung der Tochter.
   * Sprachsynthese-Erweiterung in `AssistantService`.

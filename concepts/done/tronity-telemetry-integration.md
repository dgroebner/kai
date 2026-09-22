# Konzept: Anbindung der Fahrzeug-Telemetrie via TRONITY API (VW ID.Buzz)

> **Status:** Entwurf / Machbarkeitsstudie  
> **Ziel-Domain:** `Car` (`src/Car/`, `public/car/`)  
> **Datum:** 21.09.2026  
> **Gegenstand:** Machbarkeit, Vor- und Nachteile sowie Integrationskonzept zur Ablösung der bisherigen Datenübertragung via Raspberry Pi / VW Data Act durch die Cloud-Plattform **TRONITY**.

---

## 1. Executive Summary & Machbarkeitsurteil

### Fazit
Eine Umstellung der Telemetrie- und Livedatenerfassung des VW ID.Buzz auf die **TRONITY API** ist **technisch vollständig machbar** und würde die Systemarchitektur von Kai erheblich vereinfachen und professionalisieren.

Die bisherige, fehleranfällige Kette aus:
`VW Data Act Portal` ➔ `Raspberry Pi (vwgroup-vehicle2mqtt)` ➔ `ZIP-Dumps` ➔ `Python-Forwarder` ➔ `Kai /car/telemetry API`

kann durch eine direkte, serverbasierte Cloud-to-Cloud-Kommunikation ersetzt werden:
`VW We Connect Cloud` ➔ `TRONITY Platform` ➔ `Kai Backend (Webhook & REST API)`.

### Kernbewertung
* **Machbarkeit:** Sehr hoch (standardisierte REST- und Webhook-Schnittstellen mit OAuth2).
* **Entwicklungsaufwand:** Überschaubar (~1–2 Entwicklertage für Client, Webhook-Endpunkt und DB-Erweiterung).
* **Größter Vorteil:** Vollständiger Wegfall der lokalen Raspberry-Pi-Infrastruktur, Entfall des Parsings kryptischer UUID-Keys, stabile Reichweitenwerte sowie **hochwertige neue Datenpunkte** (GPS-Standort, detaillierte Ladevorgänge, automatisches Fahrtenbuch).
* **Größter Nachteil:** Laufende Abonnement-Kosten für TRONITY (ab **4,90 € / Monat** bzw. 58,80 € / Jahr bei jährlicher Zahlweise für *TRONITY Premium*).

---

## 2. Ausgangslage vs. Zielbild

| Merkmal | Bisherige Lösung (VW Data Act + Raspi) | Zukünftige Lösung (TRONITY API) |
|---|---|---|
| **Datenquelle** | VW EU Data Act Portal (kontinuierlicher ZIP-Export) | Offizielle VW Group Cloud-Schnittstellen via TRONITY |
| **Erforderliche Hardware** | Lokaler Raspberry Pi (24/7-Betrieb, lokaler Speicher) | Keine lokale Hardware erforderlich (Server-to-Server) |
| **Prozesskette** | Scraping/Download ➔ ZIP-Archivierung ➔ Python-Forwarder ➔ Backend-POST | TRONITY Cloud Push (Webhooks) oder Backend-Polling |
| **Datenstruktur** | 100+ unstrukturierte JSON-Keys mit UUIDs und Duplikaten | Sauber normalisiertes, typisiertes JSON-Schema |
| **Latenz / Aktualität** | Oft verzögert (Batch-Generierung alle 15–60 Minuten) | Nahezu Echtzeit bei Ladestart, Ladeende und Fahrtende |
| **Wartungsaufwand** | Hoch (SD-Karten-Verschleiß, Archivbereinigung, Parsing-Fixes) | Minimal (Standardisierte REST-API, Stabilität durch TRONITY) |
| **Kosten** | 0 € (VW EU-Portal kostenlos, Stromverbrauch Raspi vernachlässigbar) | 4,90 € – 6,90 € monatlich (TRONITY-Abo) |

---

## 3. Analyse der TRONITY API & Schnittstellenarchitektur

TRONITY stellt unter `https://api.tronity.tech` eine abgesicherte REST-API zur Verfügung, die für zahlende Kunden (ab *Premium*) ohne Zusatzkosten freigeschaltet ist.

### 3.1 Authentifizierung & Autorisierung
* **Verfahren:** OAuth 2.0.
* **Credentials:** `client_id` und `client_secret` werden im TRONITY-Entwicklerportal (`app.tronity.tech`) generiert.
* **Token-Flow:** Kai ruft über den Token-Endpunkt (`POST https://api.tronity.tech/oauth/authentication`) ein temporäres Bearer-Token ab, das serverseitig gecacht wird (z. B. 1–2 Stunden Gültigkeit).

### 3.2 Relevante API-Endpunkte

1. **Fahrzeugliste:**
   * `GET /v1/vehicles`
   * Liefert alle im TRONITY-Konto hinterlegten Fahrzeuge inklusive `id`, `vin`, `name`, `make` und Modell.
2. **Aktueller Fahrzeugstatus (Live-Snapshot):**
   * `GET /v1/vehicles/{vehicleId}/last_record`
   * Liefert den aktuellsten Messwert für Ladestand (`level`), Restreichweite (`range`), Gesamtkilometer (`odometer`), Ladezustand (`charging`), Ladeleistung (`chargePower`), Steckerstatus (`plugged`), Restladezeit (`chargeRemainingTime`) sowie GPS-Position (`latitude`, `longitude`).
3. **Ladehistorie (Charges):**
   * `GET /v1/vehicles/{vehicleId}/charges`
   * Liefert abgeschlossene Ladesitzungen mit Beginn, Ende, geladenen kWh, Start-/End-SoC, Durchschnittsleistung, Ladedauer und Kosten.
4. **Fahrtenhistorie (Trips):**
   * `GET /v1/vehicles/{vehicleId}/trips`
   * Liefert abgeschlossene Fahrten mit Start-/Endzeit, Start-/End-km, Distanz, Energieverbrauch (kWh und kWh/100km) sowie Geschwindigkeitswerten.
5. **Event-Benachrichtigungen (Webhooks):**
   * `POST /v1/webhooks`
   * Ermöglicht das Registrieren von Webhook-URLs auf Kai (`https://meine-domain.de/car/webhook.php`), die von TRONITY bei Statusänderungen getriggert werden (z. B. `charging_start`, `charging_stop`, `trip_end`).

---

## 4. Feature- und Datenpunkt-Vergleich

### 4.1 Neue Datenpunkte, die es bisher in Kai nicht gab

Durch die Normalisierung und Auswertung von TRONITY stehen mehrere wertvolle Datenpunkte bereit, die das bisherige VW-Data-Act-System nicht oder nur sehr fragmentarisch lieferte:

| Neuer Datenpunkt | Beschreibung & Einheit | Nutzen für Kai |
|---|---|---|
| **Echte Restreichweite (`range`)** | Berechnete Bordcomputer-Reichweite in km | Bisher musste die Reichweite manuell im Dashboard gepflegt werden, da der Data Act oft 0 oder veraltete Werte lieferte. TRONITY liefert diesen Wert stabil. |
| **GPS-Standort (`latitude`, `longitude`)** | Breitengrad und Längengrad | Ermöglicht eine Standort-Kachel mit OpenStreetMap in `public/car/index.php` (z. B. Fahrzeug steht zu Hause, bei der Arbeit oder an einer Ladesäule). |
| **Geofencing / Ortsbestimmung** | Erkennung des aktuellen Aufenthaltsorts | Automatische Erkennung, ob das Auto am heimischen PVCharge-Standort steht. |
| **Strukturierte Einzelfahrten (`trips`)** | Distanz (km), Dauer (Min.), Verbrauch (kWh & kWh/100km) | Automatisches digitales Fahrtenbuch. Berechnung der echten ID.Buzz-Verbrauchseffizienz je Fahrt ohne manuelle Schätzung. |
| **Strukturierte Ladesitzungen (`charges`)** | Geladene Energiemenge (kWh), Ladedauer, Ladeort | Exakte Dokumentation jedes Ladevorgangs. Automatische Verknüpfung mit den Strompreisen aus `system_settings` (PVCharge vs. Netzbezug). |
| **Batteriegesundheit (SoH / Diagnostic)** | Geschätzter State of Health und Degradation in % | Langzeit-Tracking der Batteriealterung über Ladezyklen hinweg. |

### 4.2 Gegenüberstellung aller Datenpunkte

| Datenpunkt | Bisher (Data Act / Raspi) | TRONITY API | Bewertung |
|---|---|---|---|
| **Ladestand (SoC %)** | Vorhanden (nach Prio-Korrektur 65%) | Vorhanden (`level`: 65%) | Gleichwertig, bei TRONITY direkt als sauberer Int |
| **Ziel-Ladestand (Target SoC %)** | Vorhanden (`settings.target_soc`) | In der Regel vorhanden | Gleichwertig |
| **Ladeleistung (kW)** | Vorhanden (`44ed0d61...`) | Vorhanden (`chargePower`) | Gleichwertig |
| **Ladezustand (Status)** | Vorhanden (`CHARGING_HV_BATTERY`) | Vorhanden (`charging`: boolean / state) | Gleichwertig |
| **Stecker verbunden (Plug)** | Abgeleitet aus Ladeleistung | Vorhanden (`plugged`: boolean) | Besser & nativer bei TRONITY |
| **Restladezeit (Sekunden)** | Vorhanden (`cad65f6f...`) | Vorhanden (`chargeRemainingTime`) | Gleichwertig |
| **Kilometerstand (Odometer)** | Vorhanden (`mileage.value`) | Vorhanden (`odometer`) | Gleichwertig |
| **Fahrzeug-Schloss (Locked)** | Vorhanden (`locked`: true/false) | Je nach OEM-Freigabe verfügbar | Bei VW ID meist vorhanden |
| **Außentemperatur (°C)** | Vorhanden (`outdoor_temperature`) | Teils in Trip-Records vorhanden | Beim Data Act granularer |
| **HV-Batterietemperaturen (Min/Max °C)** | Vorhanden (`hvbatterytemperature`) | **Nicht separat exponiert** | **Vorteil Data Act** (falls Zelltemperatur wichtig ist) |
| **Fahrtenbuch (Trips)** | Fehlt komplett | **Vollständig vorhanden** | **Riesiger Vorteil TRONITY** |
| **GPS-Standort** | Fehlt / nicht ausgewertet | **Vollständig vorhanden** | **Riesiger Vorteil TRONITY** |
| **Ladesitzungs-Kosten** | Fehlt (nur rohes Delta) | **Vollständig vorhanden** | **Großer Vorteil TRONITY** |

---

## 5. Detaillierte Vor- und Nachteile

### Vorteile (Pro)

1. **Massive Reduktion der Systemkomplexität:**
   * Kein lokaler Raspberry Pi mehr erforderlich.
   * Keine fehleranfälligen cron-basierten Scraper, ZIP-Dumps und Python-Forwarder.
   * Keine Probleme mehr mit Datenverlust bei Neustarts, volllaufenden Speichern oder hängenden Daemons.
2. **Robustes, dokumentiertes Datenmodell:**
   * Kein Rätselraten um kryptische VW-UUIDs (`506cb83e...` vs. `ac1108b1...`).
   * Konsistente, vorvalidierte Datentypen (`odometer` als Zahl, `charging` als Status, `range` als km).
3. **Echtzeit-Fähigkeit durch Webhooks:**
   * Anstelle stündlicher oder 15-minütiger Batch-Downloads kann TRONITY das Kai-Backend sofort per Webhook benachrichtigen, wenn ein Ladevorgang startet oder stoppt.
4. **Erweiterte Features im Dashboard:**
   * Standortkarte (Wo steht der ID.Buzz gerade?).
   * Echtes Fahrtenbuch (Fahrtzeiten, Verbrauch auf 100 km).
   * Ladekosten-Auswertung (Wieviel kWh zu welchem Preis geladen?).
5. **Direkte Unterstützung von Standard-Tools:**
   * TRONITY lässt sich parallel mit Drittsystemen (z. B. ABRP – A Better Routeplanner, Apple Watch, Home Assistant) ohne Mehrfach-Scraping koppeln.

### Nachteile und Risiken (Contra)

1. **Laufende Abonnement-Kosten:**
   * TRONITY Premium kostet **4,90 € pro Monat** (bei Jahreszahlung: 58,80 € / Jahr) bzw. **6,90 € bei monatlicher Kündbarkeit**.
   * Die bisherige Data-Act-Lösung ist bis auf marginale Stromkosten für den Raspi komplett kostenlos.
2. **Abhängigkeit von einem Drittanbieter-Cloud-Dienst:**
   * Fällt TRONITY aus oder ändert TRONITY die API-Konditionen/Endpunkte, funktioniert die Telemetrie nicht mehr.
   * Zusätzliche Station im Datenfluss: VW Server ➔ TRONITY Server ➔ Kai Server.
3. **Datenschutz und DSGVO:**
   * GPS-Standort- und Fahrtenprofildaten werden an TRONITY übertragen und dort verarbeitet.
   * Zwar ist TRONITY ein deutsches Unternehmen (TRONITY GmbH mit Sitz in Mannheim) mit Hosting innerhalb der EU (DSGVO-konform), dennoch müssen die Datenflüsse in der Datenschutzerklärung und der `AGENTS.md`-Tabelle dokumentiert werden.
4. **Fehlen von internen Tiefen-Sensorwerten:**
   * Spezialdaten wie die minimalen und maximalen Akku-Zelltemperaturen (`battery_temp_min`, `battery_temp_max`) oder die Stellung der elektrischen Feststellbremse werden von TRONITY nicht in die Standard-Records gemappt.

---

## 6. Architektur- und Integrationskonzept für Kai

Sollte die Entscheidung für TRONITY fallen, empfiehlt sich folgende saubere Integration in die bestehende Architektur von Kai (gemäß `AGENTS.md`):

### 6.1 Komponenten im Backend

```
src/Car/
├── Client/
│   └── TronityClient.php          ← Kapselt OAuth2-Token, API-Aufrufe (GET last_record, charges, trips)
├── Service/
│   ├── TronitySyncService.php     ← Fachliche Logik: Konvertiert TRONITY-Payload in Kai-Datenstruktur
│   └── TronityWebhookService.php  ← Validiert eingehende Webhook-Signatur & verarbeitet Events
├── TelemetryRepository.php        ← Bestehende DB-Schicht (wird weiterverwendet)
└── VehicleDashboardRepository.php ← Bestehendes Dashboard-Repository (erweitert um Trips/Location)

public/car/
├── index.php                      ← Dashboard UI (um Standort-Kachel & Trips erweitert)
├── telemetry/index.php            ← Kann als Fallback-API bestehen bleiben
└── webhook.php                    ← Neuer, token-geschützter Endpunkt für TRONITY Webhooks
```

### 6.2 Datenfluss-Optionen

#### Option A: Reines Polling (Pull)
* Ein Cronjob in Kai (z. B. alle 10–15 Minuten) ruft `TronitySyncService::syncLatestState()` auf.
* Sehr einfach umzusetzen, benötigt keinen öffentlichen Webhook-Endpunkt.
* *Nachteil:* Statusänderungen (z. B. Ladestecker gezogen) werden mit bis zu 15 Minuten Verzögerung sichtbar.

#### Option B: Webhook-gestützt (Push + Hybrid, empfohlen)
* TRONITY sendet bei Ladestart, Ladeende und Fahrtende einen Webhook an `public/car/webhook.php`.
* Kai aktualisiert sofort den Live-Status in `vehicle_state` und schreibt das Log.
* Ergänzend läuft ein 1-stündiger Liveness-Cronjob (über `public/shared/mail.php`), um bei längerer Standzeit den State synchron zu halten.
* *Vorteil:* Sofortige Reaktion im UI, optimale Batteriewerte beim Laden.

### 6.3 Schema-Erweiterungen (`database/migration.sql`)

Die Tabelle `vehicle_state` kann ohne Bruch abwärtskompatibel erweitert werden:

```sql
-- Erweiterung vehicle_state um Standort- und Geofence-Informationen
ALTER TABLE `vehicle_state`
    ADD COLUMN `latitude` DECIMAL(10, 7) NULL AFTER `outdoor_temp_c`,
    ADD COLUMN `longitude` DECIMAL(10, 7) NULL AFTER `latitude`,
    ADD COLUMN `location_label` VARCHAR(100) NULL AFTER `longitude`;

-- Optional: Neue Tabelle für archivierte Fahrten
CREATE TABLE IF NOT EXISTS `vehicle_trips` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vin` VARCHAR(17) NOT NULL,
    `tronity_trip_id` VARCHAR(64) UNIQUE NOT NULL,
    `start_at` DATETIME NOT NULL,
    `end_at` DATETIME NOT NULL,
    `distance_km` DECIMAL(6, 2) NOT NULL,
    `consumed_kwh` DECIMAL(6, 2) NOT NULL,
    `avg_consumption_kwh_100km` DECIMAL(5, 2) NULL,
    `start_odometer` INT NOT NULL,
    `end_odometer` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_vin_start` (`vin`, `start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Neue Tabelle für strukturierte Ladevorgänge
CREATE TABLE IF NOT EXISTS `vehicle_charge_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `vin` VARCHAR(17) NOT NULL,
    `tronity_charge_id` VARCHAR(64) UNIQUE NOT NULL,
    `start_at` DATETIME NOT NULL,
    `end_at` DATETIME NOT NULL,
    `start_soc` INT NOT NULL,
    `end_soc` INT NOT NULL,
    `charged_kwh` DECIMAL(6, 2) NOT NULL,
    `avg_power_kw` DECIMAL(5, 2) NULL,
    `location_label` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_vin_charge_start` (`vin`, `start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 7. Kosten-Nutzen-Bewertung & Empfehlung

### Entscheidungskriterien

| Kriterium | VW Data Act (Status Quo) | TRONITY API |
|---|---|---|
| **Laufende Kosten** | **0 € / Monat** | **4,90 € – 6,90 € / Monat** |
| **Wartungsfreiheit** | Gering (Raspi-Wartung, Parsing-Bugs) | **Sehr hoch (Cloud-managed)** |
| **Zuverlässigkeit der Reichweite** | Mäßig (oft manuelle Pflege nötig) | **Exzellent (direkter Tacho-Wert)** |
| **Zusatzfunktionen** | Keine | **GPS, Fahrtenbuch, Ladesitzungen** |
| **Hardware-Bedarf** | Raspberry Pi dauerhaft online | **Keine** |
| **Akkutemperatur-Erfassung** | **Vorhanden** | Entfällt |

### Handlungsempfehlung
1. **Wenn die Priorität auf 0 € Betriebskosten und maximaler Datentiefe (inkl. Batteriezell-Temperaturen) liegt:**
   * Das jetzige System (Data Act mit den gerade implementierten Prio-Fixes) beibehalten. Mit der aktuellen Bereinigung stimmen SoC (65%), Ladeleistung (3,4 kW) und Ladezustand exakt.
2. **Wenn die Priorität auf Ausfallsicherheit, Wegfall des Raspberry Pi und neuen Komfort-Features (GPS, Fahrtenbuch, Ladeabrechnung) liegt:**
   * Ein 14-tägiger kostenloser Testaccount bei TRONITY empfiehlt sich.
   * Die Schnittstelle in Kai kann parallel implementiert werden, ohne das alte System sofort abzuschalten.
   * Überzeugt der Komfortgewinn gegenüber den ~59 € Jahreskosten, kann der Raspberry Pi stillgelegt werden.

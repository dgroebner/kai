# Implementierungskonzept: Ganzheitliche Reisekosten- & Ladeplanung (Kai Toolset)

Dieses Dokument definiert die Architektur und das Implementierungskonzept für das Modul **Reisekosten- & Ladeplanung** im privaten Web-Tool-Set **Kai**. Das Konzept verzahnt die Domänen **Car** (Fahrzeugtelemetrie des VW ID.Buzz via Tronity, Routen- und Ladebedarfsberechnung über ein austauschbares Routing-Interface sowie ABRP Deep Links), **PVCharge** (Solarertragsprognose für schrittweises Überschussladen) und **Bank** (Ist-Kostenabgleich über Girokonto und Kreditkarte) zu einem geschlossenen, kosteneffizienten Lebenszyklus.

---

## 1. Fachliche Zielsetzung & Kernmechanik

* **Ganzheitlicher Lebenszyklus:** Eine Fahrt wird von der automatischen Erkennung (Kalenderimport via E-Mail) über die Vorbereitung (Mehrtages-PV-Vorladung, Vorabend-Netzladung) und Durchführung (App-/CarPlay-Navigation via ABRP Deep Link) bis zur Abrechnung (automatischer Soll-/Ist-Vergleich mit echten Buchungen) begleitet.
* **Heimladungs-Fokus:** Da Laden zu Hause wirtschaftlicher als DC-Schnellladen ist, zielt die Vorbereitung darauf ab, das Fahrzeug mit maximalem Heimstrom (bevorzugt PV-Überschuss) zu starten und Unterwegs-Ladestopps auf das Notwendige zu minimieren.
* **E-Mail-Trigger via Kalender (.ics):** Vollautomatische Erkennung von Reisen aus Termineinladungen (Google Kalender), die an das bestehende IMAP-Postfach gesendet werden.
* **Hierarchische Reisen (Trip-Nesting):** Mehrtägige Hauptreisen (z. B. Urlaub) dienen als übergeordneter Rahmen; Ausflüge während dieses Zeitraums übernehmen automatisch die Unterkunftsadresse als Startort und binden sich ohne heimischen PV-Vorlauf, aber mit Vorabend-Prüfung ein.
* **Zusammenführung der Kosten:** Das System kombiniert kalkulatorische Heimstromkosten (Haushaltsstrompreis × Netz-kWh am Vorabend) mit realen Unterwegs-Ausgaben (Kreditkartenumsätze und Giro-Lastschriften im Bank-Modul) zu einer Cent-genauen Reisekostenbilanz.

---

## 2. Der 3-Phasen-Lebenszyklus einer Reise

### Phase 1: Vorbereitung & Vorlade-Kette
* **Bedarfsermittlung (Heuristik / Provider):** Sobald eine Reise erkannt wird, berechnet der aktive Routing-Provider (`RoutePlannerInterface`) Distanz, Straßenanteile und prognostizierten Energiebedarf.
* **Start-SoC-Entscheidung:**
  * Liegt der berechnete Bedarf $\le 54\text{ kWh}$ (ca. 70 % Akku) und die Distanz unter 200 km, genügt der normale Alltags-Ladestand (z. B. 80 %).
  * Bei Distanzen $> 200\text{ km}$ oder hohem Energiebedarf fordert das System **100 % Start-SoC** an.
* **PV-Vorlauf (Tage vor der Abreise):** Bei ausreichender Ertragsprognose aus `src/PVCharge/` wird der Ziel-SoC an Sonnentagen vor der Fahrt schrittweise um maximal +10 % pro Tag angehoben (Bewertung: 0,00 € bzw. entgangene Einspeisevergütung).
* **Vorabend-Ladung (Netzstrom):** Am Vorabend (z. B. 20:00 Uhr) ermittelt Kai die Differenz zwischen dem aktuellen Fahrzeug-SoC (`vehicle_state`) und 100 %. Der Nutzer erhält über das Daily Briefing die Aufforderung, an der Wallbox vollzuladen. Der errechnete kWh-Bedarf wird mit dem hinterlegten Strompreis multipliziert und als geplanter Heimstromposten verbucht.

### Phase 2: Reisedurchführung & geschachtelte Ausflüge
* **Navigation via ABRP Deep Link:** Kai generiert einen direkten ABRP-Routenlink (`https://abetterrouteplanner.com/?...`). Da der VW ID.Buzz über Tronity bereits Live-Telemetriedaten an ABRP sendet, wird der Link ohne fixen Abfahrts-SoC erzeugt, sodass ABRP in der App oder im CarPlay automatisch mit dem realen Live-SoC startet und Unterwegs-Ladestopps dynamisch optimiert.
* **Ausflüge im Urlaub (Sub-Trips):** Liegt ein Kalendertermin zeitlich innerhalb einer Hauptreise:
  * Start- und Endpunkt ist die Zieladresse der Hauptreise (Unterkunft).
  * Die PV-Vorladekette ist deaktiviert.
  * Am Vorabend prüft Kai anhand der Streckendistanz und des aktuellen Fahrzeug-SoC, ob der Ausflug ohne Unterwegs-Stopp machbar ist, und warnt bei Bedarf.

### Phase 3: Kostenabgleich & Reisekosten-Report
* **Transaktions-Matching:** Eingehende Kreditkarten- und Girobuchungen im Reisezeitraum (Ladeanbieter wie EnBW, Ionity, Tesla sowie Maut und Parken) werden als Kandidaten vorgeschlagen und der Reise zugeordnet.
* **Gesamtbilanz:**
  1. Heimstrom: Berechnete Netzladung am Vorabend (kWh × Haushaltsstrompreis).
  2. Unterwegs geladen: Reale Beträge der zugeordneten Bank- und Kreditkartenbuchungen.
  3. Nebenkosten: Zugeordnete Maut-, Park- oder Fährtickets.
* **Effizienz-Metriken:** Reale Gesamtkosten und Kosten pro 100 km über die Reisedistanz.

---

## 3. Datenaufnahme via Kalendereinladung (IMAP)

### 3.1. Parsing & Absenderprüfung
* Der Mail-Dispatcher (`src/Shared/Mail/MailDispatcher.php`) verarbeitet Nachrichten mit `text/calendar` bzw. `.ics`-Anhängen.
* **Sicherheitsfilter:** Nur Einladungen von Absendern aus den Systemeinstellungen (`system_settings`) werden verarbeitet.

### 3.2. Attribut-Zuordnung
* **Titel:** Zusammenfassung des Termins (`SUMMARY`).
* **Startzeitpunkt:** Beginndatum und Uhrzeit (`DTSTART`).
* **Endzeitpunkt:** Enddatum und Uhrzeit (`DTEND`).
* **Zielort:** Ausgelesener Ort (`LOCATION`), geocodiert in Breiten- und Längengrad.
* **Startort:** Standardmäßig die in den Systemeinstellungen hinterlegte Heimadresse samt Koordinaten.

### 3.3. Reise-Klassifizierung & Rundreisen
* **Kurztrips ($\le$ 48 Stunden):** Werden automatisch als Rundreise (Heim $\rightarrow$ Ziel $\rightarrow$ Heim) angelegt, um den gesamten Ladebedarf von zu Hause aus vorzubereiten.
* **Langzeitreisen (> 48 Stunden):** Zunächst wird nur die Hinfahrt geplant; die Rückreise wird vor Urlaubsende separat kalkuliert.

---

## 4. Domänen-Architektur & Modul-Zusammenspiel

### 4.1. Domäne: Car (`src/Car/`)

#### A. Entkoppeltes Routing-Interface (Strategy Pattern)
Um unabhängig von externen API-Freischaltungen und Kosten zu bleiben, wird die Routenberechnung über ein Interface abstrahiert:

* **Datentransferobjekt (`src/Car/RoutePlanResult.php`):**
  * `totalDistanceKm`: Gesamtdistanz in km.
  * `estimatedConsumptionKwh`: Prognostizierter Verbrauch.
  * `recommendedDepartureSoc`: Empfohlener Start-SoC (80 % oder 100 %).
  * `enRouteChargeKwh`: Geschätzter Ladebedarf unterwegs.
  * `chargingStops`: Liste geschätzter oder geplanter Ladestopps.
  * `deepLink`: Vollständige ABRP Deep-Link URL.
  * `providerName`: Kennung des Providers (`ORS_HEURISTIC` oder `ABRP_V2`).

* **Interface (`src/Car/RoutePlannerInterface.php`):**
  * `planRoute(float $startLat, float $startLon, float $destLat, float $destLon, int $targetSoc = 10): RoutePlanResult`

* **Provider 1: OpenRouteService Heuristik (`src/Car/OrsHeuristicPlanner.php`):**
  * *Standard-Provider:* Kostenlos und autark.
  * Fragt Routendistanz und Straßenklassen (Autobahn, Landstraße, Innerorts) via OpenRouteService API ab.
  * Berechnet den Verbrauch über ein ID.Buzz-Modell (z. B. Autobahn 25 kWh/100 km, Landstraße 18 kWh/100 km, Innerorts 16 kWh/100 km) inklusive optionalem Temperaturzuschlag.
  * Bestimmt Vorlade-Bedarf und generiert den passenden ABRP Deep Link.

* **Provider 2: Iternio/ABRP Planning API v2 (`src/Car/AbrpPlanner.php`):**
  * *Zukunftssicherer Provider:* Kann aktiviert werden, sobald der API-Key für das Feature `/2/plan` freigeschaltet ist.
  * Sendet strukturierte OpenAPI-Requests an `POST https://api.iternio.com/2/plan` mit `X-API-KEY`-Header, `COORDINATES`-Typ und `TYPECODE` (`volkswagen:id.buzz:22:77:rwd`).
  * Mappt reale Ladehalte und Zeiten direkt in das `RoutePlanResult`.

* **Provider-Umschaltung via Konfiguration:**
  * Gesteuert über Umgebungsvariable in `.env`:
    ```ini
    TRIP_ROUTING_PROVIDER=ORS_HEURISTIC
    # Umschalten auf ABRP bei Freischaltung:
    # TRIP_ROUTING_PROVIDER=ABRP
    ABRP_API_KEY=
    ORS_API_KEY=
    ```

#### B. ABRP Deep Link Generator (`src/Car/AbrpDeepLinkBuilder.php`)
* Erzeugt standardisierte Deep Links (`https://abetterrouteplanner.com/?...`).
* Übergibt `origin`, `destination`, `vehicle_model` und `arrival_soc`.
* Lässt `departure_soc` im Standardfall leer, damit die ABRP-App den aktuellen Live-Telemetrie-SoC via Tronity verwendet. Optional kann ein fixer Planungs-SoC übergeben werden.

#### C. Ladeplanungs- und Reiseservice (`src/Car/TripPlanningService.php`)
* Verwaltet Lebenszyklus und Status der Reisen in `car_trips`.
* Vergleicht Soll-Bedarf mit dem aktuellen Ist-SoC aus `vehicle_state`.
* Generiert geplante Ladeschritte in `car_trip_charging_steps`.

### 4.2. Domäne: PVCharge (`src/PVCharge/`)
* Stellt tägliche Ertragsprognosen der kommenden Tage (`pv_forecast_daily`) bereit.
* Ermöglicht die Bewertung, ob an Vorlauftagen ausreichend PV-Überschuss für einen +10 % SoC-Schritt zu erwarten ist.

### 4.3. Domäne: Bank (`src/Bank/`)
* Stellt Methoden bereit, um Transaktionen im Reisezeitraum (`bank_giro_transactions`, `bank_cc_transactions`) als Ausgabenkandidaten aufzulisten.
* Verknüpft Buchungen über `bank_trip_transactions` mit einer Reise.

### 4.4. Domäne: System (`src/System/`)
* **Stammdaten:** Hält Heimadresse (Text und Koordinaten), Haushaltsstrompreis (Cent/kWh) und erlaubte Kalenderabsender in `system_settings` vor.
* **Daily Briefing Integration:** Erweitert das Briefing um reisebezogene Meldungen (PV-Ladeempfehlung tagsüber, Vorabend-Vollladehinweis, Abreise-Zusammenfassung).

---

## 5. Datenmodell-Konzept (`database/schema.sql`)

```sql
-- 1. Tabelle für Reisen
CREATE TABLE IF NOT EXISTS car_trips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_trip_id INT UNSIGNED NULL,
    calendar_uid VARCHAR(255) NULL,
    title VARCHAR(255) NOT NULL,
    
    -- Geografie & Zeit
    start_address VARCHAR(255) NOT NULL,
    start_lat DECIMAL(10, 7) NOT NULL,
    start_lon DECIMAL(10, 7) NOT NULL,
    destination_address VARCHAR(255) NOT NULL,
    destination_lat DECIMAL(10, 7) NOT NULL,
    destination_lon DECIMAL(10, 7) NOT NULL,
    departure_time DATETIME NOT NULL,
    return_time DATETIME NULL,
    is_round_trip TINYINT(1) NOT NULL DEFAULT 0,
    
    -- Planungsparameter & Heuristik
    target_arrival_soc INT NOT NULL DEFAULT 10,
    planned_departure_soc INT NOT NULL DEFAULT 100,
    total_distance_km DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    estimated_consumption_kwh DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    en_route_charge_kwh DECIMAL(8, 2) NOT NULL DEFAULT 0.00,
    routing_provider VARCHAR(50) NOT NULL DEFAULT 'ORS_HEURISTIC',
    abrp_deep_link TEXT NULL,
    
    -- Status & Abrechnung
    status ENUM('entwurf', 'geplant', 'aktiv', 'abgeschlossen', 'storniert') NOT NULL DEFAULT 'geplant',
    home_charge_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    en_route_charge_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    additional_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (parent_trip_id) REFERENCES car_trips(id) ON DELETE CASCADE,
    INDEX idx_calendar_uid (calendar_uid),
    INDEX idx_departure_time (departure_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabelle für geplante Ladeabschnitte
CREATE TABLE IF NOT EXISTS car_trip_charging_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    step_type ENUM('pv_precharge', 'evening_grid', 'en_route_fast') NOT NULL,
    scheduled_date DATE NOT NULL,
    target_soc INT NOT NULL,
    planned_kwh DECIMAL(6, 2) NOT NULL,
    status ENUM('geplant', 'erledigt', 'uebersprungen') NOT NULL DEFAULT 'geplant',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (trip_id) REFERENCES car_trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Verknüpfung von Transaktionen zu Reisen
CREATE TABLE IF NOT EXISTS bank_trip_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('giro', 'creditcard') NOT NULL,
    transaction_id INT UNSIGNED NOT NULL,
    cost_category ENUM('charge', 'toll', 'parking', 'other') NOT NULL DEFAULT 'charge',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (trip_id) REFERENCES car_trips(id) ON DELETE CASCADE,
    UNIQUE KEY uq_trip_tx (transaction_type, transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
# Implementierungskonzept: Tronity-Telemetrie & Ladedaten-Integration im Kai E-Auto Modul

## 1. Zielsetzung

Erweiterung des bestehenden E-Auto Moduls im Kai Toolset um zwei dedizierte Tabs („Fahrten“ und „Ladevorgänge“). Die
Datengrundlage bilden die aggregierten Telemetriedaten der Tronity API (Trips und Charges), angereichert mit
Geofencing-Erkennung sowie der Verknüpfung zu den bereits im System vorhandenen lokalen Smart-Meter- und PV-Ertragsdaten
für Ladevorgänge am Heimatstandort.

---

## 2. Architektur & Datenfluss

1. **Datenaufnahme (Tronity Ingestion):**
    - Abruf aggregierter Trips und Charges über Tronity (via Webhook-Receiver bei Abschluss-Events oder periodischem
      Polling-Job).
    - Idempotente Speicherung anhand der eindeutigen Tronity-IDs (`trip_id`, `charge_id`), um Duplikate auszuschließen.

2. **Verarbeitung & Anreicherung:**
    - **Fahrten:** Berechnung abgeleiteter Werte (z. B. Delta SoC, Durchschnittsgeschwindigkeit, Energieverbrauch pro
      100 km) sowie Prüfung von Start- und Zielkoordinaten gegen hinterlegte Geofence-Zonen (z. B. „Zuhause“).
    - **Ladevorgänge:** Geofence-Prüfung des Ladeorts.
        - *Status PUBLIC:* Kennzeichnung als externe Ladung, Tarifauswahl bleibt vorerst ungebunden oder fällt auf
          konfigurierte Standardwerte zurück.
        - *Status HOME:* Identifikation als Heimladung. Automatischer Abgleich mit den lokalen Smart-Meter- und
          PV-Zeitreihendaten für das Intervall zwischen Ladebeginn und Ladeende.

3. **Präsentation:**
    - Bereitstellung der aufbereiteten Daten in zwei neuen Reitern („Fahrten“ und „Ladevorgänge“) innerhalb der E-Auto
      Benutzeroberfläche von Kai.

---

## 3. Datenmodellierung (Container / Tabellenstrukturen)

### A. Container: Fahrten (`vehicle_trips`)

Speichert jede abgeschlossene Fahrt als Einzelsatz.

* **Identifikatoren:** Interne ID, Tronity-Trip-Referenz-ID.
* **Zeitliche Dimension:** Startzeitpunkt, Endzeitpunkt, Dauer (Minuten).
* **Wegstrecke & Dynamik:**
    - Kilometerstand Start und Ende (km)
    - Distanz (km)
    - Durchschnittsgeschwindigkeit (km/h) – *abgeleitet aus Distanz / Fahrzeit*
* **Energetische Werte:**
    - SoC bei Start und Ende (%)
    - Netto-Batterieverbrauch (Delta SoC in % und verbrauchte kWh)
    - Durchschnittsverbrauch (kWh/100 km)
* **Umgebung & Kontext:**
    - Außentemperatur (°C) – *soweit von Tronity bereitgestellt*
* **Ortsdaten:**
    - Startkoordinaten (Latitude, Longitude) und Zielkoordinaten (Latitude, Longitude)
    - Start- und Zielort-Bezeichnung (aufgelöst über interne Geofences wie „Zuhause“ oder Freitext)

### B. Container: Ladevorgänge (`vehicle_charges`)

Speichert jede beendete Ladesession.

* **Identifikatoren:** Interne ID, Tronity-Charge-Referenz-ID.
* **Zeitliche Dimension:** Startzeitpunkt, Endzeitpunkt, Ladedauer (Minuten).
* **Batterie- & Ladedaten (Fahrzeug-Perspektive via Tronity):**
    - SoC bei Start und Ende (%)
    - Delta SoC (%)
    - Geladene Nettoenergie im Akku (kWh)
    - Durchschnittliche Ladeleistung (kW)
    - Lademodus (AC vs. DC)
* **Orts- & Tarifklassifizierung:**
    - Geokoordinaten (Latitude, Longitude)
    - Standorttyp (`HOME` vs. `PUBLIC`) – *abgeleitet via Geofence-Abgleich*
    - Tarifzuordnung / Abrechnungskategorie
* **Energie- & Kostenabgleich (nur für Standort `HOME` via Smart-Meter/PV):**
    - Zugeführte Energie laut Heimmessung / Zähler (kWh)
    - Anteil PV-Eigenverbrauch (kWh)
    - Anteil Netzbezug (kWh)
    - Ladeverlust absolut (kWh) und relativ (%) – *Differenz zwischen zugeführter Energie und Nettozuwachs im Akku*
    - Berechnete Ladekosten (€) – *basierend auf hinterlegtem Arbeitspreis für Netzstrom und Opportunitätskosten für
      PV-Strom*

---

## 4. Fachliche Geschäftslogik

### 4.1 Geofencing

* Die Koordinaten des Heimatstandorts sind bereits im PV-Modul SolarForecastService als URL-Parameter hinterlegt. Diese
  sollten in der System-Datenbank abgelegt und von dort verwendet werden.
* Geofencing des Heimatstandorts über die Koordinaten + Toleranzradius in Metern.
* Liegen Start- oder Endkoordinaten eines Trips bzw. die Koordinaten eines Ladevorgangs innerhalb des Radius, wird der
  Datensatz als „Heimatstandort / Zuhause“ deklariert.

### 4.2 Ladedaten-Splitting bei Heimladungen

Sobald ein Ladevorgang mit Typ `HOME` als beendet markiert wird:

1. **Zeitfenster-Filter:** Aggregation der lokalen Smart-Meter- und PV-Protokolldaten über den Zeitraum von `Startzeit`
   bis `Endzeit`.
2. **Aufteilung:** Aufteilung der Gesamtenergie in reinen Solar- und Hausspeicherstrom (Überschussdeckung) und
   Netzbezug.
3. **Effizienzbewertung:** Berechnung des tatsächlichen Ladeverlusts durch Gegenüberstellung der Zählerenergie an der
   Wallbox mit dem realen Netto-Akkugewinn laut Tronity.
4. **Kostenberechnung:** Multiplikation der Anteile mit den konfigurierten Kostensätzen.

---

## 5. UI/UX: Modul-Erweiterung in Kai

### Tab 1: „Fahrten“

* **Tabellarische Übersicht:** Chronologische Liste der Fahrten mit Filterung nach Zeitraum (Tag, Woche, Monat, Jahr).
* **Spaltenanzeige:** Datum/Uhrzeit, Strecke (Start- und Zielort/Geofence), Distanz, Durchschnittsgeschwindigkeit,
  Verbrauch (kWh & kWh/100 km), SoC-Verlauf (Start/Ende), Temperatur.
* **KPI-Aggregationskarten:**
    - Gesamtstrecke im gewählten Zeitraum
    - Durchschnittsverbrauch über alle gefilterten Fahrten
    - Gesamter Energiebedarf für Fahrten

### Tab 2: „Ladevorgänge“

* **Tabellarische Übersicht:** Chronologische Liste der Ladevorgänge mit Filterung nach Standorttyp (`Alle`, `Zuhause`,
  `Unterwegs`).
* **Spaltenanzeige:**
    - Datum/Uhrzeit und Dauer
    - Ladetyp (AC / DC) und Ladeort (Zuhause / Öffentlich)
    - Geladene Energie (kWh) und SoC-Sprung (von % auf %)
    - *Bei Heimladung zusätzlich:* PV-Anteil (%), Netzanteil (%), Ladeverluste (%) und Gesamtkosten (€)
* **KPI-Aggregationskarten:**
    - Geladene Gesamtenergie
    - Autarkiequote beim Laden (Verhältnis PV-Strom zu Gesamtladestrom zu Hause)
    - Durchschnittlicher Ladeverlust am Heimladepunkt
    - Summe der aufgelaufenen Ladekosten

---

## 6. Nicht-funktionale Anforderungen & Validierung

* **Robuste Synchronisation:** Polling- bzw. Webhook-Verarbeitung muss tolerant gegenüber asynchron eintreffenden Daten
  sein (z. B. wenn Tronity Fahrtdaten erst verzögert nach Fahrtende konsolidiert bereitstellt).
* **Historische Nachberechnung:** Möglichkeit, den PV-/Smart-Meter-Abgleich für Heimladungen manuell erneut anzustoßen,
  falls Zählerdaten nachträglich synchronisiert wurden.
* **Datenschutz & Speicher:** Lokale Vorhaltung der Geokoordinaten ausschließlich für die interne Distanz- und
  Geofence-Ermittlung.
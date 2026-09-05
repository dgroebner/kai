# Konzept zur Implementierung des Wettermoduls im Kai-Tool

Dieses Konzept dient als architektonische und funktionale Spezifikation zur Integration des Wettermoduls in das
Kai-Tool. Es ist in zwei aufeinanderfolgende Phasen unterteilt und beschreibt die Kernkomponenten, die Datenflüsse sowie
die visuelle Logik.

---

## 1. Projektziel und Übersicht

Das Wettermodul erweitert das Dashboard um eine tagesaktuelle, visuelle und alltagstaugliche Entscheidungshilfe für
Leipzig-Holzhausen. Kern des Moduls ist ein dynamisches SVG-Diorama (Landschaft mit Haus, Garten, braunem Labrador und
Baum), dessen Elemente (Jahreszeit, Witterung, Accessoires) basierend auf Wetterdaten automatisch gesteuert werden.

---

## 2. Technische Architektur & Datenquellen

### Phase 1: Externe API (Open-Meteo)

* **Datenquelle:** Open-Meteo API (Nutzung der hochauflösenden DWD-Modelle wie ICON-EU/ICON-D2 für den Standort
  Leipzig-Holzhausen).
* **Abruf & Caching:** Ein Backend-Prozess (PHP) ruft die stündlichen und täglichen Prognosedaten ab. Um API-Limits zu
  schützen und die Ladezeit des Dashboards zu optimieren, werden die JSON-Rohdaten lokal (z. B. in einer temporären
  Datei oder Datenbank) gecached (Intervall: ca. 30 bis 60 Minuten).
* **Relevante Parameter:**
    * Temperatur (aktuell, Min, Max)
    * Niederschlagsmenge und -wahrscheinlichkeit
    * Windgeschwindigkeit und -böen
    * Sonnenscheindauer / Wolkenbedeckung
    * Astronomische Daten (Sonnenaufgang, Sonnenuntergang, Mondphase)

### Phase 2: Lokale Wetterstation (Raspberry Pi)

* **Daten-Push:** Die auf dem Raspberry Pi laufenden lokalen Sensoren übertragen die gemessenen Ist-Werte (z. B.
  Temperatur, lokale Bodenfeuchte, Wind) via HTTP-POST-Request an einen definierten Endpunkt im Kai-Tool.
* **Fallback-Logik:** Das Modul priorisiert lokale Sensordaten für die Ist-Werte im Garten, greift für stündliche
  Prognosen des restlichen Tages jedoch auf die Open-Meteo-API zurück.

---

## 3. Evaluierungs-Engine (Die 5 Kernfragen)

Das Backend verarbeitet die Rohdaten und übersetzt sie in klare boolesche Zustände (True/False) sowie Textbausteine für
das Dashboard:

1. **Regenschirm nötig?**
    * *Bedingung:* Regenwahrscheinlichkeit übersteigt einen definierten Schwellenwert oder prognostizierte Regenmenge
      ist relevant.
2. **Jacke nötig?**
    * *Bedingung:* Aktuelle Temperatur unterschreitet einen Komfortwert oder hohe Windböen erzeugen einen starken
      Windchill-Effekt.
3. **Schal und Mütze nötig?**
    * *Bedingung:* Frostige Außentemperaturen im winterlichen Bereich.
4. **Nur im Pool aushalten (Große Hitze)?**
    * *Bedingung:* Hohe Temperaturspitzen am Nachmittag / sommerliche Hitzeperiode.
5. **Muss man heute gießen?**
    * *Bedingung:* Kombination aus geringem Niederschlag in den letzten Tagen, hoher Sonneneinstrahlung und (in Phase 2)
      niedriger lokaler Bodenfeuchte.

---

## 4. Visuelles Konzept: Das dynamische SVG-Diorama

Die Darstellung erfolgt über ein modulares Inline-SVG, das direkt im HTML gerendert wird. Das Bild setzt sich aus
verschiedenen logischen Ebenen zusammen:

* **Hintergrund & Landschaft:**
    * Zeigt ein Haus mit Garten und einen braunen Labrador als wiederkehrendes Maskottchen.
    * Der Baum passt sein Aussehen an die Jahreszeit an (Sommer: grüne Blätter; Winter: kahle Äste/Schnee).
* **Himmel & Gestirne:**
    * Wechselt je nach Tageszeit zwischen Sonne und Mond.
    * **Goldstandard:** Die Mondphase wird über eine mathematische Berechnung des Mondalters dynamisch als
      SVG-Sichel/Vollmond dargestellt.
* **Dynamische Wettereffekte & Zustände:**
    * *Wind:* Die Äste des Baumes reagieren auf die Windgeschwindigkeit und werden über CSS-Transformationen
      (Skew/Rotate) leicht gebogen.
    * *Szenenwechsel (Hund):* Je nach Temperatur und Wetterlage ändert sich die Position bzw. das Accessoire des braunen
      Labradors (z. B. entspannt im Planschbecken bei Hitze, winterlich eingepackt oder im Schnee bei Kälte).
* **Die 5-Fragen-Leiste:**
    * Eine visuelle Übersicht am unteren Rand des Dioramas, die die Ergebnisse der Evaluierungs-Engine (Schirm, Jacke,
      Mütze, Pool, Gießen) mit klaren Indikatoren (Häkchen/Kreuz) und kurzen, kindertauglichen Texten präsentiert.
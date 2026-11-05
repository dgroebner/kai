# Implementierungskonzept: „Das verrückte Labyrinth“ in Quarxl

Dieses Konzept beschreibt die vollständige Integration des Brettspiel-Klassikers **„Das verrückte Labyrinth“** in die bestehende **Quarxl** (`GamesHub`)-Plattform (`public_games/` & `src/GamesHub/`).

---

## 1. Spielprinzip & Originalgetreues Regelwerk

### 1.1 Das Spielfeld ($7 \times 7$ Raster + 1 freie Schiebekachel)
Das Labyrinth besteht aus **50 Gang-Kacheln** (49 auf dem $7 \times 7$-Spielfeld + 1 freie Kachel außerhalb):

1. **16 fest montierte Kacheln** auf allen geraden Koordinaten $(r, c)$ mit $r \in \{0, 2, 4, 6\}$ und $c \in \{0, 2, 4, 6\}$:
   - **4 Eck-Startfelder** (L-Kurven, fest nach innen ausgerichtet):
     - $(0,0)$ **Gelb** (Ausgänge: Süd, Ost)
     - $(0,6)$ **Rot** (Ausgänge: Süd, West)
     - $(6,6)$ **Grün** (Ausgänge: Nord, West)
     - $(6,0)$ **Blau** (Ausgänge: Nord, Ost)
   - **12 feste Schatz-Kacheln** (T-Kreuzungen mit je einem festen Schatz-Symbol):
     - Oberer Rand: $(0,2)$, $(0,4)$ (T-Stück nach Süden offen)
     - Unterer Rand: $(6,2)$, $(6,4)$ (T-Stück nach Norden offen)
     - Linker Rand: $(2,0)$, $(4,0)$ (T-Stück nach Osten offen)
     - Rechter Rand: $(2,6)$, $(4,6)$ (T-Stück nach Westen offen)
     - Innere Pfeiler: $(2,2)$, $(2,4)$, $(4,2)$, $(4,4)$ (T-Stücke in definierter Standardausrichtung)
2. **34 bewegliche Kacheln** (werden zu Spielbeginn gemischt und zufällig gedreht auf die restlichen 33 Felder verteilt; 1 Kachel bleibt als **freie Einschiebe-Kachel** übrig):
   - **13 Gerade Gänge (`I`)** – ohne Schatz
   - **15 Kurven (`L`)** – davon **6 mit Schatz-Symbol** und **9 ohne Schatz**
   - **6 T-Kreuzungen (`T`)** – alle **6 mit Schatz-Symbol**

> **Summe der Schätze:** $12 \text{ (auf festen T-Kacheln)} + 6 \text{ (auf beweglichen L-Kacheln)} + 6 \text{ (auf beweglichen T-Kacheln)} = \mathbf{24\text{ Schätze}}$.

---

### 1.2 Die 12 Einschub-Positionen
Nur Zeilen und Spalten mit **ungeradem Index** ($1, 3, 5$) besitzen keine festen Kacheln und können verschoben werden:
- **Von oben nach unten (`TOP`):** Spalte $1, 3, 5$
- **Von unten nach oben (`BOTTOM`):** Spalte $1, 3, 5$
- **Von links nach rechts (`LEFT`):** Zeile $1, 3, 5$
- **Von rechts nach links (`RIGHT`):** Zeile $1, 3, 5$

**Spezialregeln beim Einschieben:**
1. **Zwang zum Schieben:** Jeder Spielzug *muss* mit dem Einschieben der freien Kachel beginnen (auch wenn der gesuchte Schatz bereits ohne Schieben erreichbar wäre).
2. **Verbot der direkten Umkehrung:** Die gerade herausgeschobene Kachel darf vom nächsten Spieler **nicht** direkt an derselben Stelle in Gegenrichtung wieder eingeschoben werden (`forbidden_push` im State gespeichert).
3. **Rausgeschobene Spielfiguren (Wrap-Around):** Wird eine Spielfigur am gegenüberliegenden Rand mit der Kachel vom Brett geschoben, wird sie automatisch auf die **neu eingeschobene Kachel** auf der gegenüberliegenden Seite gesetzt (Originalregel).

---

### 1.3 Spielablauf & Phasen pro Zug
Jeder Spieler erhält zu Beginn verdeckt seinen Stapel an Schatzkarten (je nach gewähltem Modus z. B. 3, 5 oder alle gleichmäßig verteilten Karten). Jeder Spieler sieht immer nur seine **oberste aktuelle Zielkarte**.

Ein Zug besteht aus **2 Phasen**:
1. **`PHASE_SHIFT` (Kachel drehen & einschieben):**
   - Der aktive Spieler kann die freie Kachel beliebig in $90^\circ$-Schritten drehen und wählt einen der 11 erlaubten Pfeile (bzw. 12 im allerersten Zug).
2. **`PHASE_MOVE` (Spielfigur ziehen):**
   - Der Server berechnet per **Breitensuche (BFS)** alle vom aktuellen Standpunkt der Spielfigur über durchgehend offene Gänge erreichbaren Felder (`reachable_cells`).
   - Der Spieler klickt auf ein beliebiges erreichbares Feld (oder bestätigt seinen aktuellen Platz, falls er stehen bleiben möchte).
   - **Schatz gefunden:** Landet der Spieler auf dem Feld seines aktuell gesuchten Schatzes, wird die Schatzkarte als gesammelt markiert und die nächste Karte seines Stapels aufgedeckt.
   - **Spielende / Siegbedingung:**
     - *Klassisch (mit Heimweg):* Sobald alle eigenen Schätze gesammelt sind, muss der Spieler im letzten Schritt wieder sein **Start-Eckfeld** erreichen.
     - *(Optional konfigurierbar im Raum-Modal; Standard: Originalregel mit Rückkehr zum Startfeld).*

---

## 2. Architektur & Dateistruktur

Die Umsetzung fügt sich nahtlos in die bestehende Quarxl-Struktur ein (keine neuen DB-Tabellen nötig, da `games_hub_rooms` und `games_hub_players` generische JSON-States nutzen):

```
kai_root/
├── src/GamesHub/Labyrinth/
│   └── LabyrinthService.php        ← Komplette Spiellogik, Brett-Generator, BFS-Wegfindung & Bot-KI
├── public_games/
│   ├── index.php                   ← Neue Kachel „Das verrückte Labyrinth“ + Modus-Auswahl im Modal
│   ├── js/hub.js                   ← Modal-Verknüpfung für gameType = 'labyrinth'
│   ├── sw.js                       ← ggf. statische Assets berücksichtigen
│   └── labyrinth/
│       ├── index.php               ← Spieltisch-UI (Lobby, 7x7 Brett, Einschubpfeile, Schatzkarte)
│       ├── api.php                 ← JSON-Endpunkte (create, join, add_bot, start, rotate, shift, move, state, reset)
│       ├── labyrinth.css           ← Styles für 7x7 Grid, Gang-Kacheln, Animationen & Schatzkarten
│       └── labyrinth.js            ← Client-Logik, Polling, Animationssteuerung & Event-Delegation
```

---

## 3. Datenmodell (`state_json` & `sheet_json`)

### 3.1 Raum-Zustand (`games_hub_rooms.state_json`)

```json
{
  "game_mode": "standard",
  "cards_per_player": 5,
  "return_to_start": true,
  "phase": "shift",
  "turn_index": 0,
  "turn_player_id": 12,
  "winner_id": null,
  "winner_name": null,
  "board": [
    [
      {
        "type": "L",
        "rotation": 90,
        "fixed": true,
        "treasure": null,
        "start_color": "yellow"
      }
    ]
  ],
  "spare_tile": {
    "type": "T",
    "rotation": 180,
    "fixed": false,
    "treasure": "ghost"
  },
  "forbidden_push": {
    "side": "BOTTOM",
    "index": 3
  },
  "last_push": {
    "side": "TOP",
    "index": 3,
    "by_player_id": 11
  },
  "last_action_text": "Danny hat Spalte 4 von oben eingeschoben."
}
```

### 3.2 Spieler-Zustand (`games_hub_players.sheet_json`)
Damit kein Mitspieler im Netzwerk-Tab die noch verbleibenden Zielkarten der Gegner auslesen kann, wird `sheet_json` serverseitig vor der Ausgabe in `api.php?action=state` gefiltert (genau wie die Handkarten bei Uno/Poker/Skip-Bo):

```json
{
  "color": "yellow",
  "start_pos": [0, 0],
  "pos": [2, 3],
  "target_deck": ["dragon", "crown", "key"],
  "collected_treasures": ["ghost", "owl"],
  "is_bot": false
}
```
- **Gefilterte Ausgabe für Mitspieler:** Sehen nur `remaining_count: 3`, `collected_treasures: ["ghost", "owl"]`, `pos: [2, 3]` und `returning_home: false`, **nicht** aber `target_deck[0]`.
- **Ausgabe für den Spieler selbst:** Sieht zusätzlich `current_target: "dragon"`.

---

## 4. Wegfindung (BFS) & Kachel-Öffnungen

Jeder Kacheltyp besitzt in Grundstellung (`rotation = 0`) definierte offene Seiten (`N`, `E`, `S`, `W`), die bei Rotation um $90^\circ, 180^\circ, 270^\circ$ im Uhrzeigersinn weitergedreht werden:

| Kacheltyp | `rotation: 0` | `rotation: 90` | `rotation: 180` | `rotation: 270` |
|---|---|---|---|---|
| **`I` (Gerade)** | `N, S` | `E, W` | `N, S` | `E, W` |
| **`L` (Kurve)** | `N, E` | `E, S` | `S, W` | `W, N` |
| **`T` (Kreuzung)** | `E, S, W` (oben zu) | `N, S, W` (rechts zu) | `N, E, W` (unten zu) | `N, E, S` (links zu) |

Zwei benachbarte Felder $A$ und $B$ sind genau dann verbunden, wenn:
- $B$ nördlich von $A$ liegt **und** $A$ nach `N` offen ist **und** $B$ nach `S` offen ist (analog für `E`/`W`, `S`/`N`, `W`/`E`).

Der `LabyrinthService::getReachableCells(array $board, int $startRow, int $startCol)` liefert in $< 1\text{ ms}$ alle erreichbaren Koordinaten `[r, c]` zurück und übergibt sie in Phase `move` direkt an das Frontend, sodass erreichbare Kacheln sofort grün/golden hervorgehoben werden können.

---

## 5. Einstellbare Bot-Schwierigkeitsgrade (`LabyrinthBot`)

Damit Bots weder frustrierend unschlagbar noch langweilig sind, bietet das Spiel drei wählbare Schwierigkeitsgrade:

- **🌱 Leicht (`easy`, Gelegenheitsspieler):** Der Bot prüft nur 3 bis 4 zufällige Pfeile und 2 Drehungen. Weite Schlangenpfade (> 2 Felder) werden mit 80% Wahrscheinlichkeit übersehen – der Bot nähert sich schrittweise an und macht gelegentlich Fehler.
- **⚔️ Normal (`normal`, Ausgewogen - Standard):** Der Bot prüft 6 bis 7 Pfeile und 3 Drehungen. Direkte Schätze werden zuverlässig gefunden, wenn sie nahe liegen ($\le 3$ Felder); extrem lange Zickzack-Wege über das gesamte Brett werden wie bei einem guten menschlichen Spieler teilweise übersehen.
- **🧠 Meister (`master`, Gnadenlos):** Vollständige 44-Kombinationen-Breitensuche ohne menschliche Wahrnehmungsschwelle (maximale Spielstärke).

---

## 6. UI/UX-Design (`public_games/labyrinth/`)

1. **Kompakte Spieler-Leiste oben:**
   - Zeigt alle 2–4 Spieler mit ihrer Spielfigur-Farbe (🟡 Gelb, 🔴 Rot, 🟢 Grün, 🔵 Blau), Anzahl der bereits gefundenen Schätze vs. verbleibende Karten und wer gerade am Zug ist.
2. **Zentrale $9 \times 9$-Interaktionsmatrix (7×7 Brett + umlaufender Rahmen für die 12 Einschub-Pfeile):**
   - Die 12 Pfeile (`▼`, `▲`, `▶`, `◀`) sitzen exakt an den ungeraden Reihen/Spalten.
   - Der verbotene Gegen-Pfeil des Vorzugs wird mit einem kleinen `🚫`-Indikator ausgegraut.
   - Jede Kachel wird als gestochen scharfes, responsives **Inline-SVG** gerendert (Steinmauer-Textur/Pfadoptik, Eck-Markierungen für feste Kacheln, Schatz-Icon in der Mitte, farbige Spieler-Pawns mit Versatz, falls mehrere Figuren auf demselben Feld stehen).
3. **Steuerpult unter/neben dem Brett:**
   - **Links:** Aktuelle **Geheime Schatzkarte** (mit Symbol + Name, z. B. *„🐉 Drache“* oder *„🏠 Zurück zum Startfeld!“*). Wird das gesuchte Schatzfeld auf dem Brett gefunden, erhält die Kachel für diesen Spieler einen dezenten goldenen Puls-Rahmen (damit man im Gewusel nicht lange suchen muss).
   - **Rechts:** **Freie Gänge-Kachel** mit großen Buttons `↺ Links drehen` / `↻ Rechts drehen` (oder Klick direkt auf die Vorschau-Kachel) sowie einer Vorschau-Markierung am Brett.
   - In Phase `move`: Button `🚶 Stehen bleiben (Zug beenden)` + Klick-Highlighting auf alle erreichbaren Felder im Labyrinth.
4. **Sanfte Animation:**
   - Beim Einschieben gleitet die betroffene Zeile/Spalte per CSS-Animation (`transform: translate(...)`) weich um eine Kachelbreite weiter, bevor das neue Raster einrastet.

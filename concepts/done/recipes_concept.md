# Implementierungskonzept: Kochrezept-Assistent & Küchenbegleiter (Kai Toolset)

Dieses Dokument definiert das Architektur- und Implementierungskonzept für die neue Domäne **Recipes** (Kochrezepte & Küchenbegleiter) im privaten Web-Tool-Set **Kai**. Das Modul verbindet Rezeptimport (Foto, PDF, URL via multimodaler Gemini-KI), verständliche Schrittanleitungen für die ganze Familie (inkl. Jugendliche 11+ Jahre), Haushaltspräferenzen (z. B. Schalotten statt Zwiebeln), exakte Gerätesteuerung (12-Stufen-Ceranfeld), intelligente Vorbereitung (Mise en Place mit Schmorzeit-Nutzung), Timing-Synchronisation („Alles gleichzeitig heiß auf dem Tisch“) und die direkte Anbindung an die Einkaufsliste mit Vorratsprüfung.

---

## 1. Fachliche Zielsetzung & Leitprinzipien

* **Alltagstauglichkeit & Stressfreiheit:** Kochen soll entspannt sein. Häufige Stressfaktoren wie vage Beschreibungen („anschwitzen“, „mittlere Hitze“), Hin- und Herscrollen zu Mengenangaben oder kaltwerdende Beilagen werden durch klare Datenstrukturen und smarte Logik eliminiert.
* **Jugend- & Einsteigergerecht (11+ Jahre):** Die Jugendlichen kochen bereits wöchentlich selbstständig. Es bedarf keiner „Kindergarten-Warnungen“, sondern handlungsorientierter, präziser Sprache ohne Profi-Küchenjargon.
* **Präzise Haushalts-Parameter:** Statt vager Begriffe wie „mittlere Hitze“ arbeitet das System mit den realen Gegebenheiten der Küche (12-Stufen-Ceranfeld, Umluft/Ober-Unterhitze).
* **Restevermeidung & Realer Einkauf:** Skalierung berücksichtigt echte Handels-Packungsgrößen (z. B. Dosen, Becher) und markiert Vorratsartikel (Gewürze, Öl, Salz), damit die Einkaufsliste sauber bleibt.
* **Gleichzeitig fertig (Multi-Topf-Synchronisation):** Koordination mehrerer Komponenten (z. B. Braten, Kartoffeln, Gemüse, Soße) inklusive Vorheiz- und Garzeiten, sodass alles exakt zum gewünschten Servierzeitpunkt fertig ist.

---

## 2. Kernfunktionen im Detail

### 2.1 Multi-Format Import (Foto, PDF, URL via Google Gemini)
* **Quellen:**
  * **Foto:** Schnappschuss aus Kochbuch, Magazin oder handschriftlicher Karteikarte.
  * **PDF:** Rezeptdateien oder Dokumentenscans.
  * **URL:** Direkter Webseiten-Link (z. B. Foodblogs, Chefkoch).
* **KI-Extraktion (Gemini 2.5 Flash multimodaler Modus):**
  * Extrahiert: Titel, Kurzbeschreibung, Portionen, Zubereitungszeit, aktive vs. passive Kochzeit, Nährwerte (Kalorien, Eiweiß, Kohlenhydrate, Fett).
  * Zerlegt Zutaten in strukturierte Datensätze: Name, Menge, Einheit, Vorrats-Kategorie (`pantry` vs. `fresh`).
  * Zerlegt Schritte in einzelne Komponenten/Töpfe und bindet Zutatenmengen direkt inline ein.

### 2.2 Verständliche Sprache ohne Jargon (Automatische Übersetzung)
Beim Import oder der Textaufbereitung filtert Kai Küchen-Jargon heraus und übersetzt in glasklare Handlungen:
* *„Bohnen blanchieren“* → *„Bohnen 2 Minuten in kochendes Wasser geben, abgießen und sofort mit kaltem Wasser abspülen (damit sie knackig und grün bleiben).“*
* *„Schalotten anschwitzen“* → *„Schalotten bei Stufe 6 anbraten, bis sie weich und glasig sind (nicht braun werden lassen).“*
* *„Mit Wein ablöschen“* → *„Flüssigkeit hineingießen und den Bratensatz vom Pfannenboden mit dem Kochlöffel lösen.“*
* *„Zur Rose abziehen / Legieren“* → *„Bei niedriger Hitze (Stufe 3) so lange rühren, bis die Masse cremig andickt.“*

### 2.3 Konkrete Herd- & Ofeneinstellungen (12-Stufen-Ceranfeld)
In den globalen Systemeinstellungen wird das Herdprofil hinterlegt (`stove_type = ceran`, `stove_levels = 12`).
Kai übersetzt alle Temperaturangaben in exakte Stufen:
* **Stufe 1–3:** Warmhalten, Schokolade/Butter sanft schmelzen.
* **Stufe 4–5:** Sanftes Köcheln mit Deckel (Reis, Kartoffeln ziehen lassen, Soßen reduzieren).
* **Stufe 6–7:** Mittlere Hitze (Schalotten glasig dünsten, Rührei, empfindliches Gemüse, sanftes Braten ohne Verbrennen).
* **Stufe 8–9:** Normales Braten (Pfannkuchen, Fleischstreifen, Hackfleisch, Gemüse anbraten).
* **Stufe 10–11:** Scharfes Anbraten (Steaks, Schmorbraten ankrusten, Bratkartoffeln).
* **Stufe 12:** Wasser im Topf schnell zum Kochen bringen.
* **Backofen:** Immer Angabe von exakter Temperatur und Heizart (z. B. *„180 °C Umluft (oder 200 °C Ober-/Unterhitze)“*).

### 2.4 Inline-Mengen in den Kochschritten
* Kein Scrollen zwischen Zutatenliste und Arbeitsschritten.
* Mengenangaben werden direkt in den Fließtext integriert:
  * *„Eine Pfanne auf **Stufe 7** erhitzen und **1 EL Rapsöl** hineingeben. Die **2 gewürfelten Schalotten** und **1 Zehe gehackten Knoblauch** ca. 3 Minuten glasig anbraten. Anschließend **250 g Champignons** und **1 TL Thymian** dazugeben...“*

### 2.5 Intelligentes Mise en Place (Vorbereitung & Schmorzeit-Nutzung)
Statt alles hektisch im laufenden Kochprozess zu schneiden, strukturiert Kai die Vorbereitung:
1. **Vor dem Einschalten des Herdes (Mise en Place Phase 1):**
   * Benötigtes Equipment (z. B. *große Pfanne, großer Topf, Sparschäler, Schneebesen*).
   * Zutaten, die sofort bei Hitze hineinmüssen (z. B. *Schalotten würfeln, Knoblauch pressen, Gewürze bereitstellen*).
2. **Nutzung passiver Wartezeiten (Mise en Place Phase 2):**
   * Gibt es eine Gar-/Schmorzeit von $\ge 15$ Minuten (z. B. Fleisch schmort 60 Min, Auflauf backt 30 Min), platziert Kai zeitintensive Nebenarbeiten genau in dieses Zeitfenster:
   * *„⏳ Während das Gulasch auf Stufe 4 schmort (60 Min): Jetzt entspannt 800 g Kartoffeln schälen und vierteln sowie die Gurke für den Salat hobeln.“*

### 2.6 Multi-Topf Timing-Synchronisation („Gleichzeitig fertig“)
Koordiniert mehrere Gerichts-Komponenten (z. B. Braten, Kartoffeln, Gemüse, Soße) auf einen gemeinsamen Fertigstellungspunkt:
* **Berücksichtigung von Vorheiz-Phasen:** 
  * Ceranfeld Stufe 12: ca. 8–10 Min, bis 2 Liter kaltes Wasser kochen.
  * Backofen: ca. 10–12 Min Vorheizzeit auf 180 °C.
* **Zwei Modi:**
  * **Modus A: Zielzeit-Planung („Essen um 13:00 Uhr“):** Kai plant rückwärts ab 13:00 Uhr und generiert eine feste Zeituhr (Wann Braten ansetzen? Wann Kartoffelwasser aufsetzen? Wann Gemüse garen?).
  * **Modus B: Relativer Start („Wir fangen JETZT an“):** Start mit Komponente 1. Kai meldet sich mit akustischem Signal / Prompt: *„🔔 In 5 Minuten: Kartoffeltopf auf Stufe 12 aufsetzen!“*

### 2.7 Portionierung, Skalierung & Packungsgrößen
* **Skalierer:**
  * Anzahl Erwachsene (Standardfaktor 1.0) und Kinder/Jugendliche (Faktor 0.8 oder 1.0 nach Wunsch).
* **Handelsübliche Packungsgrößen & Reste-Hinweise:**
  * Rezepte verlangen oft krumme Mengen (z. B. 140 g Schmand, 280 g gehackte Tomaten).
  * Kai kennt Standardgrößen (1 Dose = 400 g, 1 Becher Schmand = 200 g, 1 Becher Sahne = 200 ml, 1 Pck. Frischkäse = 150/200 g).
  * Berechnung: 
    * *Rezeptbedarf:* 140 g Schmand.
    * *Einkaufsempfehlung:* 1 Becher (200 g).
    * *Reste-Hinweis:* *„60 g Schmand verbleiben – Tipp: Für Salatdressing, Ofenkartoffel oder Kräuterquark nutzen.“*

### 2.8 1-Klick-Übernahme auf die Einkaufsliste (mit Vorrats-Filter)
* Beim Klick auf **„Auf Einkaufsliste setzen“** öffnet sich ein Vor-Abgleich:
  * **Spalte 1: Zu kaufende Frischeartikel (vorab angehakt):** Fleisch, frisches Gemüse, Spezialzutaten.
  * **Spalte 2: Vorratsschrank prüfen (vorab DEAKTIVIERT / markiert):**
    * Speiseöl, Essig, Salz, Pfeffer, Zucker, Mehl, Brühe, Trockengewürze, Senf, Schalotten, Knoblauch.
    * Nutzer prüft mit einem Blick in den Vorratsschrank und hakt nur an, was tatsächlich leer ist.
  * Übergabe erfolgt nahtlos an die bestehende Einkaufsliste (`ShoppingListRepository`).

### 2.9 Zutaten-Alternativen & Haushaltspräferenzen
* **Haushalts-Standards:**
  * Standardregel: *„Ersetze Zwiebeln durch Schalotten“* (wird beim Import automatisch angewendet).
* **KI-Funktion „Zutat austauschen“:**
  * Klick auf z. B. *Erbsen* → Kai fragt Gemini nach 3 Alternativen (z. B. Edamame, Mais, grüne Bohnen) und passt Kochzeit/Temperatur im Schritt an.
* **Varianten-Speicherung (Forks):**
  * Rezepte können als Variante gespeichert werden (z. B. *„Klassisch“* vs. *„Familien-Variante (mit Schalotten & Hähnchen)“*).

### 2.10 Interaktiver Koch-Modus (Kitchen Screen)
* **Screen Wake Lock API:** Verhindert das automatische Abschalten des Bildschirms auf Tablet oder Smartphone während des Kochens.
* **Große, übersichtliche Typografie:** Schritte auch aus 1,5 Metern Distanz am Herd ablesbar.
* **One-Click-Timer:** Erkennt ein Schritt eine Dauer (*„20 Minuten köcheln“*), erscheint ein Button `[⏱️ Timer 20:00 starten]`, der parallel im Browser läuft und bei Ablauf ein akustisches Signal ausgibt.

---

## 3. Datenmodell (Schema-Erweiterung)

```sql
-- Rezepte Grunddaten
CREATE TABLE IF NOT EXISTS recipes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(191) NOT NULL,
    description TEXT NULL,
    source_type ENUM('url', 'photo', 'pdf', 'manual') NOT NULL DEFAULT 'manual',
    source_url VARCHAR(500) NULL,
    image_path VARCHAR(255) NULL,
    prep_time_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    cook_time_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    total_time_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    default_servings INT UNSIGNED NOT NULL DEFAULT 4,
    calories_per_serving INT UNSIGNED NULL,
    protein_per_serving DECIMAL(6,1) NULL,
    carbs_per_serving DECIMAL(6,1) NULL,
    fat_per_serving DECIMAL(6,1) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rezept-Varianten (z. B. Standard vs. Abgewandelt)
CREATE TABLE IF NOT EXISTS recipe_variants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipe_id INT UNSIGNED NOT NULL,
    variant_name VARCHAR(100) NOT NULL DEFAULT 'Standard',
    is_default TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Strukturierte Zutaten
CREATE TABLE IF NOT EXISTS recipe_ingredients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    variant_id INT UNSIGNED NOT NULL,
    ingredient_name VARCHAR(150) NOT NULL,
    quantity DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    unit VARCHAR(50) NOT NULL DEFAULT 'Stück',
    package_size DECIMAL(8,2) NULL,
    package_unit VARCHAR(50) NULL,
    is_pantry_staple TINYINT(1) NOT NULL DEFAULT 0, -- 1 = Vorratsartikel (Salz, Öl etc.)
    prep_note VARCHAR(150) NULL, -- z. B. 'gewürfelt', 'geputzt'
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (variant_id) REFERENCES recipe_variants(id) ON DELETE CASCADE,
    INDEX idx_ingredient (ingredient_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Komponenten (z. B. 'Fleisch', 'Beilage Kartoffeln', 'Gemüse', 'Soße')
CREATE TABLE IF NOT EXISTS recipe_components (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    variant_id INT UNSIGNED NOT NULL,
    component_name VARCHAR(100) NOT NULL, -- z. B. 'Schmorbraten', 'Salzkartoffeln'
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (variant_id) REFERENCES recipe_variants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Arbeitsschritte
CREATE TABLE IF NOT EXISTS recipe_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    component_id INT UNSIGNED NOT NULL,
    step_number INT UNSIGNED NOT NULL,
    instruction TEXT NOT NULL,
    phase ENUM('mise_en_place_pre', 'cooking', 'mise_en_place_parallel', 'serving') NOT NULL DEFAULT 'cooking',
    stove_level INT UNSIGNED NULL, -- Konkrete Stufe (1-12)
    oven_temp_celsius INT UNSIGNED NULL,
    oven_mode VARCHAR(50) NULL, -- z. B. 'Umluft'
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    is_passive_wait TINYINT(1) NOT NULL DEFAULT 0, -- 1 = Topf kocht alleine, Zeit für Parallelarbeit
    timer_seconds INT UNSIGNED NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (component_id) REFERENCES recipe_components(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 4. Domänen-Architektur & Modul-Zusammenspiel

Gemäß der strikten Modultrennung aus `AGENTS.md`:

```
src/Recipes/
├── RecipeRepository.php           # CRUD für Rezepte, Zutaten, Varianten, Komponenten & Schritte
├── RecipeAiParser.php             # Gemini-Extraktion (Foto/PDF/URL), Jargon-Bereinigung, Zutatentausch
├── RecipeScaler.php               # Skalierung Personen, Reste-Kalkulation, Packungsgrößen
└── RecipeTimelineService.php      # Timing-Planer (Rückwärts- & Vorwärtsrechnung, Multi-Topf)

public/recipes/
├── index.php                      # Übersicht, Rezeptliste, Filter, Suche
├── view.php                       # Rezept-Detailansicht, Skalierer, Varianten-Umschaltung
├── cook.php                       # Interaktiver Koch-Modus (Full-Screen, Wake Lock, Timer, Timeline)
└── api.php                        # AJAX-Endpunkte (Import, Zutatentausch, Übernahme Einkaufsliste)
```

### Zulässige Integrationspunkte (Ausnahmen für AGENTS.md):
* **Recipes → Einkaufsliste (`ShoppingListRepository`):** Export von ausgewählten Zutaten direkt in die Einkaufsliste.
* **Recipes → System (`SystemSettingsService`):** Auslesen der Herd-Konfiguration (`stove_levels = 12`, `stove_type = Ceran`).

---

## 5. Berechtigungskonzept
Eingebettet in das bestehende Kai-Rollenmodell:
* `recipes_read`: Rezepte einsehen, Koch-Modus nutzen, Portionen skalieren.
* `recipes_write`: Neue Rezepte importieren, bearbeiten, Zutaten austauschen, auf Einkaufsliste übertragen.

---

## 6. Phasenplan zur Umsetzung

* **Phase 1: Datenbasis & Import-Engine**
  * Migration ausführen (Tabellen `recipes`, `recipe_variants`, `recipe_ingredients`, `recipe_components`, `recipe_steps`).
  * `RecipeAiParser` mit speziellem Prompt: Foto/PDF/URL-Extraktion, Jargon-Übersetzung, Schalotten-Default, 12-Stufen-Herd.
* **Phase 2: Rezeptverwaltung & Detailansicht**
  * `public/recipes/index.php` & `view.php`: Anzeige aller Rezepte, Portionen-Skalierer, Reste-Hinweise.
  * Zutatentausch-Dialog („Alternative suchen“ via Gemini).
* **Phase 3: Einkaufslisten-Schnittstelle mit Vorratsfilter**
  * Dialog zur Übergabe auf die Einkaufsliste (Frischezutaten vs. Vorratsartikel).
* **Phase 4: Der interaktive Koch-Modus & Timing-Planer**
  * `cook.php` mit Screen Wake Lock API, Inline-Mengendarstellung, interaktiven Timern.
  * Multi-Topf-Synchronisation: Startzeit oder Ziel-Essenszeit eingeben, Kai synchronisiert alle Töpfe & Schritte automatisch.
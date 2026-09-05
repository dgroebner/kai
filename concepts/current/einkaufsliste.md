# Konzept: Intelligente Einkaufsliste im Kai Toolset

Dieses Konzept beschreibt die Architektur, Datenstrukturen und Kernfunktionen einer intelligenten, lernenden Einkaufsliste zur nahtlosen Integration in das private Web-Tool-Set **kai**. Die Lösung ersetzt bestehende Drittanbieter-Anwendungen (wie Family Wall) und nutzt die im System bereits vorhandenen Komponenten (PostgreSQL-Datenbank, eBon-Auswertung, Produktkategorien und Gemini-KI-Anbindung).

---

## 1. Ausgangssituation & Anforderungen

* **Zwei-Märkte-Strategie:** Der Einkauf teilt sich in zwei Hauptmärkte auf:
  * **Rewe:** Dient als Stammmarkt für den täglichen Bedarf.
  * **Globus:** Wird für Spezialartikel, andere Packungsgrößen und eine breitere Auswahl genutzt, die bei Rewe nicht verfügbar sind.
* **Einkaufsfrythmen:** 
  * *Wocheneinkauf:* Einmal wöchentlicher Großeinkauf.
  * *Spontane Einkäufe:* Kurzfristige Besorgungen unter der Woche bei akutem Bedarf.
* **Automatisierung & Intelligenz:** 
  * Automatische Vorschlagsgenerierung basierend auf historischen Einkäufen und eBons.
  * Einbindung saisonaler Faktoren (z. B. Schulferien im Freistaat Sachsen, die das Koch- und Verbrauchsverhalten beeinflussen).

---

## 2. Datenmodell & Architektur

Zur optimalen Integration in die bestehende PHP- und PostgreSQL-Architektur wird das Datenbankschema um folgende Bereiche erweitert:

### 2.1. Markt- und Gang-Sortierung (`market_categories`)
* Jeder Markt erhält eine eigene Gang-Reihenfolge (Aisles/Kategorien), um den Einkaufsweg optimal abzubilden.
* Die Einkaufsliste wird in den Marktansichten strikt nach diesen Gängen sortiert.

### 2.2. Artikelstamm & Markt-Zuordnung (`product_master`)
* Speichert Artikel und verknüpft sie mit ihrem bevorzugten Einkaufsort (ermittelt aus historischen Kassenbons).
* Hinterlegt durchschnittliche Verbrauchsintervalle für die automatische Bedarfsermittlung.

### 2.3. Aktive Einkaufsliste (`shopping_list_items`)
* Verwaltet die aktuell offenen Positionen mit Attributen für Menge, Zielmarkt, Erfassungsart und Abhake-Status.

---

## 3. Kernfunktionen der Einkaufsliste

### 3.1. Automatisches Markt-Splitting & Gang-Sortierung
* Artikel werden anhand der Historie oder Regeln automatisch dem passenden Markt (Rewe oder Globus) zugewiesen.
* Die Ansicht lässt sich nach Märkten filtern und zeigt die Produkte in korrekter Gang-Reihenfolge.

### 3.2. Lernende Vorschläge & eBon-Integration
* Das System analysiert historische eBons und deren Intervalle.
* Nähert sich ein Artikel dem Ende seines Verbrauchszyklus, schlägt das System ihn automatisch für den nächsten Wocheneinkauf vor.

### 3.3. Berücksichtigung der sächsischen Schulferien
* Einbindung des sächsischen Ferienkalenders, um Bedarfs- und Mengenvorschläge in Ferienzeiten automatisch anzupassen (geändertes Koch- und Vorratsverhalten).

### 3.4. Wocheneinkauf vs. Spontane Einkäufe
* **Wocheneinkauf:** Vollautomatisch generierte Vorschlagsliste zur Vorab-Prüfung vor dem Gang in den Markt.
* **Spontane Einkäufe:** Ad-hoc erfasste Artikel unter der Woche mit Markierung als spontan, um die statistische Verbrauchsanalyse nicht zu verfälschen.

### 3.5. Interaktive Bedienung & Abschluss
* **Abhaken:** Erledigte Artikel werden abgehakt und zunächst ausgeblendet ("unsichtbar").
* **Einkauf abschließen:** Ein zentraler Button löscht alle abgehakten Positionen und aktualisiert die Verbrauchsintervalle für zukünftige Prognosen.

---

## 4. KI-Integration via Gemini

* **Rezept- & Zuteilungs-Assistenz:** Nutzung des bestehenden Gemini-Clients, um Rezepte oder manuelle Text-Eingaben zu parsen, Einheiten zu berechnen und die Artikel direkt den korrekten Märkten und Kategorien zuzuordnen.
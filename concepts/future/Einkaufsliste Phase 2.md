# Implementierungskonzept: Phase 2 – Erweiterte Einkaufslisten- und E-Bon-Integration im Kai Tool

Dieses Dokument dient als detaillierte architektonische und funktionale Spezifikation für die Umsetzung von Phase 2 der
Einkaufslistenfunktion. Es beschreibt die fachlichen Abläufe, Datenstrukturen und UI/UX-Anforderungen, um sie als
Prompt-Grundlage an ein KI-Entwicklungstool (Antigravity) zu übergeben.

---

## 1. Fachliche Ziele und Überblick

Die bestehende Einkaufslistenfunktion soll um einen strukturierten, mobilen Live-Einkaufsmodus und eine intelligente
Nachbereitung erweitert werden. Kernmerkmale sind:

* Strikte Trennung zwischen **Wocheneinkauf** und **Spontaneinkauf**.
* Ein für mobile Endgeräte optimierter Live-Modus für den Supermarkt-Besuch.
* Ein Schnellfilter direkt im Markt (*Alle, Rewe, Globus*).
* Das direkte Bereinigen (Löschen) abgehackter Artikel von der aktiven Liste beim Beenden des Einkaufs.
* Die automatische Absicherung der Historie für den späteren Abgleich mit digitalen Kassenbons (E-Bons).
* Die Unterstützung mehrerer E-Bons pro Einkauf (z. B. bei einer Tour durch mehrere Märkte).

---

## 2. Datenmodell und Architekturanforderungen (Logische Struktur)

Für die Umsetzung müssen die Datenstrukturen logisch so aufgebaut sein, dass folgende Entitäten und Beziehungen
abgebildet werden:

* **Einkaufslisten (Listen-Ebene):**
    * Trennung der Listen nach Typ: `wocheneinkauf` und `spontaneinkauf`.
    * Jedes Listenelement (Artikel) muss einem Typ und optional einem bevorzugten Markt (z. B. *Rewe*, *Globus*, oder
      marktübergreifend) zugeordnet sein.

* **Aktive Einkaufs-Sessions (Vorgangs-Ebene):**
    * Wenn ein Einkauf gestartet wird, wird ein aktiver Einkaufsvorgang erzeugt.
    * Attribute: Einkaufs-Typ (Woche/Spontan), Startzeitpunkt, Status (`aktiv`, `beendet`).

* **Einkaufshistorie (Persistenz für den Abgleich):**
    * Beim Beenden des Einkaufs ("Checkout") werden alle während der Session als gekauft markierten Artikel in eine
      Historientabelle überführt.
    * Diese Tabelle speichert den Bezug zur Einkaufs-Session, den Artikelnamen, den Markt und den Zeitstempel.

* **E-Bon-Verknüpfung (n:1 Beziehung):**
    * Eine Entität für importierte E-Bons muss so gestaltet sein, dass **mehrere E-Bons** (z. B. ein Bon von Rewe und
      ein Bon von Globus) **einem gemeinsamen Einkauf** zugeordnet werden können.

---

## 3. Workflow und Funktionslogik

### A. Start des Einkaufs

1. Der Nutzer wählt im Kai Tool aus, ob ein **Wocheneinkauf** oder ein **Spontaneinkauf** gestartet werden soll.
2. Das System öffnet die entsprechende Ansicht und generiert eine aktive Einkauf-Session.

### B. Mobile Ansicht & Schnellfilter im Markt

1. Die Benutzeroberfläche schaltet auf ein mobil-optimiertes Layout mit großen, touch-freundlichen Elementen um.
2. Am oberen Bildrand wird ein fixierter Schnellfilter mit den Optionen **Alle**, **Rewe** und **Globus**
   bereitgestellt.
3. Durch Tippen auf einen Filter werden die Listeneinträge in Echtzeit gefiltert, sodass nur die relevanten Artikel für
   den jeweiligen Markt (oder alle Artikel) angezeigt werden.
4. **Wichtige Design-Entscheidung:** Es wird *keine* manuelle Erfassung von Spontankäufen während des Einkaufs im Markt
   geben (kein zeitaufwendiges Tippen im Gang). Spontankäufe werden ausschließlich im Nachgang über den E-Bon-Abgleich
   ermittelt.

### C. Abschluss des Einkaufs ("Checkout")

1. Der Nutzer klickt im Markt auf "Einkauf beenden".
2. **Datenbereinigung (Cleanup):** Alle Artikel, die während des Einkaufs als abgehakt markiert wurden, werden
   **unwiderruflich von der aktiven Einkaufsliste gelöscht**, um eine saubere Liste für das nächste Mal zu hinterlassen.
3. **Historisierung:** Die abgehakten Artikel werden parallel in der Einkaufshistorie für die Nachbereitung hinterlegt.
4. Der Status des Einkaufs wechselt auf `beendet`.

### D. E-Bon-Matching und Auswertung

1. Der Nutzer importiert später die digitalen Kassenbons (E-Bons) für diesen Einkauf.
2. Das System verknüpft die Bons mit dem entsprechenden Einkauf (wobei ein Einkauf mehrere Bons aufnehmen kann, falls
   mehrere Märkte besucht wurden).
3. Ein automatisierter Abgleich vergleicht die Positionen auf den E-Bons mit der Einkaufshistorie.
4. Artikel, die auf den Bons stehen, aber in der Historie (dem abgehakten Zettel) *nicht* vorhanden waren, werden
   automatisch als **Spontankäufe** klassifiziert und in der Auswertung verbucht.

---

## 4. UI/UX-Anforderungen für das Frontend

* **Responsive & Mobile-First:** Optimiert für Smartphones (hohe Klickflächen, guter Kontrast, schnelle Reaktionszeit).
* **Sticky Header:** Der Schnellfilter (*Alle, Rewe, Globus*) muss beim Scrollen durch die Artikelliste oben fixiert
  bleiben.
* **Visuelles Feedback:** Klar erkennbarer Status der abgehakten Artikel vor dem Beenden des Einkaufs. Eindeutiger
  Aktionsbutton zum "Einkauf beenden".
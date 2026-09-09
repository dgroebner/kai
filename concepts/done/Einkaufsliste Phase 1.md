Implementierungskonzept: Smart Shopping List – Phase 1

Dieses Konzept beschreibt die detaillierte technische und fachliche Umsetzung von Phase 1 für die smarte Einkaufsliste
im Kai Toolset. Ziel dieser Phase ist es, den Artikelstamm so zu erweitern, dass die starre 1:1-Zuordnung zwischen
Kassenbon-Positionen und Artikeln aufgelöst wird. Dadurch können unterschiedliche Schreibweisen, Marken, Märkte oder
Packungsgrößen eines logischen Artikels (z. B. Erdbeeren lose vs. 500g-Schale) flexibel auf einen einheitlichen
Master-Artikel abgebildet werden.

1. Ausgangssituation & Zielsetzung

Herausforderung: Bisher existiert im System oft eine direkte, starre Bindung zwischen dem Kassenbon-Produkt
(eBon-Eintrag) und dem internen Artikel. In der Realität besitzt ein Artikel jedoch vielfältige Ausprägungen je nach
Supermarkt, Marke oder Verpackungseinheit.

Ziel von Phase 1:

Entkopplung von eBon-Rohdaten und Artikelstamm.

Einführung einer flexiblen Zuordnungsschicht, sodass mehrere unterschiedliche eBon-Produktvarianten demselben
Master-Artikel zugewiesen werden können.

Anpassung der Darstellungslogik in der Einkaufsliste (Anzeige des eigenen Artikelnamens, darunter der originale
eBon-Name kleiner und in Klammern, sofern vorhanden).

Bereitstellung von zwei Wegen für die Artikelzuordnung: Direkt bei der Auswertung des eBons oder komfortabel im Nachgang
über die Einkaufslistenansicht ("gelernte Artikelnamen").

2. Fachliches Datenmodell & Architektur

Um diese Anforderungen abzubilden, wird das Datenbankschema um eine klare Trennung zwischen dem Master-Artikel und den
historischen eBon-Varianten erweitert.

2.1. Master-Artikelstamm (articles / product_master)

Repräsentiert den einheitlichen, eigenen Artikel (z. B. "Erdbeeren").

Enthält übergreifende Attribute wie Kategorie-Zuordnung, Standard-Markt und Standard-Einheit.

2.2. eBon-Produkt-Zuordnung (article_mappings / ebon_product_mapping)

Verknüpft die konkreten, historisch aus den eBons eingelesenen Produktbezeichnungen mit dem Master-Artikel.

Speichert das Mapping so ab, dass das System lernt: Wenn auf dem Bon "Erdb. 500g" oder "Erdbeeren lose" steht, gehört
dies zum Master-Artikel "Erdbeeren".

2.3. Einkaufslisten-Struktur (shopping_list_items)

Die aktive Einkaufsliste führt ausschließlich die bereinigten, eigenen Artikel.

Hält Referenzen auf den zugewiesenen Master-Artikel sowie optional den Ursprung (z. B. ob der Artikel durch den
eBon-Lerneffekt oder manuell hinzugefügt wurde).

3. Kernfunktionen & Workflows

3.1. Auflösung der 1:1-Zuordnung

Eingehende Kassenbon-Positionen werden nicht mehr starr als neuer Einmal-Artikel behandelt.

Das System prüft, ob für eine eBon-Bezeichnung bereits eine Zuordnung zu einem Master-Artikel existiert. Falls ja,
greift das Mapping automatisch.

3.2. UI/UX in der Einkaufsliste & Historie

Einkaufsliste: Es werden ausschließlich die eigenen, sauberen Artikelnamen angezeigt.

eBon-Ansicht / Historie: Die Darstellung wird so erweitert, dass der eigene Artikelname prominent im Vordergrund steht.
Der originale, vom Supermarkt gelieferte eBon-Name wird direkt daneben oder darunter kleiner und in Klammern
dargestellt, um die Nachvollziehbarkeit zu wahren.

3.3. Zwei Wege der Zuordnung (Lernende Artikelnamen)

Direkt im eBon: Bei der Ansicht oder Nachbearbeitung eines eingelesenen Kassenbons kann eine unzugeordnete Position
direkt per Auswahlmenü einem bestehenden Master-Artikel zugewiesen oder ein neuer erstellt werden.

In der Einkaufslistenansicht: Über eine dedizierte Ansicht oder direkt beim Verwalten der "gelernten Artikelnamen"
können Zuordnungen korrigiert oder neu angelernt werden, sodass zukünftige Importe automatisch greifen.

4. Nächste Schritte zur Umsetzung

Datenbankschema definieren: Erstellung der Relationen zwischen Master-Artikeln und den eBon-Produktvarianten.

Backend-Matching anpassen: Erweiterung der Import- und Parsing-Logik, damit eBon-Positionen gegen die Mapping-Tabelle
geprüft werden.

UI-Anpassungen: Umsetzung der erweiterten Darstellung in der eBon-Historie und dem Zuordnungs-Workflow.
# Implementierungskonzept: Ganzheitliche Reisekosten- & Ladeplanung (Kai Toolset)

Dieses Dokument definiert das Architektur- und Implementierungskonzept für das Modul **Reisekosten- & Ladeplanung** im privaten Web-Tool-Set **Kai**. Das Konzept verzahnt die Domänen **Car** (Fahrzeugtelemetrie, Routen- und Ladeplanung via ABRP/Iternio), **PVCharge** (Solarertragsprognose für schrittweises Überschussladen) und **Bank** (Ist-Kostenabgleich über Girokonto und Kreditkarte) zu einem lückenlosen Lebenszyklus.

---

## 1. Fachliche Zielsetzung & Kernmechanik

* **Ganzheitlicher Lebenszyklus:** Eine Fahrt wird von der Vorbereitung (Mehrtages-PV-Vorladung, Vorabend-Netzladung) über die Durchführung (Minimierung teurer Schnellladestopps) bis zur Abrechnung (automatischer Soll-/Ist-Vergleich mit echten Buchungen) begleitet.
* **Heimladungs-Fokus:** Da Laden zu Hause am wirtschaftlichsten ist, plant das System Routen strikt so, dass mit maximalem Vorlade-Stand gestartet wird und Unterwegs-Ladestopps auf das absolute Minimum reduziert werden.
* **E-Mail-Trigger via Kalender (.ics):** Das System erkennt Reisen vollautomatisch aus Termineinladungen von Google Kalender, die an das bestehende Kassenbon-IMAP-Postfach gesendet werden.
* **Hierarchische Reisen (Trip-Nesting):** Mehrtägige Aufenthalte (z. B. Urlaub) dienen als übergeordneter Rahmen; Ausflüge während dieses Zeitraums übernehmen automatisch die Unterkunftsadresse als Startort und binden sich ohne PV-Vorlauf, aber mit Vorabend-Prüfung ein.
* **Zusammenführung der Kosten:** Das System kombiniert kalkulatorische Heimstromkosten (Haushaltsstrompreis) mit realen Unterwegs-Ausgaben (Kreditkartenumsätze und Giro-Lastschriften) zu einer Cent-genauen Reisekostenbilanz.

---

## 2. Der 3-Phasen-Lebenszyklus einer Reise

### Phase 1: Vorbereitung & Vorlade-Kette
* **Bedarfsermittlung:** Kai berechnet die Route über ABRP mit der Annahme eines voll geladenen Akkus (oder Ziel-Start-SoC) und einem niedrigen Rest-SoC am Ziel, um maximale Reichweite aus dem Heimstrom herauszuholen.
* **PV-Vorlauf (Tage vor der Reise):** Bei ausreichender Ertragsprognose aus dem PV-Modul wird der Ladestand an Sonnentagen in Schritten von maximal 10 % SoC angehoben. Diese Energie wird kostenseitig zunächst mit 0,00 €  bewertet. Die Kosten können aus dem Ladevorgang ermittelt werden, sobald dieser über Tronity gemeldet und registriert worden ist.
* **Vorabend-Ladung (Netzstrom):** Am Abend vor der Abreise ermittelt Kai den verbleibenden SoC-Hub. Der Nutzer erhält eine Benachrichtigung, den Restbetrag an der Wallbox über Haushaltsstrom zu laden. Die hierfür benötigte Energiemenge wird mit dem in den Systemeinstellungen hinterlegten Haushaltsstrom-Arbeitspreis multipliziert und als fixer Posten verbucht. Die Kosten können aus dem Ladevorgang ermittelt werden, sobald dieser über Tronity gemeldet und registriert worden ist.

### Phase 2: Reisedurchführung & geschachtelte Ausflüge
* **Fahrten unterwegs:** Die Route wird primär über den vollen Startakku bestritten; notwendige Schnellladestopps werden auf das Nötigste beschränkt. Optional sollte der Start-SOC nicht über 80% liegen um den Fahrzeugakku zu schonen, sofern die Reise diese trotzdem noch sinnvoll zulässt.
* **Ausflüge im Urlaub (Sub-Trips):** Liegt ein Kalendertermin zeitlich innerhalb einer mehrtägigen Urlaubsreise, wird er als Unter-Reise angelegt:
  * Start- und Endpunkt ist automatisch die Zieladresse der Hauptreise (z. B. Ferienhaus).
  * Die PV-Vorladekette wird deaktiviert (keine private PV vor Ort).
  * Am Nachmittag vor dem Ausflugs prüft Kai über ABRP und den aktuellen Ist-SoC, ob die Tour ohne Unterwegs-Stopp machbar ist. Falls nicht, fordert Kai rechtzeitig zum Nachladen an einer lokalen Ladesäule auf.

### Phase 3: Kostenabgleich & Reisekosten-Report
* **Transaktions-Matching:** Eingehende Kreditkarten- und Girobuchungen im Reisezeitraum (z. B. Schnellladeanbieter, Maut, Parken) werden als Kandidaten für die Reise erkannt und manuell oder regelbasiert zugeordnet.
* **Ladekosten-Matching** Bis zum eintreffen der tatsächlichen Abrechnungen der Ladekosten, werden die von Tronity zum Ladevorgang gemeldeten Kosten als Prognose verwendet.
* **Gesamtbilanz:** Die Reisekosten setzen sich zusammen aus:
  1. Heimstrom: Exakter kWh-Hub der Vorabend-Ladung multipliziert mit dem Stromtarif. Wird bereits im Ladevorgang erfasst, wenn dieser von Tronity gemeldet wurde.
  2. Unterwegs geladen: Reale Rechnungsbeträge aus den zugeordneten Bank- und Kreditkartenbuchungen. (Vorschau aus den Kosten die Tronity zum Ladevorgang meldet)
* **Effizienz-Metriken:** Auswertung der realen Kosten pro 100 km über die gesamte Distanz.

---

## 3. Datenaufnahme via Kalendereinladung (IMAP)

### 3.1. Parsing & Absenderprüfung
* Der bestehende E-Mail-Dispatcher verarbeitet eingehende Nachrichten auf Kalenderdaten (text/calendar bzw. .ics-Anhänge).
* **Sicherheitsfilter:** Nur Einladungen, deren Absender oder Organisator in der Liste der erlaubten Benutzer hinterlegt ist, werden weiterverarbeitet.

### 3.2. Attribut-Zuordnung
* **Titel:** Entspricht der Kalender-Zusammenfassung (SUMMARY).
* **Startzeitpunkt:** Beginndatum und Uhrzeit (DTSTART).
* **Endzeitpunkt:** Enddatum und Uhrzeit (DTEND).
* **Zielort:** Ausgelesener Ort (LOCATION), der als Zieladresse verwendet wird.
* **Startort:** Standardmäßig die in den Systemeinstellungen hinterlegte Heimadresse (inklusive Geokoordinaten).

### 3.3. Rückreise-Erkennung
* **Kurztrips (<= 48 Stunden):** Die Rückreise wird standardmäßig direkt in eine Rundreise (Heim -> Ziel -> Heim) integriert, um den Ladebedarf für den gesamten Ausflug von zu Hause aus vorzubereiten.
* **Langzeitreisen (> 48 Stunden):** Es wird zunächst nur die Hinfahrt geplant; die Rückreise wird kurz vor dem Urlaubsende separat kalkuliert.

---

## 4. Domänen-Architektur & Modul-Zusammenspiel

### 4.1. Domäne: Car (src/Car/)
* **Reiseverwaltung:** Führt geplante und abgeschlossene Reisen, verwaltet Adressen, Startzeiten, Ziel-SoC und Verknüpfungen zu Eltern-Reisen.

---

## 5. Berechtigungskonzept

* Berücksichtigung des bereits etablierten Berechtigungskonzeptes mit folgenden "Unterrechten"
* **Lese-Rechte** Initial hat nur der Admin Rechte auf die Reiseplanung, separate Lese-Rechte werden per Berechtigungskonfiguration erteilt.
* **Schreib-Rechte** Initial hat nur der Admin Rechte auf die Reiseplanung, separate Lese-Rechte werden per Berechtigungskonfiguration erteilt.

--

## 6. Daily-Briefing

* Sind für den Benutzer Lese-Rechte für die Reiseverwaltung erteilt, wird im Daily-Briefing auf die nächste kurzfristig bevorstehende Reise hingewiesen. 
* Es wird darauf hingewiesen ob eine Ladung notwendig ist oder nicht und welcher Ziel-SoC erreicht werden sollte.
* Berücksichtigung der PV-Vorladekette beim Anzeigeverhalten des Briefing-Widgets
# Gesamtkonzept: Gamification & Aufgaben-Ökosystem (Kai Toolset)

## 1. Vision & Leitgedanke

Das System transformiert Haushaltsroutinen, eigenständige Verantwortungsbereiche (Tiere, Vorräte, Kochen), persönliche
Meilensteine und Geschwister-Kollaboration in ein faires, transparentes Mitmach-System.

* **Motivation vor Zwang:** Pflichten sichern eine solide Basis; signifikante Boni entstehen durch Eigeninitiative,
  Zuverlässigkeit (Streaks), Qualitätsstufen und gegenseitige Hilfsbereitschaft.
* **Altersgerechte Relevanz (11, 13, 17 Jahre):** Reicht von unmittelbaren Sofort-Erfolgen bis hin zu
  eigenverantwortlichen Projekten, Budgets und verhandelbaren Freiheiten.
* **Dynamische Selbstregulation bei Fristversäumnis:** Aufgaben, die bis zur Deadline nicht erledigt werden, blockieren
  nicht den Haushalt, sondern wandern automatisch als lukrative Bounties an die Geschwister.
* **Maximale Generizität & Wartungsarmut:** Weder spezifische Aufgaben noch feste Achievements oder Belohnungen sind
  fest einprogrammiert. Alle Regeln, Meilensteine, Kriterien und Belohnungen werden rein datengesteuert über das
  Eltern-Dashboard definiert und gepflegt.

---

## 2. Rollen- und Berechtigungskonzept (RBAC)

Das System unterscheidet strikt zwischen zwei Hauptrollen sowie dynamischen Kontext-Berechtigungen (z. B. Assistent /
Peer-Reviewer).

### Rollendefinitionen

1. **Rolle: Eltern (Admin / Supervisor)**
    * **Vollzugriff:** Anlegen, Konfigurieren und Deaktivieren von Aufgabendefinitionen, generischen Achievement-Regeln
      und Prämienkatalogen.
    * **Triage & Wichtung:** Einstufen, Bepunkten und Genehmigen von selbst eingereichten Aufgaben sowie Bestätigen von
      Assistenten- und Übernahme-Boni.
    * **Belohnungsverwaltung:** Pflege des Prämienkatalogs, Festlegung von Coin-Werten und Bearbeitung von
      Einlöseanträgen (Genehmigen / Auszahlen).
    * **Override-Recht:** Manuelle Korrektur von Punkteständen, Streaks, Fristen oder versehentlichen Fehleingaben.

2. **Rolle: Kind (User / Participant)**
    * **Eigene Aufgaben:** Einsicht in den persönlichen Wochenplan, Fristen, Ausführungsstatus und Fortschritt.
    * **Bounty Board (Pool):** Einsehen und eigenständiges Annehmen offener Aufgaben (inklusive überfälliger Aufgaben
      der Geschwister).
    * **Self-Submission („Chore Pitch“):** Einreichen spontan erledigter Hilfen oder neuer Aufgabenvorschläge.
    * **Geschwisterhilfe („Co-Op / Assistant Claim“):** Bestätigen oder Anmelden von freiwilliger Mithilfe bei Aufgaben
      anderer Kinder.
    * **Peer-Rating:** Abgabe von Feedback/Sternen für Koch-Tage anderer Familienmitglieder (ohne Einsicht in die Voten
      Dritter vor Abschluss).
    * **Prämien- & Achievement-Übersicht:** Einsehen freigeschalteter Badges, Verfolgen offener Fortschrittsbalken und
      Stellen von Anträgen auf Belohnungseinlösung.
    * **Restriktion:** Kein Zugriff auf Konfigurations- oder Regel-Editoren, keine Selbst-Freigabe von Punkten oder
      Anträgen, keine Einsicht in vertrauliche Notizen der Eltern.

---

## 3. Modul-Architektur & Systemkomponenten

Das Modul gliedert sich in fünf funktionale Subsysteme:

### A. Der generische Aufgaben- & Workflow-Kern

* **Aufgabentypen:**
    * *Feste Zuweisungen:* Wiederkehrend nach Wochentag oder Datumsintervall (z. B. Kochen, Tierversorgung).
    * *Offener Pool (Bounty Board):* Reguläre Aufgaben ohne festen Besitzer sowie **überfällige Aufgaben (Escalated
      Bounties)**.
    * *Spontan-Eintrag (Self-Claim):* Direkt nach Erledigung gemeldete Hilfen.
* **Workflow-Zustandsautomat:**
    * Standardablauf: `Geplant` -> `Eingereicht/Erledigt` -> `In Prüfung (Eltern)` -> `Bewertet/Ausgezahlt`.
    * **Eskalations-Workflow (Deadline-Überschreitung):**
        1. Aufgabe erreicht Fälligkeitszeitpunkt (`due_date` / `due_time`) ohne Einreichung durch das zugewiesene Kind.
        2. Status wechselt automatisch auf `Overdue / Escalated`.
        3. Das ursprüngliche Kind verliert die exklusive Zuweisung.
        4. Die Aufgabe wird mit einem dynamischen **Übernahme-Zuschlag (Bounty-Bonus)** auf dem Schwarzen Brett für alle
           Geschwister freigegeben.
    * Für Koch-Tage vorgeschaltet: `Rezept-Pitch` -> `Eltern-Machbarkeits-Check` -> `Einkaufslisten-Sync`.

### B. Das Wichtungs- & Bonus-Framework

* **Trennendes Währungssystem:**
    * **XP (Erfahrungspunkte):** Kumulativer Fortschritt, verfällt nie, bestimmt das Gesamtlevel und schaltet
      Status-Badges frei.
    * **Coins (Aktionswährung):** Einlösbar für konkrete Privilegien, Gutscheine oder Budgets im Prämienkatalog.
* **Wichtungskriterien (modular zuschaltbar):**
    * *Basisaufwand:* Standardwert der Aufgabe.
    * *Planungs-Bonus:* Belohnt rechtzeitiges Vorbereiten (z. B. Rezept 24h vorher eingereicht).
    * *Initiativ-Bonus:* Automatisch vorgeschlagen, wenn ein Kind eine Aufgabe selbst entdeckt und eingereicht hat.
    * *Übernahme-Bonus (Rescue-Bonus):* Zusätzliche Coins für ein Geschwisterkind, das eine überfällige Aufgabe vom
      Schwarzen Brett rettet und erledigt (z. B. Basiswert + 50 %).
    * *Streak-Reset bei Fristversäumnis:* Lässt ein Kind eine Pflichtaufgabe auf das Schwarze Brett rutschen, reißt
      dessen persönlicher Zuverlässigkeits-Streak (pädagogischer Lerneffekt ohne Minuspunkte).

### C. Kollaboration: Geschwisterhilfe & Aufgaben-Übernahme

* **Freiwillige Assistenz (Co-Op):**
    * Ein zweites Kind hilft freiwillig mit (z. B. beim Gemüseschneiden oder Zimmeraufräumen).
    * Das System schüttet einen separaten Helfer-Bonus aus, ohne dem Hauptverantwortlichen Punkte abzuziehen.
* **Aufgaben-Rettung (Rescue Claim):**
    * Landet eine versäumte Aufgabe auf dem Schwarzen Brett, kann jedes andere Kind die Quest per Klick „claimen“ und
      für sich reservieren (z. B. mit 12-Stunden-Lock).
    * Nach erfolgreicher Erledigung erhält die Retterin den vollen Basiswert **plus** den konfigurierten
      Übernahme-Bonus.

### D. Die generische Achievement- & Badge-Engine

* **Regelbasierte Auswertung (Trigger- & Event-Driven):**
    * Achievements sind rein datenbankbasierte Regelsätze, die bei Event-Abschlüssen (Aufgabe erledigt, Review
      abgeschlossen, Claim ausgeführt) abgeglichen werden.
* **Metrik-Typen (konfigurierbar über das Eltern-Dashboard):**
    * *Rescue-Events:* Rette X-mal eine überfällige Aufgabe eines Geschwisterkinds („Die Feuerwehr“).
    * *Zähler-Events:* Erledige X Aufgaben einer bestimmten Kategorie.
    * *Streak-Events:* Erledige persönliche Pflichten X Wochen in Folge ohne Eskalation auf das Schwarze Brett.
    * *Rating-Events:* Erziele einen Bewertungsdurchschnitt von mindestens Y Sternen beim Kochen.
    * *Initiativ-Events:* Reiche X genehmigte Aufgaben-Pitches völlig selbstständig ein.
* **Reward:** Jedes Achievement schüttet beim Erreichen eine konfigurierte Menge an XP und/oder Coins aus.

### E. Das generische Belohnungs- & Einlösesystem (Reward-Shop)

* **Prämienkatalog (vollständig elternverwaltet):**
    * Pflege von Belohnungen: Titel, Beschreibung, Zielgruppe/Mindestalter, Coin-Kosten, Limits (z. B. 1x pro Monat) und
      Typ (Gutschein, Privileg, Taschengeld, Event).
* **Einlöse-Workflow:**
    1. Kind stellt bei ausreichendem Coin-Guthaben einen Einlöseantrag.
    2. Coins werden reserviert.
    3. Eltern genehmigen den Antrag in der Triage-Inbox (Coins werden final abgebucht) oder lehnen mit Begründung ab
       (Coins werden freigegeben).

---

## 4. Benutzeroberfläche & Experience Design

### Das Eltern-Cockpit

* **Triage-Zentrale (Inbox):**
    * Prüfung aller eingereichten Arbeiten, Spontan-Pitches und Rescue-Erledigungen.
    * Übersicht über automatisch eskalierte, überfällige Aufgaben.
    * Genehmigung von Prämienanträgen.
* **Activity- & Vorlagen-Manager:**
    * Definition von Aufgabenmustern, Deadlines (Wochentag + Uhrzeit) und Eskalationsregeln.
    * Festlegung des Übernahme-Bonus (prozentual oder fixer Aufschlag).
* **Achievement- & Prämien-Studio:**
    * Konfiguration von Abzeichen, Zählern und Schwellenwerten.
    * Pflege des Belohnungskatalogs samt Coin-Preisen.

### Das Kids-Dashboard (Mobile-First / PWA)

* **Status-Header:** Aktueller Rang/Titel, Level-Fortschritt (XP) und verfügbares Ausgabeguthaben (Coins).
* **Meine Missionen heute:** Eigene Aufgaben mit verbleibender Frist („Noch 3 Stunden bis zur Eskalation!“).
* **Schwarzes Brett (Bounty Board):**
    * Standard-Bounties (freiwillige Gemeinschaftsaufgaben).
    * **Hervorgehobene Rettungs-Quests:** Überfällige Aufgaben der Geschwister, markiert mit Bonus-Badge (z. B. *„🔥
      Überfällig von Mia – Schnapp dir +50% Bonus-Coins!“*).
* **Aktions-Button („Ich habe geholfen!“):** Für spontane Hilfen oder gemeldete Unterstützung als Assistent.
* **Trophäen-Wand & Belohnungsshop:** Übersicht aller erreichten Badges und Visualisierung des Sparziels im Prämienshop.

---

## 5. Phasenplan für die Umsetzung mit Antigravity

1. **Phase 1: Fundament, Datenmodelle & RBAC**
    * Bereitstellung der generischen Tabellen für Aktivitäten, Submissions, Reviews, Achievements und Prämien.
    * Definition der Rollenberechtigungen (Eltern-Admin vs. Kind-User).
    * Basis-API für Aufgaben, Guthaben und Statusübergänge.

2. **Phase 2: Triage, Fristen & Eskalations-Engine**
    * Eltern-Triage für Freigaben und Einstufungen.
    * Kind-Dashboard für reguläre Aufgaben und Spontan-Meldungen.
    * **Automatisierter Deadline-Watcher:** Zeitgesteuerter Übergang überfälliger Aufgaben auf das Schwarze Brett
      inklusive Bonus-Aufschlag.

3. **Phase 3: Spezial-Workflows & Peer-Interaktion**
    * Mehrstufiger Ablauf für Koch-Tage mit Sync zur Einkaufsliste.
    * Blindes Peer-Rating für Familiengerichte mit Kritiker-Bonus.
    * Claiming- und Lock-Logik für Quests auf dem Schwarzen Brett.

4. **Phase 4: Achievement-Engine & Prämien-Shop**
    * Regelbasierte Auswertung von Achievements (inklusive Rescue- und Streak-Metriken).
    * Konfigurations-UIs im Eltern-Dashboard für Badges und Belohnungen.
    * Vollständiger Einlöse- und Freigabe-Workflow für den Belohnungsshop.
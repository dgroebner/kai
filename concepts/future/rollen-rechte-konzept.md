# Implementierungskonzept: Rechte- und Rollenkonzept für das Kai Tool

Dieses Dokument fasst das vollständige Architektur- und Implementierungskonzept für das Berechtigungs- und Rollensystem
des Kai Tools zusammen. Es dient als verbindliche Vorgabe für die Umsetzung.

---

## 1. Grundprinzipien & Architektur

* **Backend-First:** Die Autorisierung erfolgt ausschließlich im Backend. Das Frontend spiegelt die Rechte lediglich
  wider (Steuerung von Ansichten, Links, Buttons und Eingabefeldern über CSS-Klassen bzw. dynamische
  Attribut-Generierung).
* **Identifikator:** Jeder Benutzer wird eindeutig über seine vom Google Auth Login bereitgestellte E-Mail-Adresse
  identifiziert.
* **Admin-Fallback per `.env`:** Ein Admin wird über die `.env`-Datei definiert (z. B. `ADMIN_EMAIL=deine@email.com`).
* **Automatischer Admin-Reset & Temporärer Gruppenwechsel:** Bei *jedem* erfolgreichen Login wird geprüft, ob die
  E-Mail-Adresse dem `.env`-Admin entspricht. Ist dies der Fall, wird der Benutzer hart der Admin-Gruppe zugeordnet. Um
  während einer Session Tests mit anderen Gruppen durchzuführen, kann ein temporärer Gruppenwechsel ausgelöst werden,
  der bei einem neuen Login automatisch wieder verworfen und auf die Admin-Gruppe zurückgesetzt wird.
* **Entkopplung des Profils:** Das eigene Profil inklusive Benachrichtigungseinstellungen wird aus dem Systemmodul
  herausgelöst. Jeder authentifizierte Benutzer hat zu jeder Zeit uneingeschränkten Lese- und Schreibzugriff auf sein
  eigenes Profil (`/api/profile` / `/profile`).

---

## 2. Sprechende Rechte & Automatische Abhängigkeiten

* **Format:** Die Rechte werden sprechend aufgebaut (z. B. `ebon_read`, `ebon_write`, `finance_read`, `finance_write`).
* **Automatische Verknüpfung:** Die Admin-Oberfläche und das Backend erzwingen logische Abhängigkeiten. Wird ein
  `_write`-Recht vergeben, muss das dazugehörige `_read`-Recht im Backend automatisch ebenfalls gesetzt werden.
* **Zukunftssicherheit:** Das System bleibt offen für feinere Rechte (z. B. `finance_export`), ohne das grundlegende
  Berechtigungsmuster zu verändern.

---

## 3. Datenbank-Schema (MariaDB)

Es werden folgende Tabellen benötigt (Erweiterung des bestehenden Schemas):

1. **`users`**
    * `email` (VARCHAR, PK) - Google Auth E-Mail
    * `name` (VARCHAR)
    * `created_at` / `updated_at`

2. **`groups`**
    * `id` (INT, PK, AUTO_INCREMENT)
    * `name` (VARCHAR) - z. B. "Admin", "Familie", "Gast"

3. **`group_permissions`**
    * `group_id` (INT, FK auf `groups.id`, ON DELETE CASCADE)
    * `permission` (VARCHAR) - z. B. `finance_read`, `finance_write`
    * *Primary Key:* `(group_id, permission)`

4. **`user_groups`**
    * `user_email` (VARCHAR, FK auf `users.email`, ON DELETE CASCADE)
    * `group_id` (INT, FK auf `groups.id`, ON DELETE CASCADE)
    * *Primary Key:* `(user_email, group_id)`

---

## 4. Authentifizierung & Admin-Mapping (Login-Flow)

Beim erfolgreichen Google-Auth-Login läuft im Backend folgender Prozess ab:

1. E-Mail-Adresse aus dem Google-Token auslesen und sicherstellen, dass der Benutzer in der `users`-Tabelle existiert.
2. Prüfen, ob die E-Mail-Adresse mit dem Wert aus `ADMIN_EMAIL` in der `.env`-Datei übereinstimmt:
    * **Ja:** Der Benutzer wird *zwingend* der Admin-Gruppe zugewiesen (Eintrag in `user_groups` für die Admin-Gruppe
      wird erzwungen/überschrieben). Die Session-Variable für temporäre Gruppenwechsel (`$_SESSION['temp_group_id']`)
      wird **gelöscht**, um den Admin-Status hart zu erzwingen.
    * **Nein:** Normale Zuordnung entsprechend der Datenbank-Konfiguration.
3. Laden aller Rechte der aktiven Gruppe (bzw. der temporären Test-Gruppe in `$_SESSION['temp_group_id']`, sofern die
   Berechtigung für den Test vorliegt) in ein Session-Array: `$_SESSION['permissions'] = [...]`.

---

## 5. Middleware & Rechteprüfung

Jeder Controller, jede Route und jede API muss als **erste Aktion** eine zentrale Rechteprüfung durchführen.

* **API-Requests:** Bei fehlendem Recht wird sofort mit HTTP-Status `403 Forbidden` abgelehnt (JSON-Response).
* **Seiten-Requests & Deeplinks:** Führt ein Deeplink zu einer Seite, für die das entsprechende `_read`-Recht fehlt,
  leitet das Backend den Benutzer automatisch per HTTP-Redirect auf die Hauptseite (`/`) um (optional mit einer
  Flash-Message).
* **Ausnahme Profil:** Routen, die das eigene Profil betreffen (`/profile`, `/api/profile`), prüfen lediglich, ob der
  angemeldete Benutzer das eigene Profil aufruft.

---

## 6. Frontend-Steuerung & CSS-Tags

Das Backend steuert die Darstellung im Frontend über CSS-Klassen und Attribut-Rendering:

* **Fehlendes Recht (`read` oder `write`):**
    * Links werden gar nicht erst gerendert oder erhalten eine Deaktivierungs-Klasse (z. B. `.permission-hidden` mit
      `display: none;` oder `.permission-disabled` mit `pointer-events: none; opacity: 0.5;`).
    * Eingabefelder und Buttons für Schreibaktionen erhalten bei fehlendem `_write`-Recht das Attribut `disabled` bzw.
      werden über CSS-Klassen (z. B. `.read-only-mode`) blockiert.

---

## 7. Admin-Oberfläche (Systemmodul)

Im Systemmodul wird eine Admin-Oberfläche bereitgestellt mit folgenden Funktionen:

* **Gruppenverwaltung:** Erstellen, Umbenennen und Löschen von Gruppen.
* **Rechte-Matrix:** Zuweisung von sprechenden Rechten (`modul_aktion`) zu Gruppen per Checkbox. Logik greift: Klick auf
  `_write` setzt automatisch den Haken bei `_read`.
* **Benutzer-Zuordnung:** Zuweisung von Benutzern zu Gruppen.
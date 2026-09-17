# Google Home & Home Assistant Anbindung für das Kai Toolset

Diese Dokumentation beschreibt die vollständige Einrichtung und Konfiguration der Schnittstelle zwischen **Google Assistant / Google Home**, **Home Assistant** (Gateway) und dem **Kai Toolset Backend**.

---

## 1. Architektur & Funktionsweise

```
[Google Assistant (Nest / Smartphone)]
                │ (Sprachbefehl z.B. "Wie voll ist das Auto?")
                ▼
[Home Assistant (Gateway via Nabu Casa)]
                │ HTTP POST + Bearer Token (Authorization: Bearer <TOKEN>)
                ▼
[Kai Toolset (Strato Shared Hosting)]
   └── public/assistant/index.php
        └── src/Assistant/AssistantService.php
             ├── PVCharge (Photovoltaik & Hausspeicher)
             ├── Car (VW ID.Buzz Telemetrie)
             ├── Einkaufsliste (Artikel abfragen & hinzufügen)
             └── Weather (Wetter & Kleidungsempfehlung)
                │
                ▼ JSON-Antwort { "success": true, "speech": "...", "data": {...} }
[Home Assistant (Gateway)]
                │ TTS-Sprachausgabe (z.B. Google Cast / Nest Lautsprecher)
                ▼
[Benutzer hört die Sprachausgabe]
```

---

## 2. API-Spezifikation des Kai Toolsets

* **Endpunkt:** `https://deine-domain.de/assistant/` (bzw. `https://deine-domain.de/assistant/index.php`)
* **HTTP-Methode:** `POST` (für Befehle) / `GET` (für Health-Check)
* **Authentifizierung:** Bearer-Token im HTTP-Header:
  ```http
  Authorization: Bearer <DEIN_ASSISTANT_API_KEY>
  ```
  *(Alternativ wird auch der Header `X-API-Key: <DEIN_ASSISTANT_API_KEY>` akzeptiert).*
  Der Token wird in der `.env`-Datei des Kai Toolsets unter `ASSISTANT_API_KEY` (Fallback: `CRON_TOKEN`) definiert.
* **Content-Type:** `application/json; charset=utf-8`

### Standard-Antwortformat
```json
{
  "success": true,
  "action": "get_car_status",
  "speech": "Das Auto hat aktuell 78 Prozent Ladestand und eine Reichweite von 310 Kilometern.",
  "data": {
    "soc_percent": 78,
    "range_km": 310,
    "charging_state": "not_charging"
  }
}
```

---

## 3. Unterstützte Aktionen & Payloads

### 3.1 Photovoltaik & Speicher (`get_pv_status`)
Fragt aktuelle Erzeugung, Hausspeicher-Ladestand, Netzbezug/Einspeisung und Tagesertrag ab.
* **Request:**
  ```json
  {
    "action": "get_pv_status"
  }
  ```
* **Beispiel-Sprachausgabe (`speech`):**
  *„Die Photovoltaikanlage erzeugt aktuell 3,2 Kilowatt. Der Batteriespeicher ist zu 85 Prozent geladen und wird mit 500 Watt geladen. Heute wurden bisher 14,2 Kilowattstunden Strom erzeugt.“*

### 3.2 Fahrzeug / VW ID.Buzz (`get_car_status`)
Fragt Ladestand (SoC), Reichweite und Ladeleistung ab.
* **Request:**
  ```json
  {
    "action": "get_car_status"
  }
  ```
* **Beispiel-Sprachausgabe (`speech`):**
  *„Das Auto hat aktuell 78 Prozent Ladestand und eine Reichweite von 310 Kilometern. Es ist nicht mit dem Ladekabel verbunden.“*

### 3.3 Einkaufsliste abfragen (`get_shopping_list`)
Gibt die offenen Positionen der Einkaufsliste zurück (optional gefiltert nach Markt `Rewe` oder `Globus`).
* **Request:**
  ```json
  {
    "action": "get_shopping_list",
    "market": "Rewe"
  }
  ```
* **Beispiel-Sprachausgabe (`speech`):**
  *„Auf der Einkaufsliste für Rewe stehen 3 Artikel: Milch, Butter und Brot.“*

### 3.4 Artikel zur Einkaufsliste hinzufügen (`add_shopping_item`)
Fügt einen neuen Artikel hinzu. Prüft automatisch den hinterlegten Artikelstamm (`product_master`), um bevorzugten Markt und Kategorie selbstständig zu ermitteln.
* **Request:**
  ```json
  {
    "action": "add_shopping_item",
    "name": "Hafermilch",
    "market": "Rewe",
    "quantity": 2,
    "unit": "Packung"
  }
  ```
* **Beispiel-Sprachausgabe (`speech`):**
  *„Hafermilch wurde zur Einkaufsliste für Rewe hinzugefügt.“*

### 3.5 Wetter & Kleidungsempfehlung (`get_weather_status`)
Liefert die Temperatur sowie Hinweise, ob Jacke oder Regenschirm empfohlen sind.
* **Request:**
  ```json
  {
    "action": "get_weather_status"
  }
  ```
* **Beispiel-Sprachausgabe (`speech`):**
  *„Aktuell sind es 18 Grad. T-Shirt reicht, genieß es! Alles trocken, Regenjacke kann zuhause bleiben.“*

### 3.6 Gesamt-Status / Tagesbriefing (`get_summary`)
Kombiniert PV, Auto, Wetter und offene Einkäufe zu einem kompakten Überblick.
* **Request:**
  ```json
  {
    "action": "get_summary"
  }
  ```

### 3.7 Freitext / Voice Command (`voice_command`)
Ermöglicht es Home Assistant, einen gesprochenen Transkriptsatz direkt an Kai zu übergeben. Das Backend erkennt den Intent per RegEx bzw. Gemini AI.
* **Request:**
  ```json
  {
    "action": "voice_command",
    "text": "Setze 2 Liter Milch auf die Einkaufsliste"
  }
  ```

---

## 4. Konfiguration in Home Assistant

### 4.1 REST-Kommandos in `configuration.yaml`
Füge folgenden Abschnitt in deine `configuration.yaml` in Home Assistant ein (oder in `rest_commands.yaml`):

```yaml
rest_command:
  kai_assistant:
    url: "https://deine-domain.de/assistant/"
    method: POST
    headers:
      Authorization: "Bearer !secret kai_assistant_api_key"
      Content-Type: "application/json; charset=utf-8"
    payload: >-
      {{ payload | to_json }}
```

Trage das Secret in deine `secrets.yaml` ein:
```yaml
kai_assistant_api_key: "DEIN_IN_ENV_GESETZTER_ASSISTANT_API_KEY"
```

### 4.2 Home Assistant Skripte (`scripts.yaml`)

Diese Skripte führen den Aufruf an Kai durch und lassen die Antwort direkt über einen Google Nest / Home Lautsprecher via Text-to-Speech (TTS) vorlesen.

```yaml
# Skript 1: Status des Autos abfragen
kai_ask_car_status:
  alias: "Kai: Auto Status abfragen"
  sequence:
    - action: rest_command.kai_assistant
      data:
        payload:
          action: "get_car_status"
      response_variable: kai_res
    - action: tts.speak
      target:
        entity_id: tts.google_de_de
      data:
        media_player_entity_id: media_player.google_nest_wohnzimmer
        message: "{{ kai_res['content']['speech'] if kai_res and 'content' in kai_res and 'speech' in kai_res['content'] else 'Konnte keine Fahrzeugdaten empfangen.' }}"

# Skript 2: Status der Photovoltaikanlage abfragen
kai_ask_pv_status:
  alias: "Kai: Photovoltaik Status abfragen"
  sequence:
    - action: rest_command.kai_assistant
      data:
        payload:
          action: "get_pv_status"
      response_variable: kai_res
    - action: tts.speak
      target:
        entity_id: tts.google_de_de
      data:
        media_player_entity_id: media_player.google_nest_wohnzimmer
        message: "{{ kai_res['content']['speech'] if kai_res and 'content' in kai_res and 'speech' in kai_res['content'] else 'Konnte keine Photovoltaik-Daten abrufen.' }}"

# Skript 3: Einkaufsliste abfragen
kai_ask_shopping_list:
  alias: "Kai: Einkaufsliste abfragen"
  sequence:
    - action: rest_command.kai_assistant
      data:
        payload:
          action: "get_shopping_list"
      response_variable: kai_res
    - action: tts.speak
      target:
        entity_id: tts.google_de_de
      data:
        media_player_entity_id: media_player.google_nest_wohnzimmer
        message: "{{ kai_res['content']['speech'] }}"

# Skript 4: Artikel auf die Einkaufsliste setzen (z. B. via Sprach-Variable)
kai_add_shopping_item:
  alias: "Kai: Artikel auf Einkaufsliste setzen"
  fields:
    item_name:
      description: "Name des Artikels"
      example: "Milch"
  sequence:
    - action: rest_command.kai_assistant
      data:
        payload:
          action: "add_shopping_item"
          name: "{{ item_name }}"
      response_variable: kai_res
    - action: tts.speak
      target:
        entity_id: tts.google_de_de
      data:
        media_player_entity_id: media_player.google_nest_wohnzimmer
        message: "{{ kai_res['content']['speech'] }}"
```

---

## 5. Google Home / Google Assistant Anbindung

### 5.1 Freigabe der Skripte an Google Assistant
1. In Home Assistant unter **Einstellungen $\rightarrow$ Sprachassistenten $\rightarrow$ Google Assistant**:
2. Stelle sicher, dass die neu erstellten Skripte (`script.kai_ask_car_status`, `script.kai_ask_pv_status`, `script.kai_ask_shopping_list`) für Google Assistant freigegeben sind.
3. Sage zu einem Google Lautsprecher: *„Hey Google, synchronisiere meine Geräte.“*

### 5.2 Erstellung von Google Home Routinen
In der **Google Home App** auf deinem Smartphone:
1. Öffne **Automatisierungen / Routinen** $\rightarrow$ **Neu hinzufügen**.
2. **Auslöser (Sprachbefehl):**
   * *„Wie voll ist das Auto?“* oder *„Wie ist der Ladestand vom Buzz?“*
3. **Aktion:**
   * Wähle **Hausgeräte anpassen** $\rightarrow$ **Abläufe/Skripte** $\rightarrow$ `Kai: Auto Status abfragen` aktivieren.
4. Speichern.

Wiederhole diesen Schritt für weitere Befehle:
* *„Wie viel Strom erzeugt das Dach?“* $\rightarrow$ `Kai: Photovoltaik Status abfragen`
* *„Was steht auf der Einkaufsliste?“* $\rightarrow$ `Kai: Einkaufsliste abfragen`

---

## 6. Schneller Test via cURL

Du kannst die Schnittstelle sofort von deinem Rechner oder Raspberry Pi aus testen:

```bash
# 1. Health-Check
curl -X GET https://deine-domain.de/assistant/ \
  -H "Authorization: Bearer DEIN_ASSISTANT_API_KEY"

# 2. Auto-Status abfragen
curl -X POST https://deine-domain.de/assistant/ \
  -H "Authorization: Bearer DEIN_ASSISTANT_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"action": "get_car_status"}'

# 3. Artikel auf Einkaufsliste setzen
curl -X POST https://deine-domain.de/assistant/ \
  -H "Authorization: Bearer DEIN_ASSISTANT_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"action": "add_shopping_item", "name": "Hafermilch", "quantity": 2}'
```

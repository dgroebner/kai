# Implementierungskonzept: Anbindung des Kai Toolsets via Home Assistant an Google Assistant

## 1. Zielsetzung und Architektur

Das Ziel dieses Konzepts ist es, eine sichere und stabile Schnittstelle zu schaffen, um Sprachbefehle vom Google
Assistant über Home Assistant (als lokales Gateway) an das bei Strato gehostete **Kai Toolset** weiterzuleiten.

Die Architektur trennt klar zwischen der Cloud-Kommunikation und der geschützten Backend-Infrastruktur:

* **Frontend / Sprachsteuerung:** Google Assistant (Smart Speaker, Smartphone, Google Home App).
* **Gateway & Übersetzung:** Home Assistant (auf einem Raspberry Pi im lokalen Netz), angebunden an Google über einen
  Cloud-Dienst (z. B. Nabu Casa).
* **Zielsystem (Backend):** Das Kai Toolset (gehostet bei Strato) mit einer REST-konformen API.

---

## 2. Sicherheitskonzept

Da das Kai Toolset standardmäßig durch ein strenges Google-Auth-Whitelist-Verfahren für menschliche Benutzer geschützt
ist, muss für den maschinellen Zugriff (Machine-to-Machine) ein separater Sicherheitsmechanismus etabliert werden.

* **Token-basierte Authentifizierung:** Die Kommunikation zwischen Home Assistant und dem Strato-Backend erfolgt über
  einen festen, kryptografisch sicheren API-Key (Bearer Token).
* **Header-Validierung:** Jeder von Home Assistant ausgehende HTTP-Request muss den API-Key im Authorization-Header
  mitführen. Das Strato-Backend validiert diesen Token vor jeder Befehlsverarbeitung.
* **Isolierter Endpunkt:** Für die Webhook-Anfragen wird eine dedizierte API-Route im Backend eingerichtet, die vom
  normalen Web-Frontend getrennt ist.
* **Keine direkte Cloud-Exposition des Kai-Backends:** Das Backend muss keine öffentlichen Geräte-Schnittstellen für
  Google öffnen; es vertraut ausschließlich den authentifizierten Requests, die vom Gateway (Home Assistant) kommen.

---

## 3. Datenfluss und Ablaufsteuerung

1. **Sprachbefehl:** Der Benutzer gibt einen Befehl an den Google Assistant (z. B. eine Abfrage zum PV-Speicher oder
   einen spezifischen Befehl für das Kai Toolset).
2. **Cloud-Routing:** Google Assistant erkennt anhand der verknüpften Home-Integration, dass der Befehl an Home
   Assistant gerichtet ist, und leitet die standardisierte Anfrage an das lokale Gateway weiter.
3. **Gateway-Verarbeitung (Home Assistant):**
    * Home Assistant empfängt den Intent oder die Entitätsabfrage.
    * Eine definierte Automatisierung oder ein REST-Befehl wird ausgelöst.
4. **Schnittstellen-Aufruf:** Home Assistant sendet einen gesicherten HTTP-POST-Request inklusive des API-Tokens an die
   Strato-URL des Kai Toolsets.
5. **Backend-Antwort:** Das Kai Toolset verarbeitet den Payload, führt die gewünschte Logik aus und liefert eine
   strukturierte JSON-Antwort an Home Assistant zurück, welche die Sprachausgabe oder den Status aktualisiert.

---

## 4. Phasen der Umsetzung

### Phase 1: Vorbereitung im Kai Toolset (Strato)

* Entwurf und Implementierung eines neuen, geschützten API-Endpunkts im Backend.
* Implementierung der Token-Validierungs-Logik zur Absicherung des Endpunkts.
* Definition der erwarteten Datenstrukturen (Payloads) für eingehende Befehle.

### Phase 2: Einrichtung des Gateways (Home Assistant)

* Kopplung von Home Assistant mit dem Google Assistant Ökosystem.
* Konfiguration von virtuellen Helfern oder Entitäten, die als Brücke für die Sprachbefehle dienen.
* Erstellung der ausgehenden Schnittstellen-Kommandos im Home Assistant unter Verwendung des hinterlegten API-Keys.

### Phase 3: Integration und Test

* Konfiguration von Sprach-Kurzbefehlen oder Routinen in der Google Home App.
* Durchführung von End-to-End-Tests (Sprachbefehl $\rightarrow$ Google $\rightarrow$ Home Assistant $\rightarrow$
  Strato-Backend $\rightarrow$ Rückmeldung).
* Überprüfung der Fehlerbehandlung bei Verbindungsausfällen oder ungültigen Tokens.
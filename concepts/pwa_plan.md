# Konzept: Transformation zur Progressive Web App (PWA)

Dieses Dokument beschreibt das Konzept und die erforderlichen Anpassungen, um das private Web-Tool-Set **kai** in eine Progressive Web App (PWA) zu verwandeln. Dadurch kann die Anwendung sowohl auf dem Desktop (Chrome/Edge/Safari) als auch auf Mobilgeräten (Android/iOS) als eigenständige App installiert und gestartet werden.

---

## 1. Machbarkeitsanalyse für ein serverseitiges PHP-Projekt

Da **kai** eine klassische Multi-Page-Application (MPA) auf PHP-Basis ist (und keine Single-Page-Application/SPA mit API), ergeben sich spezifische Anforderungen:

* **Installation:** Problemlos möglich. Ein Web App Manifest und ein registrierter Service Worker reichen aus, damit Browser den "Installieren"-Button anzeigen.
* **Offline-Fähigkeit:** Eingeschränkt. Da die Seiten serverseitig gerendert werden (z. B. DB-Abfragen in `index.php`), können geöffnete Ansichten ohne Internetverbindung nicht live geladen werden.
  * *Lösung:* Der Service Worker cached alle statischen Assets (CSS, JS, Icons) und zeigt bei Verbindungsverlust eine ansprechende, gebrandete Offline-Fallback-Seite (`offline.html`) anstelle des Browser-Fehlerbildschirms.
* **Sicherheit / HTTPS:** PWAs erfordern zwingend HTTPS. Da in der `.htaccess` bereits eine HSTS-Direktive und ein HTTPS-Redirect aktiv sind, ist diese Voraussetzung bereits erfüllt.

---

## 2. Erforderliche Anpassungen & neue Dateien

Um die Anwendung als PWA lauffähig zu machen, müssen folgende Dateien hinzugefügt und angepasst werden:

```
kai_root/
├── public/
│   ├── manifest.json       ← [NEU] Metadaten der App (Name, Farben, Icons)
│   ├── sw.js               ← [NEU] Service Worker für Caching & Offline-Handling
│   ├── offline.html        ← [NEU] Offline-Fallback-Seite
│   ├── js/
│   │   └── pwa-register.js ← [NEU] Skript zur SW-Registrierung
│   ├── css/
│   │   └── style.css       ← [ANPASSUNG] Ergänzung von PWA-spezifischen CSS-Styles
│   ├── index.php           ← [ANPASSUNG] Manifest & Registrierung einbinden
│   ├── login.php           ← [ANPASSUNG] Manifest & Registrierung einbinden
│   ├── bank/
│   │   ├── index.php       ← [ANPASSUNG] Manifest & Registrierung einbinden
│   │   ├── detail.php      ← [ANPASSUNG] Manifest & Registrierung einbinden
│   │   └── api.php         ← [ANPASSUNG] Muss vom Service Worker ignoriert / Network-Only gehandhabt werden
│   ├── car/index.php       ← [ANPASSUNG] Manifest & Registrierung einbinden
│   ├── kassenbon/
│   │   ├── index.php       ← [ANPASSUNG] Manifest & Registrierung einbinden
│   │   ├── auswertung.php  ← [ANPASSUNG] Manifest & Registrierung einbinden
│   │   ├── detail.php      ← [ANPASSUNG] Manifest & Registrierung einbinden
│   │   └── api.php         ← [ANPASSUNG] Muss vom Service Worker ignoriert / Network-Only gehandhabt werden
│   └── pvcharge/index.php  ← [ANPASSUNG] Manifest & Registrierung einbinden
```

---

## 3. Detail-Entwurf der Komponenten

### 3.1 Das Web App Manifest (`public/manifest.json`)
Definiert, wie die App auf dem Betriebssystem dargestellt wird.

```json
{
  "name": "Kai's Tool-Set",
  "short_name": "Kai",
  "description": "Privates Dashboard für Kassenbons, PV-Prognose und ID.Buzz Telemetrie",
  "start_url": "/index.php",
  "display": "standalone",
  "background_color": "#0f172a",
  "theme_color": "#0f172a",
  "orientation": "portrait-primary",
  "icons": [
    {
      "src": "/img/icon-192.png",
      "sizes": "192x192",
      "type": "image/png",
      "purpose": "any maskable"
    },
    {
      "src": "/img/icon-512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ]
}
```

### 3.2 Service Worker (`public/sw.js`)
Der Service Worker steuert das Caching. Für diese PHP-App eignet sich eine **Network-First-Strategie** für Seiten und eine **Cache-First-Strategie** für statische Ressourcen.

* **Caching-Phasen:**
  1. **Install:** Cache die CSS-Dateien, JS-Dateien, Icons und die `offline.html`.
  2. **Fetch:** 
     * Bei Anfragen nach HTML-Seiten (PHP): Versuche das Netzwerk. Schlägt dies fehl (Offline), liefere die `offline.html` aus.
     * Bei Anfragen nach CSS/JS/Bildern: Liefere direkt aus dem Cache, um Ladezeiten zu minimieren.

### 3.3 Registrierungs-Skript (`public/js/pwa-register.js`)
Dieses Skript wird in den HTML-Headern eingebunden und installiert den Service Worker.

```javascript
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.log('PWA: Service Worker registriert', reg.scope))
            .catch(err => console.error('PWA: Registrierung fehlgeschlagen', err));
    });
}
```

### 3.4 Integration in die PHP-Ansichten
In jedem HTML-Header (oder einem gemeinsamen Header-Include, falls vorhanden) müssen folgende Zeilen im `<head>` ergänzt werden:

```html
<!-- PWA Manifest & Theme Color -->
<link rel="manifest" href="<?= APP_URL ?>/manifest.json">
<meta name="theme-color" content="#0f172a">

<!-- iOS PWA Support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Kai">
<link rel="apple-touch-icon" href="<?= APP_URL ?>/img/icon-192.png">

<!-- PWA Registration Script -->
<script src="<?= APP_URL ?>/js/pwa-register.js" defer></script>
```

---

## 4. Zu beachtende Besonderheiten & UX-Herausforderungen

1. **Google OAuth Redirects:**
   * Wenn die App als Standalone-PWA installiert ist und die Session abläuft, leitet die App auf `login.php` und dann zu Google OAuth weiter. 
   * *Problem:* Manche Betriebssysteme öffnen externe OAuth-Logins in einem separaten In-App-Browser-Tab und kehren danach nicht sauber zur installierten PWA zurück.
   * *Lösung:* Die `start_url` sollte auf eine Seite zeigen, die prüft, ob eine Session aktiv ist. Falls nicht, sollte eine visuelle Aufforderung erfolgen, den Login im vollen Browser durchzuführen, oder der Redirect muss so gestaltet sein, dass er im gleichen PWA-Fenster verbleibt.

2. **iOS Status Bar Overlap:**
   * Bei `black-translucent` rutscht der App-Inhalt unter die Statusleiste von iPhones (Notch/Dynamic Island).
   * *Lösung:* Padding im CSS über `safe-area-inset-top` hinzufügen:
     ```css
     body {
         padding-top: env(safe-area-inset-top);
     }
     ```

3. **Aktualisierung von CSS/JS:**
   * Da der Service Worker CSS- und JS-Dateien cached, würden Updates bei einem Push auf dem Server nicht sofort auf den Handys der Nutzer aktiv werden.
   * In der bootstrap.php wird dafür bereits eine APP_VERSION geführt
   * *Lösung:* Cache-Busting über Versions-Query-Parameter in den CSS/JS Links (z.B. `style.css?v=<?= APP_VERSION ?>`) oder eine cache-updating Logik im Service Worker (z.B. Ändern des Cache-Namens `const CACHE_NAME = 'kai-<?= APP_VERSION ?>'`).
   
4. **POST-Requests dürfen niemals im Cache landen*:*
   * z. B. kassenbon/api.php, pvcharge/save_real_yield oder car/update_range.php.
   * Ein POST-Request auf eine PHP-API würde fehlschlagen, wenn der Service Worker versucht, ihn wie ein statisches Asset zu behandeln.
   * Der Service Worker muss so konfiguriert werden, dass er alle Anfragen mit der Methode POST direkt 1:1 an das Netzwerk durchreicht.

---

## 5. Empfohlene Implementierungsschritte

1. **Grafiken erstellen:** Icons in den Auflösungen `192x192` und `512x512` pixel als PNG generieren und unter `public/img/` ablegen.
2. **Dateien anlegen:** `manifest.json`, `sw.js`, `offline.html` und `pwa-register.js` mit den oben skizzierten Inhalten erstellen.
3. **Header anpassen:** Die Manifest- und Skript-Verweise in alle PHP-Seiten einbauen.
4. **CSS anpassen:** `safe-area-inset` Unterstützung für Mobilgeräte hinzufügen.
5. **Testen:** Über die Chrome/Edge DevTools (Application -> Progressive Web App) die Manifest-Validität und Offline-Funktionalität prüfen.

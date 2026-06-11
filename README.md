# kai

Persönliche Heimanwendung zur automatischen Verarbeitung digitaler Kassenbons (eBons) per E-Mail.

## Funktionsweise

1. Ein Cronjob ruft regelmäßig `public/kassenbon/cron.php` auf.
2. Der `ScannerTask` verbindet sich per IMAP mit dem konfigurierten Postfach und liest neue Mails.
3. PDF- und Bild-Anhänge werden an die Google Gemini KI zur Analyse übergeben.
4. Die extrahierten Kassenbondaten (Händler, Datum, Artikel, Kategorien) werden in der Datenbank gespeichert.
5. Verarbeitete Mails werden ins IMAP-Archiv verschoben.
6. Das Dashboard unter `public/kassenbon/index.php` zeigt alle gespeicherten Bons an.

---

## Konfiguration (.env)

Im Wurzelverzeichnis des Projekts muss eine `.env`-Datei angelegt werden. Eine Vorlage:

```dotenv
# -------------------------------------------------------
# Anwendung
# -------------------------------------------------------

# Basis-URL der Anwendung (ohne abschließenden Slash)
APP_URL=https://deine-domain.de

# -------------------------------------------------------
# Datenbank (MySQL/MariaDB)
# -------------------------------------------------------

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=kai
DB_USER=kai_user
DB_PASS=geheimes_passwort

# -------------------------------------------------------
# Google OAuth (Login)
# -------------------------------------------------------

# OAuth 2.0 Client-ID und Secret aus der Google Cloud Console
# https://console.cloud.google.com/apis/credentials
GOOGLE_CLIENT_ID=xxxxxxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxxxxxx
GOOGLE_REDIRECT_URI=https://deine-domain.de/login.php

# Autorisierte Nutzer (kommagetrennte E-Mail-Adressen)
# Nur diese Adressen dürfen sich einloggen
ALLOWED_USERS=deine@email.de,weitere@email.de

# -------------------------------------------------------
# Google Gemini KI
# -------------------------------------------------------

# API-Key aus Google AI Studio (https://aistudio.google.com/app/apikey)
GEMINI_API_KEY=AIzaSy-xxxxxxxxxxxx

# Optionales Modell (Standard: gemini-3.1-flash-lite)
# GEMINI_MODEL=gemini-3.1-flash-lite

# -------------------------------------------------------
# IMAP (E-Mail-Postfach für Kassenbons)
# -------------------------------------------------------

IMAP_HOST=imap.beispiel.de
IMAP_PORT=993
IMAP_USER_KASSENBON=kassenbon@beispiel.de
IMAP_PASS_KASSENBON=imap_passwort

# Verschlüsselung: ssl oder tls
IMAP_ENCRYPTION=ssl

# IMAP-Ordner, der auf neue Mails überwacht wird
IMAP_INBOX=INBOX

# Absender-Adressen, von denen Kassenbons akzeptiert werden (kommagetrennt)
IMAP_ALLOWED_SENDERS=bon@haendler1.de,bon@haendler2.de

# -------------------------------------------------------
# Cron-Job Absicherung
# -------------------------------------------------------

# Geheimer Token zur Absicherung des Cron-Endpunkts
# Aufruf: https://deine-domain.de/kassenbon/cron.php?token=DEIN_TOKEN
CRON_TOKEN=sicherer_zufaelliger_token
```

---

## Variablen-Referenz

| Variable | Pflicht | Beschreibung |
|---|---|---|
| `APP_URL` | ✅ | Basis-URL der Anwendung, z. B. `https://deine-domain.de` |
| `DB_HOST` | ✅ | Hostname des Datenbankservers |
| `DB_PORT` | ✅ | Port des Datenbankservers (Standard: `3306`) |
| `DB_NAME` | ✅ | Name der Datenbank |
| `DB_USER` | ✅ | Datenbankbenutzer |
| `DB_PASS` | ✅ | Datenbankpasswort |
| `GOOGLE_CLIENT_ID` | ✅ | OAuth 2.0 Client-ID (Google Cloud Console) |
| `GOOGLE_CLIENT_SECRET` | ✅ | OAuth 2.0 Client-Secret (Google Cloud Console) |
| `GOOGLE_REDIRECT_URI` | ✅ | Autorisierte Weiterleitungs-URI (muss in Google Cloud Console hinterlegt sein) |
| `ALLOWED_USERS` | ✅ | Kommagetrennte Liste autorisierter Login-E-Mail-Adressen |
| `GEMINI_API_KEY` | ✅ | API-Key für Google Gemini (Google AI Studio) |
| `GEMINI_MODEL` | ❌ | Gemini-Modellname (Standard: `gemini-3.1-flash-lite`) |
| `IMAP_HOST` | ✅ | IMAP-Serverhostname |
| `IMAP_PORT` | ✅ | IMAP-Port (Standard: `993` für SSL) |
| `IMAP_USER_KASSENBON` | ✅ | IMAP-Benutzername / E-Mail-Adresse für Kassenbons |
| `IMAP_PASS_KASSENBON` | ✅ | IMAP-Passwort für Kassenbons |
| `IMAP_ENCRYPTION` | ✅ | Verschlüsselung: `ssl` oder `tls` |
| `IMAP_INBOX` | ✅ | Zu überwachender IMAP-Ordner (Standard: `INBOX`) |
| `IMAP_ALLOWED_SENDERS` | ✅ | Kommagetrennte Liste erlaubter Absender-Adressen |
| `CRON_TOKEN` | ✅ | Geheimer Token zur Absicherung des Cron-Endpunkts |

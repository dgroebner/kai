# Konzept: Multi-Channel Dokumenten-Ingestion und Versand an die d.velop Postbox

Dieses Konzept beschreibt eine modulare Service-Infrastruktur für ein PHP-basiertes Projekt, um Dokumente aus unterschiedlichen Quellen (IMAP-Postfächer, externe APIs wie Comdirect oder interne Service-Aufrufe) entgegenzunehmen, temporär zu verwalten und via E-Mail-Weiterleitung inklusive Ordner-Tags an die d.velop Postbox zu übertragen[cite: 2].

---

## 1. Architektur-Komponenten

*   **Ingestion Layer (Eingangs-Schicht):**
    *   **IMAP-Integration:** Ruft E-Mails und deren Anhänge über den vorhandenen `ImapClient` ab[cite: 1, 2].
    *   **Externe APIs:** Holt automatisiert Dokumente ab (z. B. Kontoauszüge und PDFs über die Comdirect-API).
    *   **Interne Service-Aufrufe:** Ermöglicht anderen Modulen des Tools (z. B. Rechnungsgeneratoren) den direkten programmatischen Aufruf.
*   **Temporärer Speicher (Secure Temp Storage):**
    *   Eingehende Dateien werden ausschließlich kurzzeitig in einem geschützten Verzeichnis (z. B. `/storage/postbox_sync/`) vorgehalten.
*   **Dispatcher & Mail-Versand:**
    *   Nutzt den `MailDispatcher`, um die Dateien als E-Mail-Anhang an die persönliche Inbound-Adresse der d.velop Postbox zu senden[cite: 1, 2].
    *   Setzt den Ordner-Tag im E-Mail-Betreff, um den Zielordner in der Postbox zu steuern[cite: 2].

---

## 2. Workflow-Ablauf

1.  **Dokumenten-Eingang:** 
    *   Ein Dokument läuft über eine der drei Quellen (IMAP, API oder interner Service) ein.
2.  **Normalisierung & Temporäre Speicherung:**
    *   Der zentrale Ingest-Service speichert die Datei temporär im `/storage/postbox_sync`-Verzeichnis und reichert sie mit Metadaten (wie dem gewünschten Ziel-Tag und Dateinamen) an.
	*   Der Service sieht Dokumentenklassen vor, denen ein Postbox Ordner-Tag, sowie ein Dokumenten Naming-Pattern zugeordnet werden kann.
	*   Anhand des Nameing-Patterns erfolgt eine Umbenennung der Datei vor Versand
	*   Der neue Dateiname wird in der Datenbank nach Versand gespeichert um einen doppelten Versand zu vermeiden. Eine entsprechende Prüfung auf bereits versandte Dokumente muss erfolgen.
3.  **Versand via IMAP/SMTP an die Postbox:**
    *   Der `MailDispatcher` generiert eine Nachricht an die persönliche d.velop Postbox-Adresse[cite: 1, 2].
    *   Der Zielordner wird gesteuert, indem im Betreff ein entsprechender Ordner-Tag (beginnend mit `#`, ohne Leerzeichen) platziert wird (z. B. `#Rechnungen`)[cite: 2].
    *   Das Dokument wird als Anhang angehängt (Maximale E-Mail-Größe inkl. Anhänge: 20 MB)[cite: 2].  
4.  **Automatisches Cleanup (Löschung):**
    *   Unmittelbar nach dem erfolgreichen (oder auch fehlerhaften) E-Mail-Versand wird die temporäre Datei von der Festplatte gelöscht, sodass keine dauerhafte Vorhaltung im Tool stattfindet.
	*   Nach Versand wird eine ActivityLog-Meldung über den Versand des Dokuments mit dem Dateinamen erstellt

---

## 3. Technische Richtlinien & Einschränkungen

*   **Ordner-Tags:** Pro E-Mail-Betreff wird nur der erste Tag ausgewertet[cite: 2]. Der Tag muss mit einem `#` beginnen und darf keine Leerzeichen enthalten[cite: 2].
*   **Dateiformate:** Es werden standardmäßig nur Anhänge verarbeitet; Inline-Bilder oder E-Mail-Inhalte ohne Anhang können nicht importiert werden[cite: 2].
*   **Sicherheit:** Die persönliche E-Mail-Adresse für die Postbox-Weiterleitung muss streng geheim gehalten werden, da jeder Absender andernfalls ungefragt Dokumente in das Postfach einliefern kann[cite: 2].
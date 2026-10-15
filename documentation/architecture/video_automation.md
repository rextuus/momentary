# Architektur: Automatisierung der Video-Verarbeitung

## 1. Zielsetzung
Die manuelle Erstellung von Video-Entities soll die Ausnahme sein. Der Regelfall ist, dass Videos, die in das System gelangen (Upload, FTP, Bulk-Import), automatisch verarbeitet, benannt und dem System hinzugefügt werden.

## 2. Komponenten
### Ingestion Layer
- **Upload-Flag**: Ein Flag beim manuellen Upload (`auto_process`, Default: `true`).
- **FTP/Bulk-Import-Scanner**: Ein Command/Job, der das Verzeichnis `uploads/import` überwacht oder manuell angestoßen werden kann.

### Processing Pipeline (Erweiterung)
- **VideoCreationService (erweitert)**: Handle automatische Entitätserstellung.
- **NamingService**: Ein spezialisierter Service zur Generierung sprechender Videonamen. 
  - **Grundlagen**: Nutzt Metadaten (EXIF, Dateiname, Zeit) zur Generierung.
  - **KI-Integration**: Nutzt den `GeminiService` für eine kontextbezogene Benennung.
- **Queueing Manager**: Überwacht die Anzahl aktiver Video-Verarbeitungen (maximal 2 gleichzeitig) und steuert den Import aus der `QUEUED`-Warteschlange.

## 3. Workflow
1. Datei landet im System (Upload, `uploads/import`).
2. Trigger (Upload-Formular mit `auto_process=true`, FTP-Scanner, oder Bulk-UI) startet Prozess.
3. `VideoCreationService` erstellt `Video` Entity (Status: `QUEUED`).
4. `ImportQueueManager` startet Prozess (Status: `PROCESSING`), sobald ein Slot (max. 2 parallel) frei ist.
5. Prozess-Pipeline wird ausgeführt.
6. `Video` wird auf Jellyfin hochgeladen.
7. Post-Processing-Schritt wird ausgeführt (Status: `POST_PROCESSING`).
8. Status auf `COMPLETED` gesetzt.

### 3.1 Status-Management (State Machine)
- **NEW**: Datei wurde erkannt, noch keine Datenbank-Entität vorhanden.
- **QUEUED**: Entität wurde erstellt, wartet in der Queue auf Kapazität.
- **PROCESSING**: Die Pipeline (Naming, Analyse, Verschiebung) ist aktiv.
- **POST_PROCESSING**: Abschließende Arbeiten nach dem Jellyfin-Upload.
- **COMPLETED**: Erfolgreich abgeschlossen, Video ist verfügbar.
- **FAILED**: Fehler aufgetreten. Erfordert manuelle Überprüfung oder automatisierten Retry.

### 3.2 Fehlerbehandlung & Monitoring
- **Retry-Strategie**: Bei transienten Fehlern (z.B. API-Timeout von KI-Service) wird ein automatischer Retry mit Exponential Backoff durchgeführt (maximal 3 Versuche).
- **Logging**: Jeder Pipeline-Schritt erzeugt Log-Einträge, die über eine `VideoProcessingLog`-Entität für jedes Video einsehbar sind.
- **Alerting**: Bei Erreichen des Status `FAILED` wird der Administrator per Notification informiert.

## 4. Detaillierte Aufgaben
### Ingestion
- [ ] Upload-Formular anpassen: `auto_process` Flag berücksichtigen und bei `true` direkt `Video`-Entität mit Status `QUEUED` erstellen und Queueing anstoßen.
- [ ] `BulkImportCommand` erstellen: Scannt `uploads/import`, erstellt für jede Datei eine `Video`-Entität.
- [ ] Admin-UI für Bulk-Import (`/admin/video/import`) erstellen: Zeigt Dateien in `uploads/import`, erlaubt Selektion und "Start Bulk Import".

### Processing
- [ ] `VideoNamingService` implementieren:
    - Extrahiert Metadaten (EXIF, Dateiname, Zeit).
    - Nutzt `GeminiService::suggestVideoName` (neue Methode mit spezifischem Prompt) zur KI-gestützten Benennung.
- [ ] `VideoCreationService` um den `VideoNamingService` erweitern (als finaler Step).
- [ ] Queueing-Mechanismus für Bulk-Import (`QUEUED` Status, `ImportQueueManager`) implementieren.
- [ ] `App\Enum\VideoStatus` Enum um `POST_PROCESSING` erweitern.
- [ ] Post-Processing-Schritt implementieren (nach Jellyfin Upload).
    - [ ] Detail-Page für Videos soll den neuen Step direkt anzeigen.

# MASTER_STATUS.md

# Strategische Gesamtprojekt-Dokumentation: Momentary, Momentary Hub & Momentary Mobile

Dieses Dokument dient als zentrale Wissensbasis ("Ground Truth") für die KI-gestützte Video-Analyse-Plattform **Momentary** (Backend), deren Kiosk-Client **Momentary Hub** (Frontend) sowie die hybride Mobil-App **Momentary Mobile** (Android Shell). Es beschreibt die Systemarchitektur, das vollständige Datenmodell, den aktuellen Implementierungsstand und die nächsten strategischen Schritte.

---

## 1. Systemarchitektur & Gesamtkonzept

Das Gesamtsystem teilt sich in eine hochperformante, asynchrone Verarbeitungs-Engine (Backend), ein schlankes, zustandsloses Kiosk-Webinterface (Frontend) sowie eine native Android-Wrapper-Shell auf, die das Webinterface nativ rendert.

Das Frontend (Momentary Hub) und die Mobile-App (Momentary Mobile) senden REST-Anfragen an das Backend (Momentary) und verarbeiten JSON-LD-Daten. HTML-Interaktionen und asynchronen Updates werden nahtlos über Hotwire Turbo (Web) bzw. Hotwire Native (Android) abgewickelt. Das Backend generiert und signiert URLs, über welche die Clients Medien direkt von Imgproxy laden.

### Tech-Stack-Übersicht & Infrastruktur
| Rolle | Service / Komponente | Port | Identifikation / Zweck |
| :--- | :--- | :--- | :--- |
| **Backend** | Symfony 8.0 (API Platform v3+) | `8088` | Interne REST-API (JSON-LD / Hydra) |
| **Bildverarbeitung**| Imgproxy | `8080` | Dynamische On-the-fly-Bildquelle |
| **Frontend** | Symfony 8.0 & Hotwire Turbo Hub | `8089` | Zustandsloses Web-User-Interface |
| **Mobile** | Turbo Hybrid Shell (Android Studio) | *N/A* | Android WebView via Hotwire Native |
| **Streaming** | Jellyfin Server | *TBD* | Finaler Export & Video-Streaming-Infrastruktur |

---

## 2. Projekt-Komponenten im Detail

### 2.1 Projekt 1: Momentary (Das Backend)
* **Asynchrone Media-Pipeline:** Gesteuert über Symfony Messenger und die Workflow-Komponente. Ablauf: Download -> PySceneDetect (smarte Keyframe-Extraktion zur AWS-Kostenminimierung) -> AWS Rekognition (Gesichtserkennung & Tagging).
* **Imgproxy-Integration:** Bilder und Thumbnails werden on-the-fly über Imgproxy skaliert und kryptografisch signiert. Der Host wird flexibel über die Umgebungsvariable `IMGPROXY_PUBLIC_HOST` gesteuert, um schnelles Switchen zwischen lokalem Entwicklungsbetrieb (`localhost`) und Emulator-Betrieb (`10.0.2.2`) zu ermöglichen.

### 2.2 Projekt 2: Momentary Hub (Das Frontend)
* **Hotwire-Philosophie (HTML over the Wire):** Bewusster Verzicht auf schwere SPA-Frameworks. Die UI-Interaktionen werden über Hotwire Turbo Drive und Turbo Frames gesteuert, um ein natives App-Gefühl für die Kiosk-Tablets zu erzielen.
* **Asynchrone Suche & Filter:** Das Dashboard-Suchformular funkt über `data-turbo-frame="videos_list"`. Ergebnisse werden isoliert im Hintergrund ausgetauscht.
* **Reines CSS-Fallback:** Logik zum Aufklappen versteckter Personen-Avatare basiert auf reinem Tailwind CSS mittels einer unsichtbaren Checkbox und dem CSS-Selektor `peer-checked:block` (fehlertolerant für Kiosk-Betrieb).

### 2.3 Projekt 3: Momentary Mobile (Android-Wrapper)
* **Konzept:** Ein natives Android-Projekt, das als Hybrid-Shell fungiert. Es nutzt **Hotwire Native (Turbo Android)**, um den Momentary Hub in einer performanten, nativen Umgebung zu rendern. Dies ermöglicht native Navigation (Stack-Management) bei minimalem UI-Code auf dem Client.
* **MainSessionNavHostFragment & Identifikation:**
  * **User-Agent:** Über die Session-Konfiguration wird ein Custom User-Agent gesetzt (z.B. `... MomentaryApp`), wodurch das Backend den Mobil-Client eindeutig identifizieren kann.
  * **Custom Header / Environment:** Da ein automatisches Request-Routing über Header-Injektionen aufgrund der Frontend-Proxy-Natur verworfen wurde, erfolgt das IP-Mapping für den Emulator pragmatisch und fehlerfrei über das `IMGPROXY_PUBLIC_HOST` Deployment-Environment im Backend.
* **Strada-Integration:** Die Bridge-Komponente wird via `Bridge.initialize(session.webView)` in `onSessionCreated()` initialisiert. Dies verknüpft native Android-Komponenten (z.B. System-Dialoge, Toasts oder native Picker) direkt mit Stimulus-Controllern der Symfony-Web-App.

---

## 3. Datenmodell & Entity-Übersicht (Backend Ground Truth)

### 3.1 Video
* **Properties:** `id` (PK), `title`, `sourceFile`, `localPath` (Relativer Pfad zur Verarbeitungsdatei), `thumbnailPath`, `status` (VideoStatus Enum), `duration`, `totalFrames`, `processedFrames`, `analysisFps`, `errorMessage`, `jellyfinPath`, `jellyfinItemId`, `createdAt`, diverse Workflow-Timestamps und Verarbeitungs-Distanzen.
* **Relationen:** `scenes` (OneToMany -> VideoScene), `videoFaces` (OneToMany -> VideoFace), `chapters` (OneToMany -> VideoChapter). *Transient:* `thumbnailUrl`.

### 3.2 VideoScene
* **Properties:** `id` (PK), `sceneNumber`, `startSeconds`, `endSeconds`, `title`.
* **Relationen:** `video` (ManyToOne), `videoFaces` (OneToMany -> VideoFace), `tags` (ManyToMany -> Tag).

### 3.3 VideoFace
* **Properties:** `id` (PK), `timestamp`, `faceLabel`, `faceImagePath`, `boundingBox` (Array [x1, y1, x2, y2]), `age`, `gender`, `emotion`, `matchSimilarity`, `embedding` (Vektordaten).
* **Relationen:** `video` (ManyToOne), `videoScene` (ManyToOne), `person` (ManyToOne -> Final zugeordnet), `detection` (ManyToOne -> Detektions-Pool), `matchedBy`/`matchFor` (Selbstreferenzierung bei Refinements). *Transient:* `imageUrl`.

### 3.4 Person
* **Properties:** `id` (PK), `name`, `fullName`, `age`, `gender`, `probablyGender`, `relation`, `characteristics`, `description`, `status` (PersonStatus Enum: NEW, IDENTIFIED, WASTED), `sceneCount`, `showCount`.
* **Relationen:** `videoFaces`, `detectionFaces`, `profileFace` (ManyToOne -> VideoFace), `mergedInto` (ManyToOne -> Person bei Zusammenführung). *Transient:* `profileImageUrl`.

---

## 4. Offene To-Dos & Nächste Meilensteine

### 4.1 Hybrid- & Mobile-Scope (Hohe Priorität)
1. 🟡 **Asset-Caching & Cache-Busting:** Da Turbo Android die Ansichten extrem aggressiv cached, muss die Cache-Buster-Logik im Symfony-Frontend (AssetMapper) absolut wasserdicht sein, damit Style- oder Skriptänderungen sofort greifen.
2. 🔵 **Strada Bridge Events definieren:** Spezifikation der genauen Event-Namen zwischen Android (Kotlin) und Frontend (Stimulus JS), um z.B. native Kiosk-Funktionen (Helligkeit steuern, App-Sperre) aufzurufen.

### 4.2 Pipeline & Features (Mittelfristig)
3. **Idempotenz im Messenger:** Absicherung aller Message-Handler im Backend gegen unvollständige Wiederholungen (z.B. bei Timeouts zu AWS).
4. **Media-Streaming via Jellyfin:** Anbindung der echten Video-Wiedergabe im Momentary Hub via HTML5-Videotag (Direktstream) oder Deep-Linking in die native Jellyfin-App auf dem Android-Client.

---

## 5. Historie behobener Blockaden (Archiv)

* ✅ **CORS / Browser Image Blocking:** Fehler behoben. `IMGPROXY_ALLOW_ORIGIN` greift vollumfänglich; Thumbnails und Avatare werden im Frontend stabil gerendert und mitsigniert.
* ✅ **JavaScript h1-check.js Crash:** Bereinigt. Core-Interaktionen laufen isoliert über Hotwire Turbo und CSS-Selektoren, Fehler im AssetMapper-Scope abgefangen.
* ✅ **Imgproxy Environment Configuration:** Das flexible Umschalten der Medien-Hosts für den Android-Emulator wurde erfolgreich aus dem Code in die `.env` ausgelagert.
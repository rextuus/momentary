# Entity-Übersicht: Momentary

Diese Dokumentation beschreibt das Datenmodell von Momentary, einschließlich aller Properties und Relationen.

## ER-Diagramm (Abstrakt)
- **Video** (1) <---> (n) **VideoScene**
- **Video** (1) <---> (n) **VideoFace**
- **Video** (1) <---> (n) **VideoChapter**
- **VideoScene** (1) <---> (n) **VideoFace**
- **VideoScene** (n) <---> (m) **Tag**
- **Tag** (n) <---> (1) **TagCategory**
- **VideoFace** (n) <---> (1) **Person** (Zugeordnete Person)
- **VideoFace** (n) <---> (1) **Person** (Detektions-Pool / `detection`)
- **VideoFace** (1) <---> (1) **VideoFace** (Refinement-Referenz / `matchedBy`)
- **Person** (n) <---> (1) **Person** (Zusammengeführt in / `mergedInto`)

---

## 1. Video
Das zentrale Element, das eine Videodatei und deren Analyse-Metadaten repräsentiert.

### Properties
- `id`: Integer (PK)
- `title`: String
- `youtubeUrl`: String (optional)
- `sourceFile`: String
- `localPath`: String (Relativer Pfad zur aktuell genutzten Datei, z.B. optimiertes MP4)
- `convertedVideoPath`: String (Veraltet/Optional)
- `thumbnailPath`: String (Pfad zum generierten Thumbnail)
- `status`: VideoStatus (Enum)
- `duration`: Float (in Sekunden)
- `totalFrames`, `processedFrames`: Integer (Fortschrittsanzeige)
- `analysisFps`, `refinedAnalysisFps`: Float
- `minSceneLengthForRefinement`: Float
- `mergeEmptyScenesWithLastPersonScene`: Boolean
- `errorMessage`: Text (optional)
- `jellyfinPath`, `jellyfinItemId`: String (Export-Metadaten)
- `createdAt`: DateTimeImmutable
- `downloadedAt`, `convertedAt`, `scenesDetectedAt`, `framesExtractedAt`, `facesAnalyzedAt`, `refinedAt`, `completedAt`: DateTimeImmutable (Workflow-Timestamps)
- `refiningExtractionFinishedAt`, `refiningAnalysisFinishedAt`, `mergingScenesAt`: DateTimeImmutable (Detaillierte Workflow-Schritte)
- `downloadDuration`, `conversionDuration`, `sceneDetectionDuration`, `frameExtractionDuration`, `faceAnalysisDuration`, `refinementDuration`, `refiningExtractionDuration`, `refiningAnalysisDuration`, `mergingScenesDuration`: Integer (Dauer der Schritte in Sekunden)
- `estimatedConversionDuration`, `estimatedSceneDetectionDuration`, `estimatedFrameExtractionDuration`, `estimatedFaceAnalysisDuration`: Integer (Schätzwerte)
- `currentFrameDirectory`, `currentRefinementFrameDirectory`: String (Interne Pfade während der Verarbeitung)

### Relationen
- `scenes`: OneToMany -> **VideoScene** (Inversed by `video`)
- `videoFaces`: OneToMany -> **VideoFace** (Inversed by `video`)
- `chapters`: OneToMany -> **VideoChapter** (Inversed by `video`)
- `thumbnailUrl`: Transient (Generiert via `VideoNormalizer` & Imgproxy)

---

## 2. VideoScene
Repräsentiert ein durch PySceneDetect erkanntes Zeitsegment innerhalb eines Videos.

### Properties
- `id`: Integer (PK)
- `sceneNumber`: Integer
- `startSeconds`: Float
- `endSeconds`: Float
- `title`: String (optional)

### Relationen
- `video`: ManyToOne -> **Video** (Owning side)
- `videoFaces`: OneToMany -> **VideoFace** (Inversed by `videoScene`)
- `tags`: ManyToMany -> **Tag** (Owning side, Inversed by `scenes`)

---

## 3. VideoFace
Ein spezifisches Vorkommen eines Gesichts in einem Frame des Videos.

### Properties
- `id`: Integer (PK)
- `timestamp`: Integer (Frame-Index oder Zeitstempel)
- `faceLabel`: String
- `faceImagePath`: String (Pfad zum extrahierten Gesichtsbild)
- `boundingBox`: Array (Koordinaten [x1, y1, x2, y2])
- `age`: Integer (erkanntes Alter)
- `gender`: String (erkanntes Geschlecht)
- `emotion`: String (erkannte Emotion)
- `matchSimilarity`: Float (Sicherheit der Personenzuordnung)
- `embedding`: Array (Vektordaten für AWS Rekognition)

### Relationen
- `video`: ManyToOne -> **Video**
- `videoScene`: ManyToOne -> **VideoScene**
- `person`: ManyToOne -> **Person** (Die final zugeordnete Person)
- `detection`: ManyToOne -> **Person** (Referenz zur Person für das Interface / Detektions-Pool)
- `matchedBy`: ManyToOne -> **self** (Referenz auf das Original-Face bei Refinements)
- `matchFor`: OneToMany -> **self** (Gegenstück zu `matchedBy`)
- `imageUrl`: Transient (Signierte Imgproxy-URL, generiert via `VideoFaceNormalizer`)

---

## 4. Person
Ein individuelles Profil einer Person, dem mehrere `VideoFace`-Vorkommen zugeordnet werden können.

### Properties
- `id`: Integer (PK)
- `name`: String
- `fullName`: String (optional)
- `age`: Integer (Durchschnitt oder manuell korrigiert)
- `gender`: String (erkanntes Geschlecht)
- `probablyGender`: String (Berechnete Tendenz basierend auf zugeordneten Faces)
- `relation`: String (Beziehung zum Archiv-Inhaber)
- `characteristics`: Text
- `description`: Text
- `isIdentified`: Boolean
- `isWasted`: Boolean (Für Profile, die ignoriert werden sollen)
- `status`: PersonStatus (Enum: `NEW`, `IDENTIFIED`, `WASTED`)
- `sceneCount`: Integer (Anzahl der Szenen, in denen die Person vorkommt)
- `showCount`: Integer (Häufigkeit der Anzeige/Interaktion)

### Relationen
- `videoFaces`: OneToMany -> **VideoFace** (Alle zugeordneten Vorkommen)
- `detectionFaces`: OneToMany -> **VideoFace** (Referenz für den Detektions-Pool)
- `profileFace`: ManyToOne -> **VideoFace** (Das Bild, das als Profilbild dient)
- `mergedInto`: ManyToOne -> **Person** (Referenz bei Profil-Zusammenführungen)
- `profileImageUrl`: Transient (Signierte Imgproxy-URL des Profilbildes)

---

## 5. Tag & TagCategory
Ermöglichen die semantische Kategorisierung von Szenen.

### Tag Properties
- `id`: Integer (PK)
- `name`: String

### Tag Relationen
- `category`: ManyToOne -> **TagCategory** (Owning side)
- `scenes`: ManyToMany -> **VideoScene** (Inversed by `tags`)

### TagCategory Properties
- `id`: Integer (PK)
- `name`: String
- `color`: String (Hex-Code für UI, optional)

### TagCategory Relationen
- `tags`: OneToMany -> **Tag** (Inversed by `category`)

---

## 6. VideoChapter
Strukturierte Kapitel für den Export (z.B. an Jellyfin).

### Properties
- `id`: Integer (PK)
- `title`: String
- `startSeconds`: Float
- `endSeconds`: Float
- `description`: Text (optional)

### Relationen
- `video`: ManyToOne -> **Video** (Owning side)

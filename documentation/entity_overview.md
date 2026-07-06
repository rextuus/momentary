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
- **VideoFace** (n) <---> (1) **Person** (Detektions-Referenz)
- **Person** (n) <---> (1) **Person** (Zusammengeführt in/Merged Into)

---

## 1. Video
Das zentrale Element, das eine Videodatei und deren Analyse-Metadaten repräsentiert.

### Properties
- `id`: Integer (PK)
- `title`: String
- `youtubeUrl`: String (optional)
- `sourceFile`: String
- `localPath`: String
- `convertedVideoPath`: String
- `status`: VideoStatus (Enum)
- `duration`: Float (in Sekunden)
- `createdAt`: DateTimeImmutable
- `downloadedAt`, `convertedAt`, `scenesDetectedAt`, `framesExtractedAt`, `facesAnalyzedAt`, `refinedAt`, `completedAt`: DateTimeImmutable (Timestamps für Workflow-Schritte)
- `totalFrames`, `processedFrames`: Integer (Fortschrittsanzeige)
- `analysisFps`, `refinedAnalysisFps`: Float
- `errorMessage`: Text (optional)
- `jellyfinPath`, `jellyfinItemId`: String (Export-Metadaten)

### Relationen
- `scenes`: OneToMany -> **VideoScene** (Inversed by `video`)
- `videoFaces`: OneToMany -> **VideoFace** (Inversed by `video`)
- `chapters`: OneToMany -> **VideoChapter** (Inversed by `video`)

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
- `tags`: ManyToMany -> **Tag** (Inversed by `scenes`)

---

## 3. VideoFace
Ein spezifisches Vorkommen eines Gesichts in einem Frame des Videos.

### Properties
- `id`: Integer (PK)
- `timestamp`: Integer (ms oder Frame-Index)
- `faceLabel`: String
- `faceImagePath`: String
- `boundingBox`: Array (Koordinaten im Bild)
- `age`: Integer (erkanntes Alter)
- `gender`: String (erkanntes Geschlecht)
- `emotion`: String (erkannte Emotion)
- `matchSimilarity`: Float (Sicherheit der Personenzuordnung)
- `embedding`: Array (Vektordaten für AWS Rekognition)

### Relationen
- `video`: ManyToOne -> **Video**
- `videoScene`: ManyToOne -> **VideoScene**
- `person`: ManyToOne -> **Person** (Die final zugeordnete Person)
- `detection`: ManyToOne -> **Person** (Referenz zur Person für das Interface)
- `matchedBy`: ManyToOne -> **self** (Referenz auf das Original-Face bei Refinements)

---

## 4. Person
Ein individuelles Profil einer Person, dem mehrere `VideoFace`-Vorkommen zugeordnet werden können.

### Properties
- `id`: Integer (PK)
- `name`: String
- `fullName`: String (optional)
- `age`: Integer (Manuell korrigiert oder Durchschnitt)
- `gender`: String
- `relation`: String (Beziehung zum Archiv-Inhaber)
- `characteristics`: Text
- `description`: Text
- `isIdentified`: Boolean
- `isWasted`: Boolean (Für Profile, die ignoriert werden sollen)
- `status`: PersonStatus (Enum)

### Relationen
- `videoFaces`: OneToMany -> **VideoFace** (Alle zugeordneten Vorkommen)
- `profileFace`: ManyToOne -> **VideoFace** (Das Bild, das als Profilbild dient)
- `mergedInto`: ManyToOne -> **Person** (Referenz, wenn Profile zusammengeführt wurden)

---

## 5. Tag & TagCategory
Ermöglichen die semantische Kategorisierung von Szenen.

### Tag Properties
- `id`: Integer (PK)
- `name`: String

### Tag Relationen
- `category`: ManyToOne -> **TagCategory**
- `scenes`: ManyToMany -> **VideoScene**

### TagCategory Properties
- `id`: Integer (PK)
- `name`: String
- `color`: String (Hex-Code für UI)

---

## 6. VideoChapter
Strukturierte Kapitel für den Export (z.B. an Jellyfin).

### Properties
- `id`: Integer (PK)
- `title`: String
- `startSeconds`: Float
- `endSeconds`: Float
- `description`: Text

### Relationen
- `video`: ManyToOne -> **Video**

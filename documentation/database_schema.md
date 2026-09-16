# Datenbank-Schema & Entity-Übersicht

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
- `sourceFile`: String
- `localPath`: String (Relativer Pfad zur aktuell genutzten Datei, z.B. optimiertes MP4)
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
- `currentFrameDirectory`, `currentRefinementFrameDirectory`: String (Interne Pfade während der Verarbeitung)
- `isPublic`: Boolean (für Zugriffskontrolle)

### Relationen
- `scenes`: OneToMany -> **VideoScene** (Inversed by `video`)
- `videoFaces`: OneToMany -> **VideoFace** (Inversed by `video`)
- `chapters`: OneToMany -> **VideoChapter** (Inversed by `video`)
- `processingSteps`: OneToMany -> **VideoProcessingStep** (Inversed by `video`)
- `thumbnailUrl`: Transient (Generiert via `VideoNormalizer` & Imgproxy)
- `allowedGroups`: Collection von Gruppen mit Zugriff

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

### Tag
- `id`: Integer (PK)
- `name`: String
- `category`: ManyToOne -> **TagCategory** (Owning side)
- `scenes`: ManyToMany -> **VideoScene** (Inversed by `tags`)

### TagCategory
- `id`: Integer (PK)
- `name`: String
- `color`: String (Hex-Code für UI, optional)
- `tags`: OneToMany -> **Tag** (Inversed by `category`)

---

## 6. VideoSceneTag
Repräsentiert die Assoziation zwischen einer Szene und einem Tag.
- `isAiGenerated`: Boolean flag
- `confidence`: Confidence score
- `videoScene`: Reference to the scene.
- `tag`: Reference to the tag.

---

## 7. VideoChapter
Strukturierte Kapitel für den Export.
- `id`: Integer (PK)
- `title`: String
- `startSeconds`: Float
- `endSeconds`: Float
- `description`: Text (optional)
- `video`: ManyToOne -> **Video** (Owning side)

---

## 8. VideoProcessingStep
Repräsentiert einen Verarbeitungsschritt eines Videos.
- `id`: Integer (PK)
- `step`: VideoStatus (Enum)
- `createdAt`: DateTimeImmutable
- `startedAt` / `processedAt` / `finishedAt`: Timing data.
- `duration`: Integer (Dauer in Sekunden)
- `errorMessage`: Text (optional)
- `video`: ManyToOne -> **Video** (Owning side)

---

## 9. File
Generisches Dateimanagement.
- `relativePath`: Speicherort.
- `mimeType`: Format.
- `fileSize`: Größe in Bytes.
- `purpose`: Verwendungszweck.

---

## 10. User-Management
### User
- `email`: User email (identifier).
- `roles`: User roles.
- `password`: Hashed password.
- `settings`: User settings.

### UserSettings
- `user`: Reference to User.
- `blurForbiddenContent`: Boolean flag.

### UserGroup
- `name`: Name der Gruppe.
- `owner`: Reference to User.
- `members`: Collection of UserGroupMember.

### UserGroupMember
- `user`: Reference to User.
- `userGroup`: Reference to UserGroup.

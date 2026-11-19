# TODOs

## Bearbeitungs-Richtlinien
- Todos müssen präzise und sauber ausformuliert sein.
- Aufgaben sind in konkret ausführbare Todos und ggf. Sub-Todos zu gliedern, damit sie jederzeit direkt umsetzbar sind.
- Vor dem Hinzufügen neuer Punkte in die Todo-Liste ist stets zu prüfen, ob ähnliche Funktionalitäten bereits existieren (z.B. Voter, Services, Controller), um Doppelentwicklungen zu vermeiden.

## Offene Aufgaben

### Medien: Fotos & Videos zusammenführen
(Grundlage: `documentation/media.md` – Basistyp `Medium`, Fotos, Ereignisse)

#### 1. Gemeinsame Suche über Videos und Fotos ✓
Umgesetzt (siehe `documentation/media.md`, Abschnitt „Suche“). Die externe Video-API `/api/search` und der
Admin-„Search Explorer“ bleiben unverändert.
- [x] Suchdienst `MediaSearch`: eine Multi-Search-Anfrage an beide Indizes, gemeinsame Filter für Personen und Tags.
- [x] Nur sichtbare Treffer (`MediumRepository::restrictToVisible`); Facetten nur aus sichtbaren Treffern gezählt.
- [x] Video-Treffer mit Fundstellen (gesprochene Sätze, Szenen mit Person/Tag), verlinkt auf `?t=`; Tags derselben Szene in einer Zeile.
- [x] Seite `/search` mit Suchfeld, Facetten und Ergebnissen getrennt nach Videos und Fotos; Suchbegriffe hervorgehoben.
- [x] Suche in der Navigation (Lupe).
- [x] Tests (Sichtbarkeit, Facetten, Fundstellen, HTML-Escaping) und Doku.
- [x] Veraltete Einträge im Index `videos` entfernt; `bin/console app:meilisearch:index-all` baut jetzt Video- und Foto-Index neu auf.

#### 2. Zeitachse über alle Medien ✓
Umgesetzt (siehe `documentation/media.md`, Abschnitt „Zeitachse“).
- [x] Seite `/timeline` mit allen sichtbaren Videos und Fotos nach Aufnahmedatum, gruppiert nach Monat und Tag (deutsche Zeit).
- [x] Nachladen beim Scrollen (Cursor statt Seitenzahl, Link als Rückfall); Monatsleiste mit Sprungmarken.
- [x] Filter nach Person und Medientyp; Link „In der Zeitachse“ auf der Personenseite.
- [x] Ereignisse als Klammer um ihre Medien (bei mehreren Ereignissen zählt das kürzeste).
- [x] Tests und Doku.

#### 3. Automatische Ereignis-Vorschläge ✓
Umgesetzt (siehe `documentation/media.md`, Abschnitt „Ereignis-Vorschläge“).
- [x] Medien ohne Ereignis nach Zeit gruppieren (Lücke ohne Aufnahme trennt; 6 h bis 3 Tage auf der Seite wählbar, Standard 24 h; mindestens 3 Medien).
- [x] GPS-Fotos: Ortswechsel über 100 km trennt die Gruppe. (Zusammenfassen als „Reise“ mit Ortsnamen folgt mit dem Reverse-Geocoding in Punkt 4.)
- [x] Vorschläge auf `/event/` mit Zeitraum, Vorschaubildern und Personen; Titel per Gemini beim Übernehmen.
- [x] Übernehmen (Ereignis mit genau diesen Medien) oder Verwerfen (pro Benutzer gespeichert; neue Medien ergeben einen neuen Vorschlag).
- [x] Tests und Doku.

#### 4. Karte aus GPS-Daten ✓
Umgesetzt (siehe `documentation/media.md`, Abschnitt „Orte & Karte“).
- [x] Aufnahmeort für alle Medien (`Medium`); Videos: GPS aus den Metadaten (ISO 6709, z. B. iPhone).
- [x] Seite `/map` mit Leaflet + OpenStreetMap, gruppierte Marker, Vorschau im Popup, Filter nach Person und Medientyp.
- [x] Karten auf Ereignis-, Foto- und Videoseiten.
- [x] Ortsnamen per Reverse-Geocoding (Nominatim: auf ~100 m gerundet, Cache, max. 1 Anfrage/s, `GEOCODING_ENABLED`) als Ortsname und Tags „Orte“; auch in Suche und Ereignis-Vorschlägen.
- [x] Tests und Doku.
- [x] Reisen: Zuhause = Ort mit den meisten GPS-Aufnahmen; alles über 50 km entfernt bildet eine Reise, die über mehrere Orte und bis zu 3 Tage ohne Aufnahme zusammenhält (Badge „Reise“, Hinweis an Gemini).

### Automatisierung der Video-Verarbeitung ✓
(Konzept siehe: `documentation/architecture/video_automation.md`; Stand im Code geprüft am 18.10.2026)
- [x] `auto_process` Flag im Upload-Formular (`VideoType`, `VideoCreationService`).
- [x] Import von Ordnern: inzwischen Import-Ordner für Videos und Fotos (siehe Idee F).
- [x] `VideoNamingService` (AI/Metadata-basiert).
- [x] Benennung als Pipeline-Schritt (`VideoNamingStepMessageHandler`) statt in `VideoCreationService`.

### Ideen für die nächsten Schritte
Vorschläge aus der bisherigen Arbeit, grob nach Nutzen sortiert. Vor dem Umsetzen jeweils prüfen, was es schon gibt.

#### A. Gesichtserkennung mit echten Daten auswerten und nachschärfen
Seit November 2026 erkennt InsightFace (selbst gehostet, `face_recognition.md`); die Schwellen sind Startwerte.
- [ ] Mit `app:face:analyze` an echten Homevideos (VHS/Hi8/MiniDV) messen: Histogramm der besten Ähnlichkeiten,
  zugeordnet/vorgeschlagen/unbekannt; Schwellen danach festlegen.
- [ ] Aufbereitete Fassung (HitPaw/VikPea) als zusätzliche Wiedergabe-Datei anhängen, mit Synchronitätsprüfung
  (Dauer, Startzeit, Schnitte gegen das Original; Versatz melden, Drift ablehnen). Die rohen Schnitte des Originals
  stehen seit 11/2026 in `Video::sceneCuts`. Danach nur Web-Fassung, Jellyfin-Export und ggf. Vorschaubilder neu,
  keine Gesichtsanalyse.
- [x] Aufnahmezeit digitalisierter Bänder, Stufe 1: DV-Subcodes lesen (eigener Leser), Aufnahme-Segmente am Video,
  `takenAt` aus dem ersten verlässlichen Segment, Segmentgrenzen als Szenen- und Kapitelgrenzen, Werksdatum verworfen,
  wiederkehrende Tage als Hinweis, Grenze 1995 (`dv_aufnahmedatum.md` → Umsetzung).
- [ ] DV: an einer echten Kassette prüfen (REC ST, Datumssprung, PAL, AVI aus der Capture-Software); Schwellen für
  wiederkehrende Daten nachschärfen.
- [ ] DV: Segmente von Hand datieren und teilen (Videoseite, `source = manual`, Genauigkeit Tag/Monat/Jahr),
  Werksdatum-Segmente bestätigen oder „ab hier“ korrigieren; Kamera-Uhr-Korrektur verschiebt alle Segmente.
- [ ] DV Stufe 2/3: Referenzauswahl, Suche, Ereignisse, Pools und Zeitleiste je Segment (`dv_aufnahmedatum.md`, 4).
- [ ] `CameraOverview::implausible()` kennt die Werksdatum-Regel (1. Januar, erste Stunde) noch nicht, nur die
  Grenzen; `CameraClock::warnings()` schon.
- [x] Bestätigte Gesichter überstehen das Neuverarbeiten (`VerifiedFaceCarryover`); jede Zuordnung von Hand
  bestätigt (`face_recognition.md` → Neuverarbeiten).
- [ ] **Ablehnungen merken („nicht diese Person“).** Wer einen Vorschlag ablehnt oder ein Gesicht von einer Person
  entfernt, soll das nicht wiederholen müssen. Zurückgestellt, weil es Abgleich und Konsolidierung ändert, nicht
  nur die Oberfläche. Plan:
  - `VideoFace.rejectedPersons` (JSON, Personen-IDs). Setzen beim Entfernen/Umhängen von einer Person
    (`PersonDetailComponent::reassignFace`, `VideoFaceComponent::submit`, `FaceReassignComponent`, Massen-Bestätigung
    „nicht diese Person“) und beim Ablehnen eines Vorschlags (Vorschlag = `VideoFace.detection`; heute gibt es dafür
    nur „Überspringen“ in Tinder/Schnell zuordnen, das je Person zählt, nicht je Gesicht). Beim Zusammenführen auf
    die Zielperson umschreiben (`PersonMerger`, auch die Merges in den Resolver-Komponenten).
  - Neuverarbeiten: in `verifiedFaceCarryover` mitmerken (Einträge mit `rejected` statt `person`), über denselben
    `CarryoverMatcher` übertragen, auch auf Gesichter ohne Bestätigung.
  - Abgleich: `FaceMatcher::rankPersons` überspringt abgelehnte Personen des Gesichts (Zuordnung und Vorschlag);
    nach dem Übertragen ein Gesicht, dessen automatische Person abgelehnt ist, auf eine neue automatische Person
    setzen.
  - Konsolidierung: Cluster mit einem Gesicht, das die gewählte Person ablehnt, nicht dieser Person zuschlagen
    (das Gesicht abspalten oder die nächstbeste Person der Gruppe nehmen); Tests für beides.
  - `DuplicatePersonFinder` und die Vorschläge in Tinder/Schnell zuordnen/Resolver beachten die Ablehnungen.
Ältere Punkte (aus der Rekognition-Zeit):
- [ ] Mallorca-Videos und -Fotos durchsehen: Kontaktbogen je Person erzeugen, falsche Zuordnungen und getrennte Doppelungen zählen.
- [ ] Schwellen (Match, Vorschlag, Video-Link, Tracking) anhand der Zählung anpassen und als neue Standardwerte übernehmen.
- [ ] Danach die Hochzeits-Videos (viele Personen, schlechte Qualität) als härteren Test nehmen.

#### B. Personen schneller benennen ✓
Umgesetzt (siehe `documentation/media.md`, Abschnitt „Personen benennen“).
- [x] Zentraler `PersonMerger` (Zusammenführen/Benennen inkl. Suchindex); Personendetailseite nutzt ihn.
- [x] „Doppelte Personen“ (Identifizieren-Menü): ähnliche Personen aus `VideoFace.matchCandidates`, nie zusammen in einem Bild; „Gleiche Person“ führt zusammen, „Verschiedene Personen“ wird gespeichert (`PersonPairDismissal`).
- [x] „Wer ist dabei?“ auf der Ereignisseite (Admins): unbenannte Personen benennen (vorhandener Name → zusammenführen) oder als unbekannt markieren.
- [x] Namen aus dem Transkript als Hinweis (Ereignisseite und Personendetail).
- [x] Tests und Doku.

#### C. Rückblicke auf dem Dashboard
Der Menüpunkt „Dashboard“ ist aus Platzgründen entfallen (Logo führt hin); das Dashboard kann zur Startseite für Erinnerungen werden.
- [ ] „Heute vor … Jahren“: Medien mit gleichem Kalendertag aus früheren Jahren.
- [ ] Neueste Ereignisse und offene Ereignis-Vorschläge.
- [ ] Hinweise auf Aufgaben: unbenannte Personen, fehlgeschlagene Analysen, Medien ohne Aufnahmezeit.

#### D. Serienfotos und Doppelungen ✓
Umgesetzt (siehe `documentation/media.md`, Abschnitt „Serienfotos“).
- [x] Ähnliche Fotos gruppieren: höchstens 10 s Abstand + Bildähnlichkeit (Fingerabdruck aus Graustufen und Farbhistogramm; dHash war für Action-Cam-Fotos zu ungenau).
- [x] In Zeitachse, Ereignissen und Fotoliste nur das beste Foto der Serie (meiste Gesichter, dann schärfstes), Rest aufklappbar; auf der Fotoseite bestes Foto wählen oder aus der Serie lösen.
- [x] `bin/console app:photo:bursts` für vorhandene Fotos; Mallorca: 2 Serien (2 und 3 Fotos).
- [ ] Später möglich: Serien auch über Videos hinweg (Standbild vs. Foto) und genauere Wahl des besten Fotos (offene Augen, Lächeln über die Rekognition-Attribute).

#### E. Falsche Kamera-Uhren erkennen ✓
Umgesetzt (siehe `documentation/media.md`, Abschnitt „Falsche Kamera-Uhren“).
- [x] Kamera an allen Medien (Fotos: EXIF; Videos: Metadaten von iPhone/Android), Kamerazeit bleibt nach Korrekturen erhalten.
- [x] Hinweis bei unplausiblen Zeiten (vor 2000, Zukunft) auf der Medienseite und unter Verwalten → Kamera-Uhren; dort auch Kameras mit Zeitraum laut Kamera-Uhr.
- [x] Abweichung beim Korrigieren je Kamera merken (Admins); gilt automatisch für neue Importe derselben Kamera im Zeitfenster (± 3 Tage) und lässt sich auf vorhandene Medien anwenden.
- [ ] Nicht umgesetzt, weil die Daten es nicht hergeben: Abgleich mit Kopier-/Dateidatum (die AKASO stempelt auch die Dateien auf der SD-Karte mit ihrer falschen Uhr, Web-Uploads haben gar keins). Möglich wäre noch der Abgleich EXIF-Zeit gegen GPS-Zeit (Satellitenzeit) bei Kameras mit GPS.

#### F. Ein Upload für alles ✓
Umgesetzt (siehe `documentation/media.md`, Abschnitt „Upload & Import-Ordner“).
- [x] Gemeinsamer Upload `/upload` für Videos und Fotos (`MediaImporter`, Typ aus der Endung, Doppelte per Hash über beide Typen, Eigentümer auch für Videos).
- [x] Import-Ordner `storage/imports` (auch per SFTP `upload/import/`) mit Verwaltungsseite; ersetzt den kaputten Video-Bulk-Import.
- [x] Automatisch überwachen (Symfony Scheduler, alle 5 Minuten, `IMPORT_FOLDER_WATCH=1`); „vermutlich schon vorhandene“ Dateien nur von Hand.

#### G. Hub und Mobile-App anbinden
`momentary-hub` (Web-Kiosk) und `momentary-android` (Hotwire-Native-Hülle um den Hub) nutzen die API; Vertrag und Anleitung für deren Claude-Sitzungen: `documentation/clients/API.md`, `../momentary-hub/CLAUDE.md`, `../momentary-android/CLAUDE.md`. Fotos, Ereignisse, Zeitachse und Karte gibt es dort noch nicht.
- [ ] API-Ressourcen für `Photo` und `Event` (mit denselben Rechten wie im Web).
- [ ] Endpunkte für Zeitachse (Cursor) und gemeinsame Suche.

#### H. Archiv-Export
Langfristige Sicherheit der Familienmedien unabhängig von der App.
- [ ] Export je Ereignis bzw. komplett: Originale plus Metadaten (Aufnahmezeit, Ort, Personen, Tags) als JSON bzw. XMP-Sidecar.
- [ ] Jellyfin: Ereignisse als Sammlungen anlegen.

#### I. Technische Aufräumarbeiten
- [ ] `VideoFace` in `Face` umbenennen (gilt seit dem Medium-Umbau auch für Fotos).
- [ ] Deprecation „Passing string as $order to QueryBuilder::addOrderBy()“ (Auslöser in API-Filtern) beheben.
- [ ] Große Klassen mit Komplexitätswarnungen (u. a. `PersonResolverComponent`, `SceneSectionComponent`, `EventController`) aufteilen.
- [ ] Lasttest mit einigen tausend Fotos auf dem ThinkCentre (Zeitachse, Karte, Suche, Foto-Queue).

### Share-Links ✓
Umgesetzt (siehe `documentation/sharing.md`).
- [x] Konzept: Ereignis oder einzelnes Medium, Ablauf, optionales Passwort mit Versuchsgrenze, Widerruf, Download optional; nur was der Ersteller sehen darf, ohne Personen/Tags/Transkripte.
- [x] Öffentliche Seiten `/s/<token>` mit eigenem Video-Stream (ohne Jellyfin-Key, mit Spulen).
- [x] Karte „Teilen“ auf Ereignis-, Video- und Fotoseite, Übersicht „Freigaben“ (`/share`).

### Sicherheit: Jellyfin-API-Key im Video-Player
Beim Umsetzen der Share-Links aufgefallen: Die Videoseite bindet Jellyfin-Streams mit `api_key=` im HTML ein. Jeder
angemeldete Benutzer kann den Key auslesen und damit Jellyfin direkt bedienen (je nach Key auch als Admin).
- [ ] Videos auch für angemeldete Benutzer über die App streamen (wie bei den Share-Links) oder einen Jellyfin-Benutzer mit nur Leserechten verwenden.
- [x] Für Hub und Android-App: kurzlebige signierte Stream-URLs (`GET /api/videos/{id}/stream-url`, `VideoStreamUrls`), ohne Cookies, mit Spulen, zum Casten geeignet (`documentation/clients/API.md`, Abschnitt 6).
- [ ] Hub und Android-App auf die Stream-URLs umstellen (in deren Repos), danach `playbackUrl` mit Key aus `VideoSceneNormalizer` entfernen und `JELLYFIN_API_KEY` aus dem Hub löschen.
- [x] Videoseite im Backend spielt über `/stream/video/…` (Web-Fassung) statt Jellyfin mit Key.
- [x] Web-Fassung (1080p, ~4 Mbit/s) als Pipeline-Schritt, Standard für Stream-URLs und Share-Links (`documentation/media.md`, „Web-Fassung von Videos“).
- [ ] Auf dem ThinkCentre `/dev/dri` an den `messenger-worker` durchreichen (Intel Quick Sync) und die Geschwindigkeit messen.
- [ ] Zugriff von außen: Reverse Proxy mit HTTPS (z. B. Caddy) für Backend und imgproxy, `APP_PUBLIC_URL` und `IMGPROXY_PUBLIC_HOST` auf die öffentliche Adresse.

### Pools: Freigabe-Kreise für Familie und Verwandte ✓
Umgesetzt (siehe `documentation/pools.md`). Entscheidungen: Inhalt über Regeln und einzelne Medien; Mitglieder sehen Personen-Namen nur aus ihren Pools.
- [x] Pool mit Mitgliedern, Regeln (Ereignis, Tag, Zeitraum) und einzelnen bzw. ausgenommenen Medien; neue passende Medien kommen automatisch dazu.
- [x] Eine Sichtbarkeitsregel für Web, Zeitachse, Suche, Karte, Ereignisse, Share-Links und API.
- [x] Personen, Tags und Facetten nur aus sichtbaren Medien; Personenverwaltung, Upload und Identifizieren nur für Bearbeiter.
- [x] Verwaltung: Benutzer (Konten, Rollen, Passwörter) und Pools mit Vorschau; Pool-Karte auf Video- und Fotoseiten.
- [ ] Einladungslink statt Passwort weitergeben (Konto setzt beim ersten Login selbst ein Passwort).

### Sicherheit: Rechte in der API
Bei der Bestandsaufnahme für die Client-Anleitung (`documentation/clients/API.md`) aufgefallen:
- [x] `/api/videos` und `/api/videos/{id}` beachten jetzt die Sichtbarkeit (Pools).
- [x] `/api/search`, `/api/persons` und `/api/tags` liefern nur Sichtbares (ohne Anmeldung nur Öffentliches).
- [ ] `/api/login_check` aus der Security-Konfiguration entfernen (JWT-Bundle ist nicht aktiv).
- [ ] momentary-android: privater SSH-Schlüssel `id_momentary` und `local.properties` sind eingecheckt; Schlüssel ersetzen, aus der Historie entfernen, `.gitignore` anlegen.


## Erledigte Aufgaben

### Rechteverwaltung für Szenen
- [x] Zugriffskontrolle für Szenen entwerfen (Pattern aus `VideoAccessVoter` übernehmen)
- [x] Voter für `VideoScene` implementieren (auf Basis von `VideoAccessVoter`)
- [x] UI-Anpassungen (Rechte-Konfiguration in Szenen-UI einbauen)

### Admin Login & Styling
- [x] Session-Persistence für Admin-Login implementieren (Remember-Me in `security.yaml` konfigurieren)
- [x] Login-Formular Design überarbeiten (Styling in `templates/login/index.html.twig` verbessern)

### Frontend Login Vorbereitung
- [x] Backend-API-Endpunkt `/api/login_check` für JWT-Authentifizierung ist konfiguriert und einsatzbereit.
- [x] Prompt für Frontend-Entwickler erstellt (siehe Übergabe an Frontend-KI).

### Datei-Management (FTP/SFTP)
- [x] UI zum Verschieben von Dateien vom FTP/SFTP-Upload-Verzeichnis in `uploads/import` entwerfen
- [x] Backend-Logik zum Auflisten und Verschieben von Dateien (aus FTP-Verzeichnis in `uploads/import`) implementieren

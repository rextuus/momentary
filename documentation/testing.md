# Tests

## Ausführen

```bash
composer test              # komplette Suite ohne die langsamen Tests (~15 s)
composer test:unit         # nur Unit-Tests (Millisekunden)
vendor/bin/phpunit --testsuite functional
vendor/bin/phpunit --filter AccessControlTest
composer test:slow         # echte ffmpeg/PySceneDetect-Pipeline im Worker-Container (~2 min)
```

Lokal muss `ext-amqp` nicht installiert sein; für `composer install` außerhalb von Docker
`--ignore-platform-req=ext-amqp` verwenden.

## Aufbau

| Verzeichnis | Basisklasse | Wofür |
| --- | --- | --- |
| `tests/Unit` | `TestCase` | Reine Logik ohne Kernel: Voter, `ImgproxyService`, `StoragePathProvider`, Verkettung der Pipeline-Schritte |
| `tests/Integration` | `KernelTestCase` | Services mit Container und Datenbank: Messenger-Routing, Pipeline mit gemockten Analyse-Services, Import-Queue, Upload |
| `tests/Functional` | `WebTestCase` | Echte HTTP-Requests: Rechte (wer darf was), Such-API, API-Platform-Filter |
| `tests/Factory` | Foundry | Testdaten-Factories (`UserFactory::new()->admin()->create()`, `VideoFactory::new()->private()->ownedBy($user)`) |
| `tests/fixtures/samples` | – | Beispielvideos und aufgezeichnete Rekognition-/Gemini-Antworten |

## Infrastruktur

- **PHPUnit 13** mit Symfony-, DAMA- und Foundry-Extension (`phpunit.xml.dist`).
- **Datenbank:** SQLite unter `var/`, in `phpunit.xml.dist` per `force` erzwungen. Wichtig im Docker-Container: Dort ist
  `DATABASE_URL` als echte Umgebungsvariable gesetzt und hätte sonst Vorrang, die Tests würden die Dev-Datenbank leeren. Foundry legt das Schema einmal pro Lauf an, DAMA rollt jeden Test
  in einer Transaktion zurück. Tests müssen nichts aufräumen und sehen nie Daten anderer Tests.
- **Storage:** `STORAGE_ROOT` ist ebenfalls erzwungen (`var/storage`), denn einige Tests löschen Verzeichnisse
  wie `videos/`; im Container würde sonst das echte `storage/` geleert. Gleiches gilt für die imgproxy-Hosts.
- **Sicherheitsnetz:** `tests/bootstrap.php` bricht den Lauf ab, wenn `DATABASE_URL` nicht auf SQLite oder
  `STORAGE_ROOT` nicht unter `var/` zeigt. Neue Umgebungsvariablen, die auf echte Daten zeigen, gehören mit
  `force="true"` in `phpunit.xml.dist`.
- **Messenger** läuft im Test-Env über `sync://`. Pipeline-Schritte werden deshalb direkt nacheinander
  im Testprozess ausgeführt. Achtung: `VideoProcessMessageDispatcher` fängt dabei Fehler späterer
  Schritte ab; die Retry-/Failure-Logik des Workers wird in `FailedStepSubscriberTest` separat geprüft.
- **API-Authentifizierung:** `OAuth2ClientTrait` holt per Password-Grant ein echtes Access-Token.
  Das Schlüsselpaar dafür erzeugt `tests/bootstrap.php` unter `var/test-oauth2/`.
- **Umgebung:** `.env.test` enthält Dummy-Werte für alle Variablen, die Suite braucht weder eine
  lokale `.env.local` noch externe Dienste (Meilisearch, AWS, Gemini, Imgproxy werden gemockt oder nie erreicht).
- **Ausgaben:** `beStrictAboutOutputDuringTests` ist aktiv. Die Pipeline schreibt Fortschritt für das
  Worker-Log auf stdout; `VideoPipelineTestCase::dispatch()` fängt das ab.

## Langsame Tests (`#[Group('slow')]`)

`RealVideoProcessingPipelineTest` lässt die echte Pipeline mit ffmpeg und PySceneDetect über die
Beispielvideos laufen. Nur Rekognition, Gemini und Meilisearch sind ersetzt. Ohne die Tools wird der Test
übersprungen; im Worker-Container ist alles installiert (`composer test:slow`).

Die Rekognition-Fixtures lassen sich mit `php tests/tools/fetch_rekognition_data.php` neu erzeugen
(ruft das echte AWS auf).

## CI

`.github/workflows/qa.yml` führt Container-, YAML-, Twig- und Mapping-Lint sowie die Suite
ohne `slow`-Gruppe aus.

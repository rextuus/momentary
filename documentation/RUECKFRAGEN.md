# Rückfragen

Offene Entscheidungen aus der Arbeit an Gesichtserkennung und Homevideos. Antwort gern direkt hier darunter.

## DV-Aufnahmedatum (Vorschlag: `dv_aufnahmedatum.md`)

1. **Testdatei:** Lokal gibt es keine echte DV-AVI. Getestet habe ich mit einer künstlichen Kassette; ob echte
   Camcorder `rec_start` setzen und wie robust dvrescue bei Bandfehlern ist, zeigt nur eine echte Datei. Bitte eine
   (gern kurze, z. B. 2–3 Aufnahmen) MiniDV- oder Digital8-AVI bereitstellen, am besten mit Aufnahmen von
   verschiedenen Tagen.
2. **dvrescue ins App-Image:** über das MediaArea-Apt-Repository (Fremdquelle im Build) oder feste `.deb`-Dateien mit
   Prüfsumme? Mein Vorschlag: feste `.deb` mit Prüfsumme, dann baut Komodo reproduzierbar.
3. **Früheste plausible Aufnahmezeit:** `CameraClock::EARLIEST_PLAUSIBLE` ist 2000-01-01. Gibt es Aufnahmen vor 2000
   (MiniDV ab 1995, Digital8 ab 1999, Hi8/VHS noch älter, dort aber ohne Datum)? Vorschlag: 1990-01-01, oder für DV eine
   eigene Grenze.
4. **Zeitzone der Kamerazeit:** DV speichert Ortszeit ohne Zone. Als Europe/Berlin lesen, wie beim Dateinamen? Bei
   Urlaubsaufnahmen im Ausland liegt das dann um die Zeitverschiebung daneben.
5. **Umfang der ersten Stufe:** Mein Vorschlag ist (a) Segmente lesen und speichern, `takenAt` = erstes Segment,
   Segmentgrenzen als Szenengrenzen. Ereignisse, Pools und Zeitleiste je Segment erst danach. Passt die Reihenfolge?
6. **Pools/Freigaben:** Gehört eine Kassette mit Weihnachten 2005 und Urlaub 2006 dann ganz in beide Pools, oder soll es
   später Freigaben je Segment/Szene geben?

## Neuverarbeiten

7. Beim Neuverarbeiten werden jetzt alte Szenen und Gesichter ersetzt. Von Hand geänderte Szeneneinstellungen
   (öffentlich, Gruppen, Tags) und bestätigte Gesichter dieses Videos gehen dabei verloren; benannte Personen bleiben
   und werden neu zugeordnet. Ist das so gewollt, oder sollen bestätigte Zuordnungen erhalten bleiben (z. B. über die
   Bildposition auf die neuen Gesichter übertragen)?

## Aufbereitete Fassung

8. Beim späteren Anhängen: eigene Upload-Schaltfläche auf der Videoseite zusätzlich zum Command, oder reicht der
   Import-Ordner (`enhanced/<videoId>_….mp4`) für die großen Dateien?

## Sonstiges

9. Dev-Datenbank `momentary_dev` ist noch nicht migriert (vier neue Migrationen). Soll ich sie nach einer Sicherung
   migrieren, oder machst du das selbst?

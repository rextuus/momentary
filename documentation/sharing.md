# Share-Links

Ein Share-Link zeigt Menschen ohne Konto ein **Ereignis** (als Album) oder ein **einzelnes Video bzw. Foto**.

## Anlegen und verwalten

- Karte **Teilen** auf Ereignis-, Video- und Fotoseite (`SharePanelComponent`): Gültigkeit (1 Tag, 7 Tage,
  30 Tage (Standard), 90 Tage oder unbegrenzt), optionales Passwort, „Originale herunterladen erlauben“, eine Notiz
  (z. B. „für Oma“, sieht nur der Ersteller). Kopieren und Zurückziehen direkt in der Karte.
- **Freigaben** (`/share`): alle eigenen Links mit Status, Ablauf und Aufrufen; Admins sehen die Links aller Benutzer.
- Teilen darf, wer das Ereignis bzw. Medium bearbeiten darf (Ersteller bzw. Eigentümer, Admins). Öffentlich sehen
  reicht nicht. Zurückziehen darf der Ersteller und jeder Admin.

## Was der Empfänger sieht

`/s/<token>` (`PublicShareController`, `ShareFileController`, eigene Vorlagen unter `templates/share/` ohne Navigation):

- Ereignis: Titel, Zeitraum, Beschreibung und alle Medien nach Aufnahmezeit, mit Einzelansicht und Vor/Zurück.
  Einzelnes Medium: direkt die Einzelansicht.
- **Nur Medien, die der Ersteller selbst sehen darf** (`MediumAccessVoter::canView()` für den Ersteller). Kommen
  später Medien zum Ereignis hinzu, zeigt der Link sie mit; verliert der Ersteller den Zugriff, verschwinden sie.
- Keine Personen, Gesichter, Tags, Transkripte, Karten oder Links ins übrige Archiv.
- **Videos** laufen über `/s/<token>/video/<id>` direkt aus dem Storage (MP4, mit Range-Anfragen zum Spulen), nicht
  über Jellyfin: Dessen API-Key darf nicht nach außen. Fotos und Vorschaubilder kommen wie überall über imgproxy
  (signierte URLs).
- Download der Originale nur, wenn beim Anlegen erlaubt (`/s/<token>/download/<id>`).

## Schutz

- Token: 256 Bit Zufall (base64url, 43 Zeichen), im Klartext gespeichert, damit der Ersteller den Link später erneut
  kopieren kann. Wer die Datenbank lesen kann, kann also geteilte Inhalte öffnen.
- Ablauf und Zurückziehen werden bei jedem Aufruf geprüft (auch für Video und Download); ungültige, abgelaufene und
  unbekannte Links zeigen dieselbe Seite „nicht (mehr) gültig“ (404).
- Passwort: nur als Hash (`password_hash`) gespeichert; nach richtiger Eingabe ist der Link für die Sitzung
  freigeschaltet. Höchstens 10 Versuche je Link und IP-Adresse in 15 Minuten (`symfony/rate-limiter`,
  `config/packages/rate_limiter.yaml`).
- `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer` (der Token gelangt nicht per Referer zu imgproxy oder
  anderen Seiten), Antworten `private`.
- Aufrufe werden gezählt (Anzahl, letzter Aufruf); IP-Adressen werden nicht gespeichert.

## Grenzen

- Damit Links von außen funktionieren, müssen App und imgproxy (`IMGPROXY_PUBLIC_HOST`) aus dem Internet erreichbar
  sein (z. B. per Reverse Proxy mit HTTPS).
- Videos ohne MP4 im Storage (z. B. noch nicht konvertiert) sind im Link nicht abspielbar.

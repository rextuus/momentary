# Pools, Benutzer und Rollen

## Rollen

| Rolle | Wer | Darf |
|---|---|---|
| Familie (`ROLE_USER`) | Mutter, Verwandte | Videos, Fotos, Zeitachse, Ereignisse, Karte, Suche – nur öffentliche Medien und die ihrer Pools; startet auf der Zeitachse (Dashboard nur für Bearbeiter) |
| Bearbeiter (`ROLE_EDITOR`) | wer das Archiv pflegt | zusätzlich hochladen, Personen benennen und verwalten, Identifizieren |
| Admin (`ROLE_ADMIN`) | du | alles; sieht alle Medien |

Konten: **Verwalten → Benutzer** (`/admin/users`, `Users`): anlegen mit Rolle und Pools; das Passwort (eigenes oder ein
gut lesbares wie `buta-dzhm-j58x`) wird einmal angezeigt und nur als Hash gespeichert. „Neues Passwort“ erzeugt ein
neues. Löschen geht nur bei Konten ohne eigene Medien, Ereignisse, Pools oder Share-Links.
Per Konsole: `bin/console app:user:create <E-Mail> [--role=family|editor|admin] [--pool=<Name|ID> …] [--password=…]
[--reset-password]` (`CreateUserCommand`, nutzt `Users`).

## Pools

Ein Pool (Entity `UserGroup`, früher „Gruppe“) legt fest, wer was sieht. **Verwalten → Pools** (`/admin/pools`):

- **Regeln** (`PoolRule`): Ereignis, Tag (am ganzen Medium, z. B. Upload-Ordner „Heimvideos“ oder ein Ort), Aufnahme von
  / bis. Alle ausgefüllten Felder einer Regel müssen zutreffen; es genügt, wenn eine Regel passt. Neue passende Medien
  kommen automatisch dazu. Zeitgrenzen gelten in UTC (Tage).
- **Einzelne Medien**: Pool-Karte auf jeder Video- und Fotoseite (`MediumPoolsComponent`, für Eigentümer und Admins):
  „Aufnehmen“ (`Medium::allowedGroups`) bzw. „Herausnehmen“ – passt eine Regel, wird das Medium im Pool
  **ausgenommen** (`UserGroup::excludedMedia`); auf der Pool-Seite „Wieder aufnehmen“.
- **Mitglieder**: Konten, die die Medien des Pools sehen. Admins brauchen keinen Pool.
- Vorschau auf der Pool-Seite: was die Mitglieder sehen.

## Sichtbarkeit (eine Regel für alles)

Ein Medium sieht, wer Admin oder Eigentümer ist, wenn es öffentlich ist oder wenn es in einem Pool ist, in dem der
Benutzer Mitglied ist. Die Regel steht einmal in `MediumRepository::visibleCondition()` (DQL, auch für Unterabfragen)
und gilt für Listen (`restrictToVisible`), den Voter (`VisibleMedia`, lädt die sichtbaren IDs einmal je Anfrage),
Zeitachse, Suche, Karte, Ereignisse, Share-Links und die API (`HubExtension`: Videos, Gesichter, Personen, Tags;
`/api/search`; Stream-URLs).

- Personen, Tags und Facetten erscheinen nur aus sichtbaren Medien; Personen-Links führen Familienmitglieder zur
  Zeitachse der Person statt zur Personenverwaltung (`person_path()`).
- Ereignisse sieht man, wenn man sie angelegt hat oder mindestens ein Medium darin sichtbar ist.
- Die Videoseite spielt über die signierte Stream-URL (Web-Fassung) – kein Jellyfin-Key im HTML.
- Szenen-Freigaben (öffentliche Szenen in privaten Videos, Gruppen je Szene) gelten weiter.

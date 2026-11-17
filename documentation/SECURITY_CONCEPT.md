# Security-Konzept: Rechtemanagement für Momentary

Dieses Dokument skizziert das geplante Rechtemanagement für die Momentary-Plattform, um einen sicheren Zugriff auf Video-Inhalte und Analyse-Daten zu gewährleisten.

## 1. Ausgangslage
Momentary ist aktuell weitgehend "offen". Da das Projekt wächst und künftig nicht jeder Besucher alle Inhalte sehen soll, müssen wir eine Authentifizierung und Autorisierung implementieren.

## 2. Architektur & Komponenten
Wir werden das **Symfony Security Component** nutzen, da es die Industriestandard-Lösung für Symfony-Projekte ist.

### A. Authentifizierung
- **User-Entity:** Einführung einer `User`-Entity (E-Mail, Passwort-Hash, Rollen, Settings-Relation).
- **Authenticator:** Ein `LoginFormAuthenticator` für den Web-Zugriff (Momentary Hub).
- **API-Auth:** Für den mobilen Client (Momentary Mobile) wird eine Token-basierte Authentifizierung (z.B. JWT via LexikJWTAuthenticationBundle) benötigt, um API-Anfragen zu sichern.

### B. Rollen-Hierarchie
Hierarchie (niedrigste zu höchste):
1. `ROLE_GUEST`: Basis-Rechte (nicht eingeloggt, Zugriff auf öffentliche Inhalte).
2. `ROLE_USER`: Standard-Nutzer (sehen freigegebene Videos/Szenen, können Gruppen erstellen).
3. `ROLE_EDITOR`: Kann Metadaten/Tags bearbeiten.
4. `ROLE_ADMIN`: Voller Zugriff, Benutzerverwaltung.

### C. Dynamisches Access Control (Gruppen)
- **UserGroups:** User können beliebig viele Gruppen erstellen und andere User hinzufügen.
- **Media-Restriction:** Beim Upload/Video-Profil kann festgelegt werden, welche Gruppe(n) Zugriff haben.
- **Suche & Blur-Logik:** "Verbotene" (nicht zugreifbare) Bilder/Videos bleiben in der Suche sichtbar, werden aber via `imgproxy` weichgezeichnet (blur), sofern der User keine explizite Berechtigung hat.

### D. User Settings
- **UserSettings-Entity:** Verknüpft mit User (1:1).
- **Konfiguration:** Optionen wie "Verbotene Inhalte anzeigen/blurren".

## 3. Technische Umsetzung (Access Control Strategy)
- **RBAC (Role-Based Access Control):** Einfache Absicherung von Controllern/Seiten via `security.yaml` und `#[IsGranted('ROLE_...')]`.
- **DAC (Dynamic Access Control):** Zentrales **Symfony Voter System** (`VideoAccessVoter`).
    - Der Voter prüft bei jedem Zugriff auf eine `Video`-Entität:
        1. Ist der User `ROLE_ADMIN`? -> GRANTED.
        2. Ist das Video öffentlich? -> GRANTED.
        3. Ist der User Besitzer des Videos? -> GRANTED.
        4. Ist der User in einer Gruppe, die Zugriff hat? -> GRANTED.
        5. Sonst -> DENY.
- **Blur-Logik (Imgproxy):**
    - `ImgproxyService` wird um eine Methode `signUrl(string $path, bool $blur = false)` erweitert.
    - Wenn der `VideoAccessVoter` einen Zugriff verweigert, wird der `imgproxy` URL mit `bl:sigma` signiert, sofern `UserSettings->blurForbiddenContent` aktiv ist.

## 4. Infrastruktur-Anforderungen
- Datenbank-Migration für `User`, `UserSettings`, `UserGroup`, `UserGroupMember`.
- Anpassung der `security.yaml` (Wechsel von `in_memory` zu `doctrine`-Provider).
- Passwort-Hashing (bcrypt).

## 5. Implementierungs-Roadmap (TODOs)

### Phase 1: Vorbereitung & Basis (Authentifizierung & User)
- [x] `User`-Entity erstellen und in Doctrine konfigurieren.
- [x] `UserSettings`-Entity erstellen (1:1 Relation zu User).
- [x] `security.yaml` auf `doctrine`-User-Provider umstellen.
- [x] Login-Formular und Authenticator implementieren.
- [x] Registrierung/User-Anlage-Mechanismus (Initial Admin).

### Phase 2: Autorisierung (Rechte & Gruppen)
- [x] Rollen-Hierarchie in `security.yaml` definieren.
- [x] `UserGroup` & `UserGroupMember` Entitäten (Relationen: User N-M UserGroup, Video N-M UserGroup).
- [x] `VideoAccessVoter` implementieren:
    - [x] `supports()` Methode für `Video` Klasse.
    - [x] `voteOnAttribute()` für `VIEW` Berechtigung (Admin/Besitzer/Gruppe Check).
- [x] `IsGranted('VIEW', video)` in Controllern/Templates einbauen.

### Phase 3: Daten-Sicherheit & Blur-Logik
- [x] `ImgproxyService` zur dynamischen URL-Signierung um Blur-Option erweitern.
- [x] Suche/Listen-Ansicht anpassen:
    - [x] `VideoAccessVoter` für Listen-Ergebnisse nutzen.
    - [x] Wenn `!$voter->vote(VIEW, video)` und `user->settings->blurForbidden`:
        - [x] URL-Generierung mit `bl:sigma` Parametern.
- [x] Test-Szenarien definiert:
    1. **Admin Zugriff:** Admin (`ROLE_ADMIN`) kann alle Videos ansehen.
    2. **Besitzer-Zugriff:** Benutzer (`ROLE_USER`) als Besitzer hat vollen Zugriff.
    3. **Gruppen-Zugriff:** Gruppenmitglieder haben vollen Zugriff.
    4. **Restriktion & Blur:** Ohne Berechtigung wird das Thumbnail verschwommen (Blur 5) dargestellt.

### Phase 4: Mobile Anbindung
- [ ] JWT-Authentifizierung für den mobilen Client implementieren.
- [ ] Sicherstellung, dass der Android-Client Token-basiert authentifiziert ist.

### Phase 5: Management UI (Admin Dashboard)
- [x] Gruppen-Verwaltung (CRUD für `UserGroup`).
- [x] Zuweisungs-Interface für Videos (Welche Gruppe darf welches Video sehen?).
- [x] Explizite "Öffentlich"-Steuerung (`isPublic` Flag) hinzugefügt.
- [x] Admin-Übersicht für User-Gruppen-Mitgliedschaften.

---
Dieses Konzept dient als Diskussionsgrundlage.

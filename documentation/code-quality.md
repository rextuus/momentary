# Code-Qualität

```bash
composer qa          # alles: Stil, statische Analyse, Tests (~20 s)
composer cs          # Code-Stil und Lint prüfen
composer cs:fix      # Code-Stil und Lint-Befunde automatisch beheben
composer phpstan     # statische Analyse
```

Vor einem Commit reicht in der Regel `composer cs:fix && composer qa`.

## Mago (Formatierung + Lint)

Konfiguration: `mago.toml`.

- **Formatter:** PER-CS (Nachfolger von PSR-12), Zeilenlänge 120. `composer cs:fix` formatiert alles.
- **Linter** mit Symfony-, Doctrine- und PHPUnit-Regeln. Aktiv und als Fehler eingestuft:
  `declare(strict_types=1)` in jeder Datei, keine ungenutzten Imports, `final` Controller,
  kein `dd()`/`dump()`, strikte Vergleiche (`in_array(..., true)`), `#[SensitiveParameter]` bei Passwörtern.
- Reine Geschmacksregeln (z. B. Named Arguments erzwingen, `else` verbieten) und Komplexitätsmetriken,
  die bei Entities und DI-Konstruktoren nur Rauschen erzeugen, sind abgeschaltet.
  `cyclomatic-complexity` meldet ab 20 nur eine Warnung.
- Bewusste Ausnahmen stehen direkt im Code, mit Begründung darüber:
  `// @mago-expect lint:<regel>`

Der Mago-Analyzer wird nicht genutzt, die Typanalyse übernimmt PHPStan.

## PHPStan (statische Analyse)

Konfiguration: `phpstan.dist.neon`, **Level 8**, mit den Erweiterungen für Symfony (kennt den Container und
Console-Commands), Doctrine (prüft DQL und Mapping) und PHPUnit.

- Neuer und geänderter Code muss Level 8 ohne Fehler bestehen.
- Altlasten stehen in `phpstan-baseline.neon`. Wer einen Eintrag behebt, bekommt von PHPStan einen Hinweis,
  dass der Baseline-Eintrag nicht mehr gebraucht wird (`reportUnmatchedIgnoredErrors`). Dann
  `composer phpstan:baseline` laufen lassen, damit die Baseline schrumpft.
- Die Baseline nie neu erzeugen, um neue Fehler zu verstecken.
- PHPStan braucht den kompilierten Test-Container; `composer phpstan` wärmt ihn vorher auf.

## CI

`.github/workflows/qa.yml` prüft Container/Config, `composer cs`, `composer phpstan` und die Tests.

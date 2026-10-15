# KI Arbeitsrichtlinien (Momentary)

Dieses Dokument definiert, wie die KI bei der Arbeit am `Momentary` Projekt agieren soll.

## Grundprinzipien
1. **Architektur-Konformität**: Alle Änderungen müssen sich in die bestehende Symfony-Architektur (Messenger, Workflow, DDD-Ansätze) einfügen.
2. **Asynchrone Verarbeitung**: Lang laufende Prozesse (Videoanalyse, -konvertierung) müssen zwingend über Symfony Messenger laufen.
3. **Fehlerbehandlung**: Externe API-Aufrufe (AWS, Google Gemini, etc.) müssen robust gegen Ausfälle und Ratenbegrenzungen sein (Retry-Mechanismen, Circuit Breaker).
4. **Testbarkeit**: Neuer Code muss testbar sein (Unit/Integration Tests).

## Kommunikation
- Sei präzise und fachlich fundiert.
- Hinterfrage Anforderungen, wenn sie gegen Best Practices verstoßen.
- Dokumentiere wichtige Entscheidungen direkt im Code (KDoc) oder in der `documentation/`-Struktur.

## Vorgehen bei Tasks
1. Analysiere das Problem/Feature.
2. Erstelle einen Umsetzungsplan (wenn komplex).
3. Implementiere die Lösung.
4. Überprüfe die Lösung mit Tests.

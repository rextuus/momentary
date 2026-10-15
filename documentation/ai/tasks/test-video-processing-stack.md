# Testen des Video Processing Stacks mit API-Mocking
Status: OPEN
Erstellt am: 2026-10-10

## Beschreibung
Der gesamte Symfony Messenger Video Processing Stack soll realistisch getestet werden. Dabei müssen externe APIs (AWS Rekognition, Google Gemini, etc.) gemockt werden, um keine echten Service-Aufrufe zu tätigen. 
Zudem sollen die integrierten Python-Skripte (`video_to_screenshots.py`, `video-analyzer`) im Test-Kontext validiert werden.

Zur Erzielung einer hohen Testabdeckung werden dedizierte Test-Videos (MP4) benötigt:
- Einzelszenen-Videos.
- Videos mit multiplen Szenen.
- Videos mit multiplen Szenen inkl. Szenen ohne Personen (erfordert Szenen-Merging).

Die Tests müssen zwingend auf einer separaten Test-Datenbank ausgeführt werden, um Datenkontamination zu vermeiden.

## Plan
1. Analyse der Architektur zur Identifikation der externen API-Integrationspunkte.
2. Einrichtung der Test-Datenbank-Umgebung.
3. Bereitstellung/Erstellung der notwendigen MP4-Test-Videos (ohne erneuten Conversion-Schritt im Test).
4. Entwicklung einer Mocking-Strategie für externe APIs (Gemini, AWS) und Python-Skripte.
5. Erstellung von Mock-Daten (JSON-Responses für Gemini, AWS API-Antwortstrukturen).
6. Implementierung von Integration-Tests für den Workflow-Durchlauf (`video_processing`) mit den definierten Szenario-Videos.

## Referenzen
- `documentation/processing_pipeline.md`
- `config/packages/test/messenger.yaml`

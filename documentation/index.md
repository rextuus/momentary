# Momentary – Dokumentation

Familien-Archiv für Videos und Fotos: Import, Analyse (Gesichter mit InsightFace, optional AWS Rekognition, Szenen, Tags mit Gemini,
Transkription mit faster-whisper), Web-Oberfläche zum Pflegen und REST-API für Hub und Android-App.

## Betrieb
- [Entwicklungsumgebung](setup.md) – lokal mit Docker
- [Deployment](deployment.md) – Produktion auf dem ThinkCentre hinter dem VPS
- [Konsolenbefehle](console_commands.md)

## Fachliches
- [Fotos, Medien & Ereignisse](media.md) (Einstieg)
- [Video-Pipeline](processing_pipeline.md) und [Automatisierung](architecture/video_automation.md)
- [Gesichtserkennung](face_recognition.md)
- [Ton-Transkription](transcription.md)
- [Pools (Sichtbarkeit)](pools.md) und [Teilen per Link](sharing.md)
- [Jellyfin-Anbindung](jellyfin_integration.md)
- [Datenbankschema](database_schema.md), [Architektur](architecture.md), [Projektüberblick](project_overview.md)
- [Sicherheitskonzept](SECURITY_CONCEPT.md)

## Clients und Qualität
- [API-Vertrag für Hub und Android-App](clients/API.md), [Wünsche der Clients](clients/WUENSCHE.md)
- [Tests](testing.md), [Code-Qualität](code-quality.md)
- [Offene Aufgaben](TODO.md)

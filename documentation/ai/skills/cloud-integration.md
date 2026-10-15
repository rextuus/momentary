# Skill: Cloud-Integration (AWS, Google, Third-Party)

## Fokus
- Integration von Drittanbieter-APIs (AWS Rekognition, Google Gemini, Cloud Vision).
- Fehlerbehandlung (Circuit Breaker, Exponential Backoff).
- Sicherheitsaspekte (Credentials-Management, IAM).
- Kostenkontrolle (Rate Limiting, Ressourcen-Optimierung).

## Regeln
- APIs MÜSSEN asynchron über Messenger aufgerufen werden.
- Implementiere Retry-Mechanismen für transiente Fehler.
- Secrets dürfen NIE im Code landen (Nutze Umgebungsvariablen).
- Erstelle robuste Interfaces für Third-Party-Dienste, um Mocking in Tests zu erleichtern.

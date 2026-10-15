# Skill: Symfony Messenger (Asynchrone Verarbeitung)

## Fokus
- Design asynchroner Workflows (Message, Handler, Bus).
- Korrekte Serialisierung/Deserialisierung von Messages.
- Robuste Fehlerbehandlung in Handlern (Retry-Strategien, Dead Letter Queues).
- Performance bei der Queue-Verarbeitung (Worker-Konfiguration).

## Regeln
- Lang laufende Aufgaben gehören IMMER in Messenger-Worker.
- Handler müssen idempotent sein.
- Überwache die Queue-Länge (Monitoring).
- Nutze `Symfony Messenger` für alle externen API-Calls, um die UI nicht zu blockieren.

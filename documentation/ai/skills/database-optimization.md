# Skill: Datenbank-Optimierung (Doctrine & SQL)

## Fokus
- Eager Loading vs. Lazy Loading (N+1 Problem vermeiden).
- Query-Optimierung und Indizierung.
- Performante Migrationen.
- Cache-Strategien für Entity-Daten.

## Regeln
- Analysiere SQL-Queries (`doctrine:query:sql` oder Debug-Toolbar).
- Nutze DTOs für schreibgeschützte Abfragen, wenn Entities zu schwergewichtig sind.
- Achte auf konsistente Datenmigrationen.
- Verwalte Indizes bei großen Tabellen explizit.

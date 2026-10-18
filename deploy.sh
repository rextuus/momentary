#!/bin/bash
# Deployment-Skript für Momentary
set -e

echo "Starte Deployment..."

# 1. Pull latest code
git pull origin main

# 2. Images bauen und Container mit den neuen Images starten (restart allein nutzt das alte Image weiter)
docker compose build app messenger-worker transcription-worker
docker compose up -d app messenger-worker transcription-worker

# 3. Cache leeren und aufwärmen
docker compose exec -T app bin/console cache:clear --env=prod
docker compose exec -T app bin/console cache:warmup --env=prod

# 4. Migrationen ausführen
docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction

# 5. RabbitMQ Queue leeren
docker compose exec -T rabbitmq rabbitmqctl purge_queue messages

# 6. Worker neu starten (laufende Transkriptionen bleiben in ihrer eigenen Queue erhalten)
docker compose restart messenger-worker transcription-worker

echo "Deployment erfolgreich abgeschlossen."

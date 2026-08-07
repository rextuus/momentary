#!/bin/bash
# Deployment-Skript für Momentary
set -e

echo "Starte Deployment..."

# 1. Pull latest code
git pull origin main

# 2. Build containers
docker compose build messenger-worker app

# 3. Cache leeren und aufwärmen
docker compose exec -T app bin/console cache:clear --env=prod
docker compose exec -T app bin/console cache:warmup --env=prod

# 4. Migrationen ausführen
docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction

# 5. Worker neu starten
docker compose restart messenger-worker

echo "Deployment erfolgreich abgeschlossen."

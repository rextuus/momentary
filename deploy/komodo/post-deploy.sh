#!/bin/sh
# Momentary – Komodo „Post Deploy“: läuft nur nach erfolgreichem `up -d --wait`, im Stack-Ordner im
# Periphery-Container. Schreibt den Zustand ins Deploy-Log und räumt alte, nicht mehr genutzte Momentary-Images
# auf (jedes Bauen lässt das vorherige Image namenlos zurück). Fremde Images anderer Stacks bleiben unberührt.
#
# In Komodo: post_deploy.command = "sh deploy/komodo/post-deploy.sh"
set -eu

project=${COMPOSE_PROJECT:-momentary}
env_file=${ENV_FILE:-.env.prod.local}
dc() { docker compose -p "$project" -f compose.prod.yaml --env-file "$env_file" "$@"; }

dc ps --format 'table {{.Service}}\t{{.Status}}'
echo
echo "Webserver: $(dc exec -T app curl -fsS http://127.0.0.1/healthz 2>/dev/null || echo FEHLER)"
echo "Migrationen: $(dc logs --no-log-prefix migrate 2>/dev/null | grep -Ei 'no migrations|migrat' | tail -2 | tr '\n' ' ')"
echo "Warteschlangen:"
dc exec -T rabbitmq rabbitmqctl -q list_queues name messages 2>/dev/null | sed 's/^/  /' || echo "  FEHLER"

# Nur namenlose („dangling“) Images, die Compose für dieses Projekt gebaut hat.
docker image prune -f --filter "label=com.docker.compose.project=${project}" | tail -1

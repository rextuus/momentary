#!/bin/sh
# Momentary – Datenbank-Dump mit Rotation. Läuft im Dienst `backup` aus compose.prod.yaml (Image mysql:8.4.13),
# nie direkt auf dem Host:
#   docker compose -p momentary -f compose.prod.yaml --env-file .env.prod.local run --rm backup [anlass]
# Schreibt /backups/momentary-db-<UTC-zeit>-<anlass>.sql.gz. Anlass (Standard „manuell“): daily (Komodo-Procedure
# momentary-backup), pre-deploy (vor jedem Deploy) oder frei. Je Anlass bleiben die neuesten BACKUP_KEEP (14).
# Ziel /backups = auf dem Host BACKUP_DIR (Standard /srv/momentary/backups). Bricht bei jedem Fehler mit
# Exit-Code ≠ 0 ab, ein halber Dump bleibt nie liegen.
set -eu
# Dumps enthalten Passwort-Hashes und persönliche Daten: nur für root lesbar.
umask 077

reason=$(printf '%s' "${1:-manuell}" | tr -c 'A-Za-z0-9-' '-')
keep=${BACKUP_KEEP:-14}
dir=/backups
name="momentary-db-$(date -u +%Y%m%dT%H%M%SZ)-${reason}.sql.gz"
tmp="${dir}/.${name%.gz}.partial"

case "$keep" in
  ''|*[!0-9]*|0) echo "Anzahl zu behaltender Dumps muss eine Zahl ≥ 1 sein (ist: '${keep}')." >&2; exit 2 ;;
esac

# Täglich läuft dieses Skript ohne pre-deploy.sh (RunStackService) – also hier selbst prüfen, ob Komodo das
# Secret eingesetzt hat (ein unbekanntes [[NAME]] bleibt wörtlich stehen).
case "${MYSQL_PWD:-}" in
  ''|*'[['*']]'*) echo "MYSQL_ROOT_PASSWORD fehlt oder ist ein unaufgelöstes [[…]] – Secret in Komodo prüfen." >&2; exit 2 ;;
esac

trap 'rm -f "$tmp" "$tmp.gz"' EXIT

test -w "$dir" || { echo "Sicherungsordner ${dir} ist nicht beschreibbar." >&2; exit 1; }
chmod 700 "$dir"

# Passwort kommt über MYSQL_PWD (nicht in der Prozessliste). Erst unkomprimiert schreiben, damit ein Fehler
# von mysqldump nicht in einer Pipe verloren geht.
mysqldump -h database -u root \
  --single-transaction --quick --routines --triggers --events \
  --hex-blob --default-character-set=utf8mb4 \
  --set-gtid-purged=OFF --no-tablespaces \
  --databases "${MYSQL_DATABASE:?MYSQL_DATABASE fehlt}" > "$tmp"

# mysqldump schreibt am Ende „-- Dump completed“; fehlt die Zeile, ist der Dump unvollständig.
tail -n 1 "$tmp" | grep -q '^-- Dump completed' || { echo "Dump unvollständig (Abschlusszeile fehlt)." >&2; exit 1; }
grep -q -m 1 '^CREATE TABLE' "$tmp" || { echo "Dump enthält keine Tabellen." >&2; exit 1; }

gzip -6 "$tmp"
gzip -t "$tmp.gz"
tmp="$tmp.gz"
sync "$tmp"
mv "$tmp" "${dir}/${name}"
sync "$dir"
trap - EXIT

echo "Gesichert: ${name} ($(du -h "${dir}/${name}" | cut -f1))"

# Rotation je Anlass, damit viele Deploys nicht die täglichen Dumps verdrängen.
# Der Zeitstempel im Namen sortiert chronologisch: neueste zuerst, alles ab Nummer keep+1 löschen.
printf '%s\n' "$dir"/momentary-db-*-"$reason".sql.gz | sort -r | tail -n +"$((keep + 1))" | while IFS= read -r old; do
  rm -f -- "$old"
  echo "Entfernt (Rotation): $(basename "$old")"
done

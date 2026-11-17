#!/bin/sh
# Start des Produktions-Images (app und Worker). Läuft als root, richtet ein und gibt die Rechte dann ab:
# Apache wechselt selbst zu www-data, alle anderen Befehle (Worker, Konsole) laufen direkt als www-data.
set -e

if [ "$(id -u)" = "0" ]; then
    # Schlüssel für API (OAuth2) und JWT liegen auf dem Server in config/ und sind nur lesend eingebunden.
    # Kopie im Container mit den Rechten, die league/oauth2-server verlangt (600, Besitzer www-data).
    for dir in oauth2 jwt; do
        if [ -d "/run/momentary-keys/$dir" ]; then
            mkdir -p "config/$dir"
            cp -a "/run/momentary-keys/$dir/." "config/$dir/"
            chown -R www-data:www-data "config/$dir"
            chmod 600 "config/$dir"/*
        fi
    done

    if [ "$1" = "apache2-foreground" ]; then
        # Datenverzeichnisse (Bind-Mounts vom Server) müssen www-data gehören. Nur Abweichungen korrigieren –
        # ein volles chown -R über das ganze Archiv würde jeden Start ausbremsen.
        for dir in storage/imports storage/app_uploads storage/jellyfin/uploads var/log var/transcription \
            var/whisper-models; do
            mkdir -p "$dir"
        done
        find storage var \( ! -user www-data -o ! -group www-data \) \
            -exec chown -h www-data:www-data {} +
        # SFTP-Uploads (Benutzer 1000, Gruppe www-data): die App muss sie verschieben dürfen
        chmod -R g+rwX storage/imports storage/app_uploads storage/jellyfin/uploads
    else
        exec setpriv --reuid=www-data --regid=www-data --init-groups docker-php-entrypoint "$@"
    fi
fi

exec docker-php-entrypoint "$@"

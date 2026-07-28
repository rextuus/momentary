# 📐 System-Dokumentation: Momentary & Sulu CMS Setup

## 🏗️ System-Architektur

```text
                           Internet / Browser
                                   │
                                   ▼
                        DNS (A-Records)
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────┐
│ IONOS VPS (217.154.91.97)                                   │
│                                                             │
│ Caddy Reverse Proxy                                         │
│ • Port 80 / 443                                             │
│ • Let's Encrypt SSL                                         │
└──────────────────────────────┬──────────────────────────────┘
                               │
                               │ WireGuard (wg0)
                               │ 10.0.0.0/24
                               ▼
┌─────────────────────────────────────────────────────────────┐
│ ThinkCentre "abyss" (Home Lab)                              │
│ WireGuard IP: 10.0.0.2                                      │
│                                                             │
│ Docker Network: momentary_momentary-network                 │
│ ├── Backend (Symfony)             → :8088                   │
│ ├── Hub Frontend (Symfony)        → :8089                   │
│ └── Jellyfin                      → :8096                   │
│                                                             │
│ Docker Network: oralcare-cms_default                        │
│ └── Sulu CMS (Zahnarztpraxis)      → :8080                  │
└─────────────────────────────────────────────────────────────┘
```

---

# 🌐 Domain- & Routing-Mapping

## Infrastruktur

| Komponente | Adresse |
|------------|----------|
| VPS Public IP | `217.154.91.97` |
| VPS WireGuard | `10.0.0.1` |
| ThinkCentre WireGuard | `10.0.0.2` |

## Domains

| Domain | Ziel | Dienst |
|---------|------|---------|
| `zahnarztpraxis-kaldauen.de` | `http://10.0.0.2:8080` | Sulu CMS |
| `oralcare-cms.de` | `http://10.0.0.2:8080` | Legacy Sulu CMS |
| `admin.momentary.wh-company.de` | `http://10.0.0.2:8088` | Momentary Backend |
| `momentary.wh-company.de` | `http://10.0.0.2:8089` | Momentary Hub Frontend |
| `media.momentary.wh-company.de` | `http://10.0.0.2:8096` | Jellyfin |

---

# ⚙️ VPS-Konfiguration

Datei:

```text
/etc/caddy/Caddyfile
```

```caddy
# Zahnarzt Praxis CMS
zahnarztpraxis-kaldauen.de, www.zahnarztpraxis-kaldauen.de {
    reverse_proxy 10.0.0.2:8080 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}

oralcare-cms.de, www.oralcare-cms.de {
    reverse_proxy 10.0.0.2:8080
}

# Momentary Frontend
momentary.wh-company.de {
    reverse_proxy 10.0.0.2:8089 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}

# Momentary Backend
admin.momentary.wh-company.de {
    reverse_proxy 10.0.0.2:8088 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-Proto {scheme}
    }
}

# Jellyfin
media.momentary.wh-company.de {
    reverse_proxy 10.0.0.2:8096 {
        header_up Host {host}
        header_up X-Real-IP {remote_host}
        header_up X-Forwarded-Proto {scheme}

        # Wichtig für Streaming und WebSockets
        flush_interval -1
    }
}
```

---

# 🐳 Docker Setup (Hub Frontend)

Pfad:

```text
/opt/momentary-hub/compose.yaml
```

```yaml
services:
  hub-app:
    build:
      context: .
      dockerfile: docker/php/Dockerfile

    container_name: momentary_hub_app
    restart: unless-stopped

    ports:
      - "${HUB_HOST_BINDING:-0.0.0.0}:${HUB_PORT:-8089}:80"

    environment:
      APP_ENV: ${APP_ENV:-prod}
      APP_SECRET: ${APP_SECRET}
      DEFAULT_URI: ${DEFAULT_URI}
      DATABASE_URL: ${DATABASE_URL}

      MOMENTARY_API_URL: ${MOMENTARY_API_URL:-http://app:80}
      MOMENTARY_API_TOKEN: ${MOMENTARY_API_TOKEN}

      JELLYFIN_INTERNAL_HOST: ${JELLYFIN_INTERNAL_HOST:-http://jellyfin:8096}
      JELLYFIN_PUBLIC_HOST: ${JELLYFIN_PUBLIC_HOST}
      JELLYFIN_API_KEY: ${JELLYFIN_API_KEY}

    volumes:
      - .:/var/www/html:rw
      - hub_var:/var/www/html/var

    networks:
      - momentary-network

volumes:
  hub_var:

networks:
  momentary-network:
    external: true
    name: momentary_momentary-network
```

---

# 🚀 Deployment

## Frontend Deployment

Datei:

```text
/opt/momentary-hub/deploy-hub.sh
```

```bash
#!/usr/bin/env bash
set -e

echo "=========================================="
echo "🖼️ STARTE FRONTEND DEPLOYMENT (HUB)"
echo "=========================================="

cd /opt/momentary-hub

echo "📥 1. Hole neuesten Git-Stand..."
git pull origin main

echo "🐳 2. Starte Docker-Container..."
docker compose up -d --build

echo "🔑 3. Setze Berechtigungen..."
docker exec momentary_hub_app \
    chown -R www-data:www-data /var/www/html/var

echo "📦 4. Composer installieren..."
docker exec momentary_hub_app \
    composer install --no-dev --optimize-autoloader

echo "🎨 5. Assets bauen..."
docker exec momentary_hub_app \
    php bin/console importmap:install || true

docker exec momentary_hub_app \
    php bin/console tailwind:build --minify || true

echo "🧹 6. Cache leeren..."
docker exec momentary_hub_app \
    php bin/console cache:clear --env=prod

docker exec momentary_hub_app \
    php bin/console cache:warmup --env=prod

echo "=========================================="
echo "✅ FRONTEND ERFOLGREICH DEPLOYT!"
echo "=========================================="
```

---

## Gesamt-Deployment

Datei:

```text
/opt/deploy-all.sh
```

```bash
#!/usr/bin/env bash
set -e

echo "=========================================="
echo "🚀 STARTE GESAMT-DEPLOYMENT"
echo "=========================================="

############################################################
# Backend
############################################################

echo "📦 [1/2] Backend"

cd /opt/momentary

git pull origin main || true

docker compose up -d --build

docker exec momentary-app-1 \
    composer install --no-dev --optimize-autoloader

docker exec momentary-app-1 \
    php bin/console sass:build

docker exec momentary-app-1 \
    php bin/console doctrine:schema:update --force

docker exec momentary-app-1 \
    php bin/console cache:clear --env=prod

docker exec momentary-app-1 \
    php bin/console cache:warmup --env=prod

############################################################
# Frontend
############################################################

echo "🖼️ [2/2] Hub Frontend"

/opt/momentary-hub/deploy-hub.sh

echo "=========================================="
echo "✅ DEPLOYMENT ERFOLGREICH"
echo "=========================================="
```

---

# 🛠️ Nützliche Befehle

## Caddy neu laden

```bash
sudo systemctl reload caddy
```

## Docker-Netzwerke anzeigen

```bash
docker network ls
```

## Offene Ports prüfen

```bash
ss -tulpn | grep -E '8080|8088|8089|8096'
```

## WireGuard Status

```bash
ip a show wg0
```

## Docker-Container anzeigen

```bash
docker ps
```

## Docker-Logs verfolgen

```bash
docker logs -f momentary_hub_app
```

## Docker-Compose neu bauen

```bash
docker compose up -d --build
```

---

# 📁 Verzeichnisstruktur

```text
/opt
├── deploy-all.sh
├── momentary
│   ├── compose.yaml
│   ├── docker/
│   └── ...
│
├── momentary-hub
│   ├── compose.yaml
│   ├── deploy-hub.sh
│   ├── docker/
│   └── ...
│
└── oralcare-cms
    ├── compose.yaml
    └── ...
```

---

# 🔄 Netzwerkübersicht

```text
Internet
    │
    ▼
Caddy (VPS)
    │
    ▼
WireGuard Tunnel
    │
    ▼
ThinkCentre (10.0.0.2)
    │
    ├── :8080 → Sulu CMS
    ├── :8088 → Backend
    ├── :8089 → Hub Frontend
    └── :8096 → Jellyfin
```
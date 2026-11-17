DC  = docker compose
PHP = $(DC) exec -T app php

.DEFAULT_GOAL := help
.PHONY: help up down sh test qa prod-secrets prod-up prod-down prod-logs prod-console prod-status prod-backup prod-reindex

help: ## Diese Hilfe
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-14s %s\n", $$1, $$2}'

# ---------- Entwicklung (compose.yaml, Code eingebunden) ----------

up: ## Entwicklung: alles bauen und starten
	$(DC) up -d --build

down: ## Entwicklung: alles stoppen (Daten bleiben)
	$(DC) down

sh: ## Entwicklung: Shell im app-Container
	$(DC) exec app bash

test: ## Entwicklung: PHPUnit (ohne slow-Gruppe)
	$(DC) exec -T app vendor/bin/phpunit

qa: ## Entwicklung: Code-Stil, PHPStan, Tests
	$(DC) exec -T app composer qa

# ---------- Produktion (auf dem Server ausführen, siehe documentation/deployment.md) ----------
PROD_ENV     ?= .env.prod.local
PROD_PROJECT ?= momentary
PROD_DC       = docker compose -p $(PROD_PROJECT) -f compose.prod.yaml --env-file $(PROD_ENV)
PROD_PHP      = $(PROD_DC) exec -T -u www-data app php
BACKUP_DIR   ?= backups/$(shell date +%F)

prod-secrets: ## Produktion: .env.prod.local und API-Schlüssel anlegen (überschreibt nichts)
	bin/prod-secrets $(PROD_ENV)

prod-up: ## Produktion: Image bauen, starten, Datenbank migrieren
	@test -f $(PROD_ENV) || ( echo "$(PROD_ENV) fehlt – zuerst make prod-secrets (documentation/deployment.md)." && exit 1 )
	$(PROD_DC) build
	$(PROD_DC) up -d --wait app
	$(PROD_PHP) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	$(PROD_DC) up -d --wait --remove-orphans

prod-down: ## Produktion: stoppen (Daten bleiben)
	$(PROD_DC) down

prod-logs: ## Produktion: Logs verfolgen (S=app für einen Dienst)
	$(PROD_DC) logs -f --tail=100 $(S)

prod-console: ## Produktion: Symfony-Konsole, z. B. make prod-console CMD="app:user:create du@example.de --role=admin"
	$(PROD_DC) exec -u www-data app php bin/console $(CMD)

prod-reindex: ## Produktion: Suchindex (Meilisearch) komplett neu aufbauen
	$(PROD_PHP) bin/console app:meilisearch:index-all

prod-status: ## Produktion: Zustand auf einen Blick (Container, Gesundheit, Speicher, Warteschlangen)
	@$(PROD_DC) ps --format 'table {{.Service}}\t{{.Status}}'
	@echo; echo "Gesundheit (das, was der VPS erreicht):"
	@$(PROD_DC) exec -T app curl -fsS http://127.0.0.1/healthz 2>/dev/null | sed 's/^/  /' || echo "  FEHLER"
	@echo
	@echo; echo "Speicher:"
	@$(PROD_DC) exec -T app df -hP /var/www/html/storage | tail -1 | awk '{print "  Festplatte: " $$(NF-3) " belegt, " $$(NF-2) " frei (" $$(NF-1) " voll)"}'
	@echo; echo "Warteschlangen (wartende Nachrichten):"
	@$(PROD_DC) exec -T rabbitmq rabbitmqctl -q list_queues name messages 2>/dev/null | sed 's/^/  /' || echo "  FEHLER"

prod-backup: ## Produktion: Datenbank, Jellyfin-Einstellungen und Schlüssel nach backups/<datum>/ sichern (Medien: siehe Doku)
	@mkdir -p $(BACKUP_DIR) && chmod 700 $(BACKUP_DIR) $(dir $(BACKUP_DIR))
	$(PROD_DC) exec -T database sh -c 'mysqldump -uroot -p"$$MYSQL_ROOT_PASSWORD" --single-transaction --routines --databases "$$MYSQL_DATABASE"' | gzip > $(BACKUP_DIR)/datenbank.sql.gz
	$(PROD_DC) exec -T jellyfin tar -czf - -C / config > $(BACKUP_DIR)/jellyfin-config.tar.gz
	tar -czf $(BACKUP_DIR)/schluessel.tar.gz $(PROD_ENV) config/oauth2 config/jwt
	@chmod 600 $(BACKUP_DIR)/*
	@echo "Sicherung in $(BACKUP_DIR)/"

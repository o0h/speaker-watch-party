COMPOSE = docker compose --project-name speaker-watch-party --env-file .env -f .runtime/compose.yml

.PHONY: build up down logs ps

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f

ps:
	$(COMPOSE) ps

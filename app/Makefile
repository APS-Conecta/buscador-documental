# Buscador Documental S1 harness. Everything runs in Docker; compose project `bd-s1` (repo-root compose.yaml).
# Nothing here touches other containers on the shared VPS.

IMG := bd-app-test
ROOT := $(CURDIR)/..
COMPOSE := docker compose -f $(ROOT)/compose.yaml
OCC := $(COMPOSE) exec -T --user www-data nextcloud php occ
NET := bd-s1_default
DOCKER := docker run --rm -v "$(CURDIR)":/app -w /app $(IMG)
DOCKER_NET := docker run --rm --network $(NET) -v "$(CURDIR)":/app -w /app -e BD_PG_HOST=db $(IMG)

.PHONY: build-test-image deps test-unit test-db up down clean instance-migrate

build-test-image:
	docker build -q -f Dockerfile.test -t $(IMG) . >/dev/null

deps: build-test-image
	$(DOCKER) composer install --no-interaction --no-progress

test-unit: deps
	$(DOCKER) vendor/bin/phpunit --configuration phpunit.xml --testdox

up:
	$(COMPOSE) up -d --wait db

# Needs the db service; creates and drops its own scratch databases.
test-db: deps up
	$(DOCKER_NET) vendor/bin/phpunit --configuration phpunit.db.xml --testdox

# Full instance: Nextcloud with the app enabled, migration applied through occ.
instance-migrate:
	$(COMPOSE) up -d --build --wait
	$(OCC) app:enable buscador_documental
	$(COMPOSE) exec -T db psql -U postgres -d nextcloud -tAc "select count(*) from pg_namespace where nspname='aps_search'"

down:
	$(COMPOSE) down

# Only the bd-s1 project: containers, volumes, network.
clean:
	$(COMPOSE) down -v --remove-orphans

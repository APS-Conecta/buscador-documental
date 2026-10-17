# Buscador Documental S1 harness. Everything runs in Docker; compose project `bd-s1` (repo-root compose.yaml).
# Nothing here touches other containers on the shared VPS.

IMG := bd-app-test
ROOT := $(CURDIR)/..
COMPOSE := docker compose -f $(ROOT)/compose.yaml
OCC := $(COMPOSE) exec -T --user www-data nextcloud php occ
NET := bd-s1_default
DOCKER := docker run --rm -v "$(CURDIR)":/app -w /app $(IMG)
DOCKER_NET := docker run --rm --network $(NET) -v "$(CURDIR)":/app -w /app -e BD_PG_HOST=db $(IMG)

.PHONY: instance-reset-schema test-e2e-formats test-e2e-one test-contract test-search test-canary build-test-image deps test-unit test-db up down clean instance-migrate

build-test-image:
	docker build -q -f Dockerfile.test -t $(IMG) . >/dev/null

deps: build-test-image
	$(DOCKER) composer install --no-interaction --no-progress

test-unit: deps
	$(DOCKER) vendor/bin/phpunit --configuration phpunit.xml --testsuite unit --testdox

test-contract: deps
	$(DOCKER) vendor/bin/phpunit --configuration phpunit.xml --testsuite contract --testdox

up:
	$(COMPOSE) up -d --wait db

# Needs the db service; creates and drops its own scratch databases.
test-db: deps up
	$(DOCKER_NET) vendor/bin/phpunit --configuration phpunit.db.xml --testdox

# Retrieve against the real schema (Nextcloud's node layer is scripted); same scratch-database approach as test-db.
test-search: deps up
	$(DOCKER_NET) vendor/bin/phpunit --configuration phpunit.search.xml --testdox

# Full instance: Nextcloud with the app enabled, migration applied through occ.
instance-migrate:
	$(COMPOSE) up -d --build --wait
	$(OCC) app:enable buscador_documental
	$(COMPOSE) exec -T db psql -U postgres -d nextcloud -tAc "select count(*) from pg_namespace where nspname='aps_search'"

# Re-applies the migration SQL on the dev instance after the SQL files changed (drops the index; dev data only).
instance-reset-schema:
	$(OCC) buscador_documental:schema:drop --force
	$(OCC) app:disable buscador_documental
	$(COMPOSE) exec -T db psql -U postgres -d nextcloud -c "delete from oc_migrations where app='buscador_documental'"
	$(OCC) app:enable buscador_documental

down:
	$(COMPOSE) down

# Only the bd-s1 project: containers, volumes, network.
clean:
	$(COMPOSE) --profile worker down -v --remove-orphans

# One scanned PDF through app + worker + OCR into the index (S1 plan, phase 2).
test-e2e-one:
	bash tests/e2e/one.sh

# Every tier-1/2 format and the hostile files, against the live stack (S2 plan, phase 2).
test-e2e-formats:
	bash tests/e2e/formats.sh

# The permission canary against the bd-s1 stack: one scanned PDF, real worker, six scenarios (S1 plan, phase 4).
test-canary:
	bash tests/canary/scenario.sh

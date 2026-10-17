# Buscador Documental S1 harness. Everything runs in Docker; compose project `bd-s1` (repo-root compose.yaml).
# Nothing here touches other containers on the shared VPS.

IMG := bd-app-test
ROOT := $(CURDIR)/..
COMPOSE := docker compose -f $(ROOT)/compose.yaml
OCC := $(COMPOSE) exec -T --user www-data nextcloud php occ
NET := bd-s1_default
DOCKER := docker run --rm -v "$(CURDIR)":/app -w /app $(IMG)
DOCKER_NET := docker run --rm --network $(NET) -v "$(CURDIR)":/app -w /app -e BD_PG_HOST=db $(IMG)

.PHONY: instance-reset-schema test-e2e-rbac test-e2e-logs bench-reconcile test-e2e-reconcile test-e2e-erasure test-e2e-events test-e2e-scope test-e2e-groupfolder test-e2e-formats test-e2e-one test-contract test-search test-canary build-test-image deps test-unit test-db up down clean instance-migrate

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

# The adversarial permission matrix with the real mechanisms (circles, team-folder ACL, expiry, moves, multi-mount, deletion).
test-e2e-rbac:
	bash tests/e2e/rbac.sh

# A-SEC-5: sentinels through the whole pipeline, then every log sink, with positive controls (S3 plan, phase 7).
test-e2e-logs:
	bash tests/e2e/logs.sh

# Reconciliation at scale: N synthetic files (default 300000), real Reconciler, EXPLAIN ANALYZE (S3 plan, phase 6).
bench-reconcile:
	bash ../spikes/s3/bench/scale.sh

# The nightly reconciliation repairs lost events, against the live stack (S3 plan, phase 6, A-OPS-3).
test-e2e-reconcile:
	bash tests/e2e/reconcile.sh

# Erasure on tag, trash and user deletion against the live stack (S3 plan, phase 5).
test-e2e-erasure:
	bash tests/e2e/erasure.sh

# Real Nextcloud events drive the index through the outbox (S3 plan, phase 4).
test-e2e-events:
	bash tests/e2e/events.sh

# The occ scope commands against the live stack (S3 plan, phase 2).
test-e2e-scope:
	bash tests/e2e/scope.sh

# A team folder (groupfolders >= 4, own storage): members find its files, outsiders do not (needs network once).
test-e2e-groupfolder:
	bash tests/e2e/groupfolder.sh

# Every tier-1/2 format and the hostile files, against the live stack (S2 plan, phase 2).
test-e2e-formats:
	bash tests/e2e/formats.sh

# The permission canary against the bd-s1 stack: one scanned PDF, real worker, six scenarios (S1 plan, phase 4).
test-canary:
	bash tests/canary/scenario.sh

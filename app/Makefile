# Buscador Documental S1 harness. Everything runs in Docker; compose project `bd-s1` (repo-root compose.yaml).
# Nothing here touches other containers on the shared VPS.

IMG := bd-app-test
ROOT := $(CURDIR)/..
COMPOSE := docker compose -f $(ROOT)/compose.yaml
OCC := $(COMPOSE) exec -T --user www-data nextcloud php occ
NET := bd-s1_default
DOCKER := docker run --rm -v "$(CURDIR)":/app -w /app $(IMG)
DOCKER_NET := docker run --rm --network $(NET) -v "$(CURDIR)":/app -w /app -e BD_PG_HOST=db $(IMG)

.PHONY: instance-reset-schema test-e2e-rbac test-e2e-logs bench-reconcile test-e2e-reconcile test-e2e-erasure test-e2e-events test-e2e-scope test-e2e-groupfolder test-e2e-formats test-e2e-one test-contract test-search test-canary build-test-image deps test-unit test-db up down clean instance-migrate node-image npm-install build-js test-js check-js-drift

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

test-e2e-vectors:
	bash tests/e2e/vectors.sh

test-e2e-egress:
	bash tests/e2e/egress.sh

test-e2e-restore:
	bash tests/e2e/restore.sh

test-e2e-ops:
	bash tests/e2e/ops.sh

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

# A-QUAL-2/3/4 (indicative) with a REAL embedding model: a throwaway Ollama (project bd-s4-ollama, no published port) pulls
# EVAL_MODEL on first use (about 1.2 GB for bge-m3), the Spanish corpus is embedded with the product's own worker client, and the
# product's SQL, glossary and fusion are evaluated in a scratch database. Results land in spikes/s4/results-s4/.
EVAL_PROFILE ?= bge-m3
EVAL_MODEL ?= bge-m3:567m
S4 := $(ROOT)/spikes/s4
OLLAMA := docker compose -f $(S4)/compose.yaml
eval-search: deps up
	@set -e; D=$$(mktemp -d /tmp/bd-s4-eval.XXXXXX); trap 'rm -rf $$D' EXIT; chmod 777 $$D; \
	$(OLLAMA) up -d --wait ollama; \
	$(OLLAMA) exec -T ollama ollama pull $(EVAL_MODEL); \
	docker run --rm -v "$$D":/out -v "$(S4)":/s4:ro -w /s4 -e PYTHONDONTWRITEBYTECODE=1 bd-s1-worker python3 corpus.py /out; \
	docker run --rm --network bd-s4-ollama_default -v "$$D":/out -v "$(S4)":/s4:ro -v "$(ROOT)/worker":/worker:ro -v "$(ROOT)/app/lib/Search/profiles.json":/profiles.json:ro \
	  -w /s4 -e PYTHONDONTWRITEBYTECODE=1 bd-s1-worker python3 embed_corpus.py /out http://ollama:11434 $(EVAL_PROFILE); \
	docker run --rm --network $(NET) -v "$(CURDIR)":/app -v "$$D":/eval -w /app -e BD_PG_HOST=db -e BD_EVAL_DIR=/eval -e BD_EVAL_OUT=/eval/result.json $(IMG) \
	  vendor/bin/phpunit --configuration phpunit.eval.xml --testdox; \
	mkdir -p $(S4)/results-s4; cp "$$D/result.json" "$(S4)/results-s4/eval_search_$(EVAL_PROFILE).json"; \
	cat "$(S4)/results-s4/eval_search_$(EVAL_PROFILE).json"

# Frontend (ADR-0016): Node 24 in a pinned image; node_modules lives in a named volume so the repo stays clean.
NODE_IMG := bd-app-node
NODE := docker run --rm -v "$(CURDIR)":/app -v bd-app-node-modules:/app/node_modules -w /app $(NODE_IMG)

node-image:
	docker build -q -f Dockerfile.node -t $(NODE_IMG) . >/dev/null

npm-install: node-image
	$(NODE) npm install --no-audit --no-fund

build-js: npm-install
	$(NODE) sh -c 'npm run build && npm run size'

test-js: npm-install
	$(NODE) npm test

# CI: the committed js/ and css/ must be what the sources build to.
check-js-drift: build-js
	@git -C $(ROOT) diff --exit-code --stat -- app/js app/css || (echo "js/ or css/ is not what the sources build to: run make -C app build-js and commit" >&2; exit 1)
	@test -z "$$(git -C $(ROOT) ls-files --others --exclude-standard -- app/js app/css)" || (echo "untracked build output" >&2; exit 1)

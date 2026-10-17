# Worker tests run in the worker image (no Python deps on the host). The spec is mounted read-only from the app.
IMG := bd-s1-worker
.PHONY: image test test-sandbox test-laws
image:
	docker build -q -t $(IMG) . >/dev/null
test: image
	docker run --rm -v "$(CURDIR)/../app/openapi":/spec:ro -e BD_SPEC=/spec/worker-v1.yaml $(IMG) python3 -m pytest -q tests

# A-SAFE-2: the isolation properties, checked inside the hardened parser container (needs only the compose project bd-s1).
test-sandbox: image
	docker compose -f ../compose.yaml --profile worker run --rm --no-deps --entrypoint python3 parser sandbox_selftest.py

# Chunker against the six real Chilean laws (local fixture: they are not in this repository). LEGAL defaults to the org checkout.
LEGAL ?= /root/aps-conecta/Databases/Legal
test-laws: image
	docker run --rm -v "$(CURDIR)":/w:ro -v "$(LEGAL)":/legal:ro -w /w -e BD_LEGAL_DIR=/legal -e PYTHONDONTWRITEBYTECODE=1 $(IMG) python3 -m pytest -q -p no:cacheprovider tests/test_chunk.py -k real_laws

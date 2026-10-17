# Worker tests run in the worker image (no Python deps on the host). The spec is mounted read-only from the app.
IMG := bd-s1-worker
.PHONY: image test test-sandbox
image:
	docker build -q -t $(IMG) . >/dev/null
test: image
	docker run --rm -v "$(CURDIR)/../app/openapi":/spec:ro -e BD_SPEC=/spec/worker-v1.yaml $(IMG) python3 -m pytest -q tests

# A-SAFE-2: the isolation properties, checked inside the hardened parser container (needs only the compose project bd-s1).
test-sandbox: image
	docker compose -f ../compose.yaml --profile worker run --rm --no-deps --entrypoint python3 parser sandbox_selftest.py

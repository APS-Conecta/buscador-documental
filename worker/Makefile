# Worker tests run in the worker image (no Python deps on the host). The spec is mounted read-only from the app.
IMG := bd-s1-worker
.PHONY: image test
image:
	docker build -q -t $(IMG) . >/dev/null
test: image
	docker run --rm -v "$(CURDIR)/../app/openapi":/spec:ro -e BD_SPEC=/spec/worker-v1.yaml $(IMG) python3 -m pytest -q tests

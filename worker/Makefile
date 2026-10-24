# Worker tests run in the worker image (no Python deps on the host). The spec is mounted read-only from the app.
IMG := bd-s1-worker
.PHONY: image test test-sandbox test-laws test-glossary test-image eval-ocr
image:
	docker build -q -t $(IMG) . >/dev/null
test: image
	docker run --rm -v "$(CURDIR)/../app/openapi":/spec:ro -e BD_SPEC=/spec/worker-v1.yaml $(IMG) python3 -m pytest -q tests

# The image runs as the non-root worker and owns its work directory (issue 43): a fresh named volume at /work is
# writable with no uid fixup at the mount — what the suite's worker and parser containers get.
test-image: image
	docker volume rm -f bd-work-probe >/dev/null 2>&1 || true
	docker run --rm -v bd-work-probe:/work $(IMG) python3 -c "import os, pathlib; assert os.getuid() == 10001, os.getuid(); pathlib.Path('/work/probe').touch(); print('non-root worker writes a fresh /work volume')"
	docker volume rm bd-work-probe >/dev/null

# A-SAFE-2: the isolation properties, checked inside the hardened parser container (needs only the compose project bd-s1).
test-sandbox: image
	docker compose -f ../compose.yaml --profile worker run --rm --no-deps --entrypoint python3 parser sandbox_selftest.py

# Chunker against the six real Chilean laws (local fixture: they are not in this repository). LEGAL defaults to the org checkout.
LEGAL ?= /root/aps-conecta/Databases/Legal
test-laws: image
	docker run --rm -v "$(CURDIR)":/w:ro -v "$(LEGAL)":/legal:ro -w /w -e BD_LEGAL_DIR=/legal -e PYTHONDONTWRITEBYTECODE=1 $(IMG) python3 -m pytest -q -p no:cacheprovider tests/test_chunk.py -k real_laws

# The glossary builder (tools/build_glossary.py) and its fixture, run in the worker image like the other Python tests.
test-glossary: image
	docker run --rm -v "$(CURDIR)/../tools":/t:ro -w /t -e PYTHONPATH=/t -e PYTHONDONTWRITEBYTECODE=1 $(IMG) python3 -m pytest -q -p no:cacheprovider tests

# A-QUAL-1: pooled CER/WER of the page-by-page pipeline against the S0 synthetic corpus (regenerated, deterministic).
# PAGES is the target page count of the corpus; the result lands in spikes/s2/results-s2/ocr_eval.json.
PAGES ?= 120
eval-ocr: image
	@set -e; docker build -q -f Dockerfile.evalgen -t bd-s2-evalgen . >/dev/null; \
	D=$$(mktemp -d /tmp/bd-s2-corpus.XXXXXX); trap 'rm -rf $$D' EXIT; \
	docker run --rm --user root -v "$$D":/out -v "$(CURDIR)/../spikes/s0-harness/corpus":/gen:ro -w /gen -e PYTHONDONTWRITEBYTECODE=1 bd-s2-evalgen \
	  python3 gen_corpus.py --seed 1 --pages $(PAGES) --out /out --procs 2; \
	mkdir -p "$(CURDIR)/../spikes/s2/results-s2"; \
	docker run --rm --user root -v "$$D":/corpus:ro -v "$(CURDIR)/../spikes/s2/results-s2":/res -e PYTHONDONTWRITEBYTECODE=1 $(IMG) \
	  python3 eval_ocr.py /corpus /res/ocr_eval.json

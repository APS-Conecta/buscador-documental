# Buscador S0 measurement harness. Throwaway; see buscador/02-performance.md.
# Compose project `bd-spike`. Nothing here touches other containers on the shared VPS.

COMPOSE := docker compose -f compose.yaml
OCC := $(COMPOSE) exec -T --user www-data nextcloud php occ
PSQL := $(COMPOSE) exec -T db psql -U nextcloud -d nextcloud -v ON_ERROR_STOP=1
WORKER := $(COMPOSE) exec -T worker

.PHONY: up seed corpus load load-dry measure measure-search measure-cold measure-ocr measure-recheck report down clean versions

up:
	mkdir -p results corpus/out
	$(COMPOSE) up -d --build --wait
	$(OCC) app:install groupfolders || $(OCC) app:enable groupfolders
	# Restart after enabling: APCu caches the enabled-app state (farmacia Makefile).
	$(COMPOSE) restart nextcloud
	$(COMPOSE) up -d --wait
	$(MAKE) versions
	@echo "Nextcloud at http://127.0.0.1:8190 (admin / admin)"

versions:
	@{ echo '{'; \
	  echo ' "postgres": "'$$($(PSQL) -tAc 'show server_version' | tr -d '\r')'",'; \
	  echo ' "pgvector": "'$$($(PSQL) -tAc "select extversion from pg_extension where extname='vector'" | tr -d '\r')'",'; \
	  echo ' "nextcloud": "'$$($(OCC) status --output=json | python3 -c 'import sys,json;print(json.load(sys.stdin)["versionstring"])')'",'; \
	  echo ' "tesseract": "'$$($(WORKER) sh -c 'tesseract --version 2>&1 | head -1' | tr -d '\r')'",'; \
	  echo ' "ocrmypdf": "'$$($(WORKER) ocrmypdf --version | tr -d '\r')'",'; \
	  echo ' "python_libs": "'$$($(WORKER) pip list --format=freeze 2>/dev/null | grep -iE '^(numpy|onnxruntime|tokenizers|pillow|reportlab|psycopg|pypdf|rapidfuzz)==' | tr '\n' ' ')'",'; \
	  echo ' "tessdata_sha256": "'$$($(WORKER) sh -c 'cd /work/models 2>/dev/null && sha256sum tessdata_*/spa.traineddata | tr "\n" " "')'"'; \
	  echo '}'; } > results/versions.json
	@cat results/versions.json

seed:
	bash seed/seed_nc.sh
	bash seed/verify_seed.sh
corpus:
	$(WORKER) python3 corpus/gen_corpus.py --seed 1 --pages 6000 --out corpus/out/functional --procs 4
	$(WORKER) python3 corpus/gen_gold.py --seed 1 --corpus corpus/out/functional --out corpus/out/functional/gold.json
	$(WORKER) sh -c 'cd corpus && python3 selfcheck.py out/functional out/functional/gold.json'
	bash corpus/determinism_check.sh
load:
	$(WORKER) python3 bench/load.py --chunks 3000000 --tag full

load-dry:
	$(WORKER) python3 bench/load.py --chunks 100000 --tag dry
# Order matters: latency runs first (quiet host), then OCR/embedding (CPU heavy), then the Nextcloud recheck.
# Hours in total on the reference box; every target writes JSON into results/.
measure: measure-search measure-ocr measure-recheck
	$(WORKER) python3 bench/check_results.py

measure-search:
	$(WORKER) python3 bench/m6_keyword.py                       # uncapped baseline (slow: head terms)
	$(WORKER) python3 bench/m6_keyword.py --cap 10000           # with the head-term pre-limit
	$(WORKER) python3 bench/m10_headterm.py
	$(WORKER) python3 bench/m7_hybrid.py
	$(WORKER) python3 bench/prep_recall_corpus.py --chunks 30000
	$(WORKER) python3 bench/m8_recall.py --schema bd_recall --queries table --n 200
	$(WORKER) python3 bench/m8_recall.py --schema bd_spike --queries random --n 60

measure-cold:                                                   # shared_buffers cold only (OS cache untouched on a shared host)
	for i in 1 2 3; do $(COMPOSE) restart db && $(COMPOSE) up -d --wait db && $(WORKER) python3 bench/m6_keyword.py --cold 60; done

measure-ocr:
	$(WORKER) python3 bench/m3_chunks.py
	$(WORKER) python3 bench/m4_embed.py
	$(WORKER) python3 bench/m1_ocr.py
	$(WORKER) python3 bench/m2_cer.py

measure-recheck:
	bash bench/m9_run.sh

report:
	$(WORKER) python3 bench/report.py

down:
	$(COMPOSE) down

# Removes only the bd-spike project (containers, volumes, built images).
clean:
	$(COMPOSE) down -v --rmi local

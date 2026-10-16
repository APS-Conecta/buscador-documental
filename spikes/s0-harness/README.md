# Buscador S0: measurement harness (throwaway)

Lives in `spikes/s0-harness/` of the product repo. Run every command from this directory. `results/` (git-ignored) is where a run writes; `results-s0/` is the committed snapshot of the S0 run of 2026-10-16 (JSON per measurement, `summary.md`, `versions.json`).

Measures the numbers in `buscador/02-performance.md` and decides ADR-0001. Not product code: schema `bd_spike`, plain SQL, nothing here is reused by S1.

Reference box: 8 cores, 31 GB RAM, >= 60 GB free disk, no GPU; host needs Docker with Compose v2, bash and python3 (only to parse a version string). `make load` aborts if free disk is under 4x its estimate. Everything runs in the compose project `bd-spike` (loopback only, Nextcloud on 127.0.0.1:8190, admin/admin, throwaway). `make clean` removes only that project. On a shared host never stop or kill anything you did not start.

```
make up              # db (pg17 + pgvector 0.8.7), Nextcloud 34.0.4 + groupfolders, worker (tesseract, ocrmypdf, onnxruntime)
make seed            # 200 groups, 100 groupfolders, users F1 (u_narrow) / F2 (u_admin200), F3 ACL tree + 50-hit fixture (~4 min cold)
make corpus          # 6 003-page synthetic corpus + 200-query gold set + self-checks (~7 min, 3 GB)
make load            # 3M chunks, indexes, sizes, WAL (m5, m11; ~20 min); `make load-dry` for 100k
make measure         # m1-m10 (several hours; the uncapped keyword baseline is the slow part); targets: measure-search, measure-ocr, measure-recheck, measure-cold
make report          # results/summary.md from results/*.json
make clean           # down -v --rmi local for this project only
```

Layout: `corpus/` generators and checks, `seed/` Nextcloud fixtures, `bench/` one script per measurement (JSON into `results/`), `db/` image and schema, `worker/` image. Seeds are fixed; the corpus is byte-identical for a seed (`corpus/determinism_check.sh`). Results carry the host load average because the VPS is shared; repeat runs and report the median and spread.

Known limits: synthetic text (Zipf head terms are harsher than natural Spanish), random vectors for 3M latency, recall from 30k real e5-small embeddings, concurrency generated on the database host, PostgreSQL 17 (pg18 image exists).

# Open questions and carried assumptions

| # | Question | Default used | Owner | Needed by |
|---|---|---|---|---|
| 1 | Who owns the starter synonym/acronym list (APS, CESFAM, REM, RUT, folios…)? 50-100 real terms | static versioned file, ~60 terms drafted | TBD (user) | S4 |
| 2 | De-identified real folder for S0 and its scanned share | synthetic Spanish corpus | user | S0 (re-run on real data later) |
| 3 | Concurrent searchers per clinic | 5-10; S0 modelled 5, 10 and 20 | user | S0 (closed for the load model) |
| 4 | Grace period for trash/old versions | 30 days | counsel/user | S3 |
| 5 | Mode «Por significado» while index incomplete | shown disabled with progress | decided | U0 |
| 6 | App id (P2 S6); the repo name is fixed: `APS-Conecta/buscador-documental` | app id placeholder `buscador` | user | before the app code (S1) |
| 7 | Counsel: Ley 21.719 date, legal basis, DPIA owner, audit retention (90 d proposed) | opt-in scope | counsel | before real-data install |
| 8 | Does the documentation repo accept a PR for these pages now (it is in its second round)? | drafts in `docs-site/` | user | first `/bd-docs` push |
| 9 | Unverified technical items to recheck in research: PDF.js `#page=N`, `core:image2text:ocr` in NC 34, OCRmyPDF flags, pgvector `hnsw.iterative_scan` version, Tesseract throughput | S0 verified: pgvector 0.8.7 `iterative_scan`, OCRmyPDF 14 flags, Tesseract throughput; still open: PDF.js, image2text | S2/S6 research | per slice |
| 10 | Define the minimum supported box (cores, RAM, disk) so ADR-0001's "fits the minimum box" can be evaluated; S0 numbers: 14.7 GB database, ~7 GB hot set, WAL 13.6 GB during builds, backfill ~71 h on 8 cores | none (not evaluable) | user | S1 (before the Postgres sizing in preflight) |
| 11 | Recheck design for A-PERF-3 (50 hits p95 < 300 ms): S0 measured 330-405 ms with a per-request folder cache | per-parent batching from the file cache | S1 | before S1 exits |
| 12 | Push the S0 harness (Makefile, compose, generators, bench scripts, ~2 MB without results) to the repo as `spikes/s0-harness/`? The accepted S0 design kept it local | local only | user | S0 commit |
| 13 | Licence and third-party notices for the product repo (harness uses Tesseract data, multilingual-e5-small ONNX int8 from Hugging Face `Xenova/multilingual-e5-small`, pgvector, Nextcloud images); org apps are AGPL-3.0-or-later | none chosen: no LICENSE file pushed | user | before the first release |

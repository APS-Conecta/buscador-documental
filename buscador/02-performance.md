# 02. Performance budget and capacity

Status: draft v3, amended by S0 (2026-10-16). Numbers marked **[S0]** were measured on the synthetic corpus on one shared 8-core box and are indicative; S4 and S7 re-measure with the real chunker/embedder. Unmarked numbers are still targets or hypotheses. The filled sheet is `capacity-sheet.md`.

## Latency budget (p95, 1M-page equivalent, target box)
| Stage | Keyword (Exacta) | Hybrid (Mixta) |
|---|---|---|
| Principals + request overhead | 30 ms | 30 ms |
| Query embedding (warm, CPU small profile) | n/a | <= 100 ms |
| SQL: FTS top-50 / HNSW top-50 under prefilter / RRF | <= 300 ms | <= 600 ms |
| Group by file, pick best chunk | 20 ms | 20 ms |
| Live recheck (top ~20 docs, batched; refill only if short) | <= 150 ms | <= 150 ms |
| Snippets + JSON | 50 ms | 50 ms |
| **Sum (budget)** | ~0.55 s (limit 1 s) | ~0.95 s (limit 2 s) |
The recheck budget (150 ms) covers ~20 documents; A-PERF-3 measures the harder 50-hit case (300 ms) as headroom. S0 must measure `isReadable`/`getById` for 50 hits on a groupfolder fixture, **including the ancestor walk for the exclusion tag** and share-attribute checks. If p95 > 300 ms: cache readable file ids per (uid, request-batch) or batch by parent folder; document the design before S1 exits.
**[S0] Result: not met by the simple designs.** 50 hits through `getById` + `isReadable` + ancestor tag walk + share attribute, one fresh PHP process per call: p95 760-820 ms (check only, naive per-hit), 330-405 ms with a per-request cache of "folder or ancestor excluded" (about 6-8 ms per hit); FS setup for the user's mounts adds ~100-160 ms (a real request already pays it). For the 20 documents of the stage budget this is ~130-160 ms, borderline against 150 ms. **Design needed before S1 exits** (A-PERF-3; delivered in S1, see the next paragraph): resolve parent folders once per batch from the file cache, evaluate ACL/tag/share attributes per parent, and avoid per-hit `getById`; the S1 recheck class must be measured against this fixture (50 hits: 25 readable, 10 via download-disabled share, 5 ACL-denied, 10 tag-excluded).
**[S1] Result (corrected probe, five variants, `spikes/s1/results-s1/`):** the S0 probe was corrected (download-disabled hits survive title-only, expired shares and "any readable node" enforced, ancestors walked to the storage root). Worst-user check p95 for 50 hits: naive 644 ms, folder memo 466 ms, memo + bulk tag lookup 381 ms (the shipped design: 377 ms measured on the real classes), `Folder::search`-constrained 308 ms; FS setup is 105-176 ms on top. All variants decided every hit like the naive reference. **A-PERF-3 not met**; ADR-0010 (accepted 2026-10-17) waives it with these numbers, keeps the 150 ms / ~20 documents stage budget, and leaves the search-constrained variant for S4.
**Page size effect:** the budget above is for the default page of 25. The recheck and snippet cost scale with the page, so 50 and 100 are measured separately (A-PERF-5: keyword p95 < 1.5 s and hybrid p95 < 2.5 s at 100 per page, `[M0]`); if they miss, recheck runs on parallel batches or the cap on page size drops to 50. Deep paging uses keyset pagination on (sort key, `file_id`) instead of `OFFSET`; relevance order pages over the cached ranked candidate list (top 1 000) for the request.
**[S0] Measured SQL stage at 3M chunks (p95, page 25, warm):** keyword 0.22 s at 1-10 searchers and 0.54 s at 20 *with the head-term pre-limit below*; hybrid 0.31 s at 1 searcher, 0.82-0.89 s at 20. Without the pre-limit keyword p95 is 6.8 s at 1 searcher and 37 s at 20 (head terms). Page 50/100 add nothing measurable to the SQL stage (keyword 0.22 s, hybrid 0.35 s at 5 searchers); the recheck dominates (see below).
Unified-search bar: debounce 250 ms, top 5, Exacta only, over-fetch 15, no model call.

## Throughput (to measure, per box)
| Metric | Unit | How |
|---|---|---|
| OCR | s/page, per thread | Tesseract `spa+eng` on the synthetic scanned set, 300 dpi |
| Image-only share | % pages | corpus sample |
| Chunks per page | count | chunker on corpus (assumed 2-3) |
| Embedding | tokens/s per profile, threads=N | ONNX on the box, batch 32 |
| Index build | min per 100k chunks | GIN + HNSW (after bulk load, parallel workers, `maintenance_work_mem`) |
| Backfill wall-clock | hours | derived: (pages x OCR s + tokens / tps) / usable cores, then / night-window fraction |
Rule: no hour figure is published in docs until it comes from this sheet. Earlier 70/170 h figures are withdrawn (embedding rates they imply, ~28k and ~3k tok/s, were not measured).
**[S0]** OCR (Tesseract 5.3, `spa+eng`, 300 dpi, `OMP_THREAD_LIMIT=1`): `tessdata_fast` 2.3 s/page on one process and 2.8 s/page/core with 8 processes (2.9 pages/s); `tessdata_best` 4.2 s and 5.3 s/page/core (CER 0.07 % vs 0.11 % on clean synthetic pages, so `fast` is the default). Through `ocrmypdf` a page costs ~6 s (`--force-ocr`) or ~9.5 s with `--deskew --rotate-pages`; a text-layer page under `--skip-text` ~0.4 s. Embedding (`multilingual-e5-small`, int8, batch 32): 2.2k / 4.5k / 7.6k / 9.1k tokens/s at 1 / 2 / 4 / 8 threads; ~285 tokens per 350-token-target chunk, 3.0 chunks per page. Image-only share 46.8 % of the synthetic corpus (real share is unknown). **Backfill estimate for 1M pages on 8 cores, box dedicated: ~71 h (OCR 359 core-h + embedding 208 core-h), ~9 nights of 8 h; keyword lane alone ~45 h.** Indicative: synthetic clean scans are the best case.
**[S0] OCR policy findings:** `--skip-text` keeps a fake or garbage text layer (CER ~0.96-0.97 against the page content), so pages whose layer is not trusted need `--redo-ocr` or `--force-ocr` (CER 0.07-3.6 %); a visible garbage layer leaves ~3.5 % residual error either way. Photographed pages without `--deskew --rotate-pages` can fail completely (one of six, CER 0.90); with it CER 0.07 %. A page that already has text exits 6 under default flags and is counted `skipped`, not failed.

## Storage (3M chunks, formulas)
- halfvec 384-d: 3M x 384 x 2 B = 2.3 GB; 1024-d: 6.1 GB; plus HNSW graph overhead (~30-100% of vector size, measure) and the `tsv` GIN (measure).
- Count: page text + chunk text (+10-15 % overlap duplication) + stored `tsv` (~0.5-1x text) + GIN (~0.3-0.5x) + `pg_trgm` on names + WAL during backfill/HNSW builds. HNSW builds need `maintenance_work_mem` sized to the graph. Decide whether `aps_search` is excluded from routine NC DB dumps and backed up separately. TOAST compression applies.
- Index builds need extra `maintenance_work_mem` and temporary disk; plan 2x headroom for the vector table during a re-embed generation.
- **[S0] Measured at 3M chunks (150 words, ~1.07 kB text each):** database 14.7 GB = 4.9 kB/chunk: `chunk` table 7.5 GB (text + stored `tsv` ~1.18 kB/chunk), tsv GIN 0.75 GB, `chunk_vec` 2.7 GB, HNSW 3.5 GB (m=16, ef_construction=64; 1.3x the vector table), small indexes < 0.2 GB. Build times: load 3 min (4 procs), GIN 3 min, HNSW 11 min with `maintenance_work_mem` 8 GB (the parallel build allocates that much shared memory, so a container needs a matching `/dev/shm`; Docker's default of 64 MB aborts it), 0.47 min per 100k chunks overall. WAL 13.6 GB during load and builds. Hot working set for fast queries: HNSW + GIN + vectors ~7 GB.

## Caps (set from S0, stored in config, enforced in worker)
Max file size, max pages, max pixels, zip expansion ratio, per-file timeout, xlsx rows/cells. Lease length >= worst page time x batch; checkpoint after each page so large scans resume.
| Cap | Value | Basis |
|---|---|---|
| Max file size | 250 MB | a 300 dpi JPEG scan page is ~0.7 MB [S0]; 250 MB is ~350 such pages |
| Max pages per file | 300 | worst measured page 9.6 s (deskew+rotate): 300 pages = 48 min on one core |
| Max pixels per page | 50 MP | A4 at 300 dpi is 8.7 MP; 50 MP covers A2 at 300 dpi and sits below Pillow's decompression-bomb limit (89 MP) |
| Per-page OCR timeout | 180 s | `--tesseract-timeout 180`; no sample came close (max 9.6 s) |
| Per-file timeout | min(3600 s, 12 s x pages + 60 s) | 1.25x the worst measured page time per page |
| Lease length | per-file timeout + 60 s, renewed after each page | checkpoint per page |
| Zip expansion ratio | 100:1 and 1 GB total uncompressed | policy default, **not measured in S0** (no archive corpus); S2 proves it with the zip-bomb tests |
| xlsx rows/cells | 1 000 000 cells | policy default, **not measured in S0**; S2 measures extraction time |
A file over a cap ends `skipped(reason)` with the reason visible to the administrator (A-SAFE-3).

## Known risk: head terms (confirmed by S0)
`ts_rank` must score every match before the top 50, and a common Spanish term can match hundreds of thousands of chunks, each needing a detoasted `tsv` and the `file_acl` join. The gold set must therefore include worst-case head-term queries for a broad-principal user, and S0 measures them. **[S0] Measured:** with the generator's Zipf head, terms match 33-99.9 % of 3M chunks and keyword p95 is 9.7 s for those terms (6.8 s over the gold set, 37 s at 20 searchers with ~1 % timeouts); even terms matching 10-30 % of chunks take p95 5.3 s; accent-free queries of common accented words behave the same. Rare, 2-term, 3-term and phrase queries are fine (p95 < 0.3 s at one searcher). The **candidate pre-limit** (rank only the newest 10 000 ACL-passing matches, `ORDER BY chunk_id DESC LIMIT 10000` before `ts_rank`) brings head terms to p95 0.2 s and the whole gold set to 0.22-0.54 s, at the cost of ranking only a recency-biased subset. **Required design element in S1/S4**, with a relevance gate: S4 must show (A-QUAL-2, real text) that the cap does not hurt recall@10, and the "demasiados resultados" path stays available. Mitigations in order: add selective terms (AND relaxation only after a strict pass), cap candidates with a GIN-supported pre-limit (recency/filename boost), a "demasiados resultados, afine su búsqueda" path, and as last resort a denormalised ACL-aware column.

## Efficiency rules
1. Generated `tsvector` column + GIN; `ts_rank` (not `ts_rank_cd`) over the prefiltered set.
2. HNSW built after bulk load. Requires **pgvector >= 0.8** (`halfvec` needs >= 0.7, `hnsw.iterative_scan` >= 0.8; pinned in preflight and the compose job, both items to verify in S0 research). `chunk_vec_<gen>` carries `file_id` so the ACL filter can sit next to the index scan. Per request: `SET LOCAL hnsw.iterative_scan = relaxed_order`, `hnsw.max_scan_tuples`, and `hnsw.ef_search >= LIMIT`; re-sort because relaxed order is approximate. Test the shortfall case (narrow user returns fewer than K); define the exact-scan switch by accessible-file count. Recall tested for 1-user vs 200-group admin.
**[S0] Measured, two corpora, HNSW forced and the plan verified with EXPLAIN for every config.** (a) 30k real e5-small chunks (Wikipedia-es), narrow user reads 1.6 % of files; (b) 3M synthetic clustered vectors (2 000 topic clusters, 3 per file, folders independent of clusters), 60 queries, exact top-10 as ground truth. Random vectors were rejected for recall (no neighbour structure: recall 1 % even unfiltered). Absolute recall in (b) is low because same-cluster neighbours are near-ties; read it as relative, not as an estimate for real embeddings.
| Setting (narrow user, 1.6 % of files) | recall@10 (a) 30k real | recall@10 (b) 3M clustered | rows < 50 |
|---|---|---|---|
| `iterative_scan = off` | 0.16 | 0.14 | 100 % (a), 100 % (b) |
| `strict_order`, `max_scan_tuples` 20 000 | 0.91 | 0.57 | 0 |
| `relaxed_order`, `max_scan_tuples` 5 000 | 0.91 | 0.70 | 0 (a), 15 % (b) |
| `relaxed_order`, `max_scan_tuples` 20 000 | 0.9995 | 0.81 | 0 |
| `relaxed_order`, 100 000 | 0.9995 | 0.82 | 0 |
Unfiltered and 200-group admin (reads every file, so identical by construction): (a) 0.959 / 0.987 / 0.994 and (b) 0.78 / 0.85 / 0.89 at `ef_search` 100 / 200 / 400. Iterative scan is mandatory, `relaxed_order` clearly beats `strict_order` for narrow users, and 20 000 is the smallest of the tested values (5 000, 20 000, 100 000) that avoids shortfall at 3M; no finer sweep was run. Caveat on (a): 20 000 tuples covers two thirds of a 30k table, so (a) overstates the narrow-user result; (b) is the figure to trust.
**Narrow-user ceiling:** on (b) the ANN result for the narrow user stays at ~0.81 whatever `ef_search`/`max_scan_tuples` (unfiltered keeps rising to 0.89), i.e. 4 points below unfiltered at `ef_search` 200 and 8 below at 400. A-QUAL-3's 5-point rule therefore holds only with the exact-scan switch below, which gives narrow users recall 1.0.
**Settings to ship as defaults:** `relaxed_order`, `max_scan_tuples` 20 000, `ef_search` 200, re-sort in a MATERIALIZED CTE (`ORDER BY d + 0`). The hybrid SQL stage is the same under strict and relaxed (p95 0.31 vs 0.32 s at 1 searcher; both vector legs verified as HNSW + nested loop to `file_acl`), so the architecture text `SET LOCAL hnsw.iterative_scan = relaxed_order` stands once the re-sort is added.
**Exact-scan switch (3M chunks, 50 000 files, clustered vectors):**
| readable files | exact p50 ms (recall 1.0) | ANN p50 ms | ANN recall@10 | ANN rows < 50 |
|---|---|---|---|---|
| 5 / 20 | 0.8 / 1.3 | 128 / 100 | 0.08 / 0.15 | 100 % |
| 100 | 4.6 | 95 | 0.46 | 57 % |
| 500 | 21.7 | 64 | 0.79 | 0 |
| 2 000 | 86.5 | 25 | 0.91 | 0 |
| 10 000 | 6 062 | 32 | 0.82 | 0 |
**Rule: use the exact path (btree on `file_id`, then sort) when the user can read fewer than ~2 000 files (~120k chunks, ~4 % of this corpus, <= ~90 ms); ANN above.** The narrow fixture user (~780 files) is on the exact path, which is why its effective recall is 1.0. Re-measure with real embeddings in S4 (the crossover moves with the share of the corpus a user can read, so define it on accessible chunks, not files).

3. Only the final page of documents gets `ts_headline`.
4. Content-hash cache avoids re-OCR/re-embed of identical files and moved files.
5. Ingestion lanes yield to the query-embed process; backfill honours a CPU/IO throttle and a night window setting.
6. Page text is stored once; OCR runs only on pages without a usable text layer.

**[S0] Postgres version:** measured on PostgreSQL 17.11 + pgvector 0.8.7. `pgvector/pgvector:0.8.7-pg18-bookworm` also exists, so the sibling apps' PostgreSQL 18 is available for S1; S4/S7 re-measure on 18 (no behaviour change expected, not verified).

## Capacity sheet template (`capacity-sheet.md`, filled in S0)
box (cores, RAM, disk, GPU) | corpus (pages, scanned %, avg pages/file) | OCR s/page | tokens/s per profile | chunks/page | storage GB | FTS p50/p95 | hybrid p50/p95 | `isReadable` x50 p95 | recall@10 (K, S, H) | filtered recall | backfill hours (night window) | verdict + profile recommended

## Engine decision rule (ADR-0001)
Postgres is kept if on the 200-query gold set at 1M-page equivalent: keyword p95 < 1 s, hybrid p95 < 2 s, filtered recall for a 200-group admin within 5 points of unfiltered, and RAM/disk fit the minimum box. Otherwise open ADR for OpenSearch as a rebuildable projection (Postgres stays the state store).

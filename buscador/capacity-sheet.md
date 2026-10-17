# Capacity sheet (S0, 2026-10-16)

Status: **indicative**. Measured by the S0 harness on a synthetic Spanish corpus (Zipf terms, random vectors for latency, real embeddings on a 30k-chunk Wikipedia-es sample for recall) on one shared VPS. S4 and S7 re-measure with the real chunker/embedder and the 1M-page-equivalent load; real documents will OCR slower than clean synthetic scans. Raw results and the derivations are in `spikes/s0-harness/results-s0/` (`summary.md` and one JSON per measurement); method and decisions are in `02-performance.md`.

| Field | Value |
|---|---|
| Box | 8 cores, 31 GB RAM, 328 GB free disk, no GPU; shared with other containers (load average 4-7 during the m6/m7 latency runs, 1.5-3 during m8 and m9) |
| Software | PostgreSQL 17.11 (S0; S1 re-ran the head-term query on 18, see below), pgvector 0.8.7, Nextcloud 34.0.4, Tesseract 5.3.0 (`spa+eng`), ocrmypdf 14.0.1, multilingual-e5-small int8 (ONNX) |
| Corpus (functional) | 6 003 pages, 434 files, 13.8 pages/file, 46.8 % image-only pages, 3.0 chunks/page; 769 tokens/page of page text, ~285 tokens per chunk in the embedding sample (350-token target, overlap included) |
| Corpus (scale) | 3 000 000 chunks = 1M-page equivalent; 50 000 files; 200-group admin and 1.6 %-of-files narrow user |
| OCR s/page | 2.3 s (1 process), 2.8 s/page/core with 8 processes, `tessdata_fast`; `tessdata_best` 4.2 / 5.3 s. Through `ocrmypdf` 6.0 s, 9.6 s with deskew+rotate |
| Tokens/s per profile | `small-cpu` (e5-small int8): 2.2k (1 thread), 4.5k (2), 7.6k (4), 9.1k (8). `large` (bge-m3 class): not measured (ineligible on this box by ADR-0004 rules; S4 measures the rate to prove it) |
| Chunks/page | 3.0 (350-token target, 10 % overlap) |
| Storage (3M chunks) | 14.7 GB total, 4.9 kB/chunk (table 7.5 GB, vectors 2.7 GB, HNSW 3.5 GB, GIN 0.75 GB, rest < 0.3 GB); WAL 13.6 GB during load and index builds; plan 2x the vector table as headroom for a re-embed generation |
| Index build | load 3 min; GIN 3 min; HNSW 11 min (`maintenance_work_mem` 8 GB, matching `/dev/shm`); 0.47 min per 100k chunks |
| FTS p50 / p95 | with the head-term pre-limit: p50 2 ms, p95 0.22 s (1 searcher), 0.22 s (5), 0.27 s (10), 0.54 s (20). Without it: p50 44 ms, p95 6.8 s (1), 12 s (5), 22 s (10), 38 s (20) |
| Hybrid p50 / p95 | p50 0.09 s; p95 with `relaxed_order` + re-sort 0.32 s (1 searcher), 0.33 (5), 0.42 (10), 0.82 (20); `strict_order` 0.31 / 0.34 / 0.43 / 0.89 |
| Page 50 / 100 | keyword p95 0.22 s, hybrid p95 0.35 s at 5 searchers (SQL stage only) |
| `isReadable` x50 p95 | **S1 (corrected probe, 3 users x 30 reps, 5 variants)**: worst-user check p95 **308.5 ms** for the best variant (search-constrained, `Folder::search`; not adopted) and **377.4 ms** for the shipped design (`Recheck` + `ExclusionChecker`: one FS setup, per-hit `getById`, folder memo, bulk tag lookup); naive 644 ms, memo 466 ms, memo + bulk tags 381 ms; FS setup 105-176 ms apart. All variants decided every hit like the naive reference. Target 300 ms: **A-PERF-3 not met**, waiver accepted in `adr/0010-recheck-budget.md` (2026-10-17). S0's 330-405 ms is superseded (its probe missed download-disabled hits, expiry, multi-mount and the ancestors above the mount) |
| recall@10 (K, S, H) | not measurable in S0 (needs the S4 gold set on real text); ANN vs exact recall: 30k real chunks 0.959 / 0.987 / 0.994 at `ef_search` 100 / 200 / 400; 3M clustered synthetic 0.78 / 0.85 / 0.89 (relative figure) |
| Filtered recall | 200-group admin = unfiltered by construction (gap 0). Narrow user on the ANN path: 0.81 at 3M clustered (4 points below unfiltered at `ef_search` 200, 8 below at 400); on the exact path 1.0. So the 5-point rule holds with the exact-scan switch below ~2 000 readable files. Without iterative scan: 0.14 and every query short; `strict_order` 0.57 |
| Backfill hours (1M pages, 8 cores) | ~71 h box dedicated (OCR 359 core-h + embedding 208 core-h); ~9 nights of 8 h; keyword lane alone ~45 h. Indicative: best case |
| Verdict | ADR-0001 confirmed **conditionally** (head-term pre-limit required, S4 relevance gate); recommended profile on a CPU-only box of this class: `small-cpu`; `large` not eligible |

## Caveats that change a figure
- Head terms: the generator's Zipf head matches 33-99.9 % of chunks, harsher than natural Spanish; terms matching 10-30 % still gave 5.3 s uncapped. Natural-text document frequencies need the real-data re-run (open question #2).
- Cold cache means `shared_buffers` cold only (the OS page cache of the shared host was left alone): uncapped p95 9.5-9.9 s on a first pass of 60 queries.
- Random 384-d vectors were used for latency only. Recall comes from (a) 30k real e5-small chunks and (b) 3M synthetic clustered vectors (absolute level low by construction, read as relative). Neither is real embeddings at scale.
- Even with the pre-limit, phrase queries have rare multi-second outliers (max 9.4 s, p99 9.3 s at one searcher, while their p95 is 0.27 s); the overall p95 is unaffected, but a per-request timeout and the "demasiados resultados" path must cover them.
- 74 of the 200 gold queries (all three-term, 13 phrase, 10 heading, 11 two-term) match nothing in the 3M synthetic text, so p50 is understated; the tail is set by head and accent-free queries, which do match. With the pre-limit, accent-free queries still reach p95 0.8 s (1 searcher) to 2.2 s (20): the residual head-term cost, to be handled by the "demasiados resultados" path.
- The per-request folder cache in the recheck measurement is a best case (all 50 hits in one folder tree); the conclusion that a recheck design is needed is robust because even that best case misses 300 ms.
- The hybrid page-100 run fuses two 50-row legs (at most 100 chunks), so its cost is understated.
- Concurrency levels 5/10/20 were driven from one load generator on the same host as the database.

## S1 additions (2026-10-16)
- **PostgreSQL 17.11 vs 18 (head-term query, same 3M-chunk text stream and seed, same tuning; `spikes/s1/results-s1/headterm/`):** the two runs agree within noise. Stress head terms with the 10 000-candidate pre-limit: p50 88.8 ms (17.11) vs 89.8 ms (18), p95 204.8 vs 178.6 ms. Realistic head terms with the same pre-limit: p50 145.6 vs 145.4 ms, p95 192.3 vs 201.2 ms. Uncapped baselines: 9.7 s vs 8.9 s (stress p95), 5.3 s vs 5.3 s (realistic p95). The pre-limit stays mandatory and PostgreSQL 18 is confirmed as the S1 image (the vectors were not reloaded: the query reads `chunk.tsv` and `file_acl` only, so HNSW sizes and recall are S0's PG 17 figures).
- **Recheck, the figures behind the row above** (worst user = the 200-group one; check p95 in ms, setup p95 apart): naive 644 (176), folder memo 466 (156), memo + bulk tags 381 (168), search-constrained 308.5 (163), shipped 377.4 (168). The 1-group user is 165-288 and the share-only user 156-259. Box: 8 cores, load average 2.3 before and after the run (the shared VPS usually sits at 4-7, so quieter than S0, not idle). The fixture is 50 hits: 20 bulk, 3 multi-mount, 10 behind a download-disabled share, 2 behind an expired share, 5 ACL-denied, 10 tagged.

## S2 additions (2026-10-17)
- **OCR through the shipped pipeline** (`policy=perpage-2`: classify per page, one-page ocrmypdf, Tesseract TSV for confidence, pages with a scan-sized image always read from the image): 406-page synthetic corpus in 2 463 s on the shared 8-core box, one page at a time (252 OCR'd pages, about 9.8 s each including the confidence pass, load average not recorded). This is slower than S0's 6.0 s/page through ocrmypdf because of the extra Ghostscript rasterise and Tesseract TSV pass (decision C in the S2 blueprint).
- **Accuracy:** pooled CER 0.24 %, WER 0.53 % (A-QUAL-1 gate: 2 % / 5 %); see `acceptance.md` and ADR-0012.
- **Re-OCR cost of decision 6:** a well-OCR'd scan from a scanner is read again; the saving of trusting such layers needs an OCR-sample comparison and is left to S5.
- **Chunks:** the six real laws give 20 to 190 chunks each, mean 135-219 tokens, max 350 (target 350, limit 500).

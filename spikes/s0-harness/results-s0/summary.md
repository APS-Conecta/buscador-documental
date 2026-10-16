# S0 results summary (indicative)

Stack: PostgreSQL 17.11 (Debian 17.11-1.pgdg12+2), pgvector 0.8.7, Nextcloud 34.0.4, tesseract 5.3.0, ocrmypdf 14.0.1+dfsg1. Box: 8 cores, 31 GB, no GPU, shared VPS.

## Storage and index builds (3 000 000 chunks = 1M-page equivalent at 3 chunks/page)
- Database 14.7 GB (4899 B/chunk); chunk table 7.5 GB (+0.84 GB indexes), chunk_vec 2.7 GB (+3.6 GB, HNSW 3.5 GB), tsv GIN 0.75 GB; stored tsv 1175 B/chunk avg.
- Load 186 s (4 procs); GIN 182 s; HNSW 652 s (maintenance_work_mem 8GB); all builds 0.47 min per 100k chunks; WAL 13.6 GB.

## Keyword (Exacta) latency at 3M chunks, p95 ms (median of 3 repeats; spread)
| searchers | page | uncapped | head-term pre-limit (cap 10 000) |
|---|---|---|---|
| 1 | 25 | 6760 (6656-6944) | 223 (211-224) |
| 5 | 25 | 11969 (11448-12747) | 217 (214-225) |
| 10 | 25 | 21759 (20487-22271) | 265 (256-274) |
| 20 | 25 | 37618 (37121-38973) | 544 (541-553) |
| 5 | 50 | 11787 (11188-12233) | 216 (211-222) |
| 5 | 100 | 11977 (11450-12308) | 223 (221-238) |

p50 uncapped / capped at 1 searcher: 44 / 2 ms.
Uncapped p95 by query type at 1 searcher: accentless 7310, head 10408, heading 43, phrase 251, rare 192, three 44, two 145
Uncapped at 20 searchers: 145 of 12000 requests hit the 60 s timeout.
Cold (shared_buffers only, uncapped, first 60 queries after a db restart): p95 9908, 9454, 9808 ms.

## Head-term mitigation (m10), p95 ms / top-10 overlap with the full ranking
- stress_head baseline: p95 9702 (narrow 7617.5, admin 9701.6), overlap 1.0
- stress_head prelimit_2000: p95 700 (narrow 810.8, admin 23.7), overlap 0.021
- stress_head prelimit_10000: p95 205 (narrow 251.6, admin 89.1), overlap 0.154
- realistic_head baseline: p95 5252 (narrow 3885.3, admin 5252.3), overlap 0.958
- realistic_head prelimit_2000: p95 166 (narrow 166.1, admin 50.1), overlap 0.108
- realistic_head prelimit_10000: p95 192 (narrow 156.8, admin 192.3), overlap 0.229
Synthetic text has no real relevance, so overlap shows the ranking differs, not that quality is lost; S4 must measure it on real text.

## Hybrid (Mixta) latency p95 ms (FTS leg capped; strict_order vs relaxed_order + re-sort)
| searchers | page | strict | relaxed |
|---|---|---|---|
| 1 | 25 | 306 | 322 |
| 5 | 25 | 339 | 328 |
| 10 | 25 | 430 | 418 |
| 20 | 25 | 894 | 818 |
| 5 | 100 | 361 | 349 |

## Filtered recall (real e5-small embeddings, 30 000 Wikipedia-es chunks, 200 queries, HNSW forced and plan-verified)
- F1_narrow: off ef100 mst20000: recall@10 0.1625, short<50 1.0; relaxed ef100 mst5000: recall@10 0.908, short<50 0.0; relaxed ef100 mst20000: recall@10 0.9995, short<50 0.0; relaxed ef400 mst20000: recall@10 1.0, short<50 0.0; strict_ ef100 mst20000: recall@10 0.91, short<50 0.0
- F2_admin200: off ef100 mst20000: recall@10 0.959, short<50 0.0; relaxed ef100 mst5000: recall@10 0.959, short<50 0.0; relaxed ef100 mst20000: recall@10 0.959, short<50 0.0; relaxed ef400 mst20000: recall@10 0.994, short<50 0.0; strict_ ef100 mst20000: recall@10 0.959, short<50 0.0
- unfiltered: off ef100 mst20000: recall@10 0.959, short<50 0.0; relaxed ef100 mst5000: recall@10 0.959, short<50 0.0; relaxed ef100 mst20000: recall@10 0.959, short<50 0.0; relaxed ef400 mst20000: recall@10 0.994, short<50 0.0; strict_ ef100 mst20000: recall@10 0.959, short<50 0.0

## ANN shortfall and exact-scan switch at 3M random vectors (latency and shortfall only)
| readable files | exact p50 ms | ANN p50 ms | ANN shortfall<50 |
|---|---|---|---|
| 5 | 0.8 | 120.9 | 1.0 |
| 20 | 1.4 | 116.7 | 1.0 |
| 100 | 5.3 | 123.6 | 0.867 |
| 500 | 21.9 | 67.5 | 0.0 |
| 2000 | 86.3 | 54.0 | 0.0 |
| 10000 | 6223.4 | 42.7 | 0.0 |

## isReadable / getById, 50 hits, fresh PHP process per call (30 runs)
- u_admin200/batched: check p50 362.7 p95 405.4 ms; FS setup p95 158 ms; outcome {'denied': 0, 'excluded': 10, 'ok': 40, 'share_download_off': 0}
- u_admin200/naive: check p50 656.4 p95 820.2 ms; FS setup p95 180 ms; outcome {'denied': 0, 'excluded': 10, 'ok': 40, 'share_download_off': 0}
- u_narrow/batched: check p50 301.7 p95 328.7 ms; FS setup p95 108.8 ms; outcome {'denied': 5, 'excluded': 10, 'ok': 35, 'share_download_off': 10}
- u_narrow/naive: check p50 516.8 p95 759.6 ms; FS setup p95 121 ms; outcome {'denied': 5, 'excluded': 10, 'ok': 35, 'share_download_off': 10}
- u_shared/batched: check p50 206 p95 233.3 ms; FS setup p95 104.9 ms; outcome {'denied': 40, 'excluded': 0, 'ok': 10, 'share_download_off': 10}
- u_shared/naive: check p50 242.4 p95 424.8 ms; FS setup p95 115.9 ms; outcome {'denied': 40, 'excluded': 0, 'ok': 10, 'share_download_off': 10}

## OCR (300 dpi, spa+eng, OMP_THREAD_LIMIT=1)
- raw tesseract tessdata_best: 4.17 s/page (1 proc), 1.525 pages/s with 8 procs = 5.25 s/page/core
- raw tesseract tessdata_fast: 2.29 s/page (1 proc), 2.896 pages/s with 8 procs = 2.76 s/page/core
- ocrmypdf fake_invisible/force-ocr: median 9.27 s/page (6/6 ok)
- ocrmypdf fake_invisible/redo-ocr: median 9.62 s/page (6/6 ok)
- ocrmypdf fake_invisible/skip-text: median 1.17 s/page (6/6 ok)
- ocrmypdf fake_visible/force-ocr: median 9.17 s/page (6/6 ok)
- ocrmypdf fake_visible/redo-ocr: median 9.38 s/page (6/6 ok)
- ocrmypdf fake_visible/skip-text: median 1.16 s/page (6/6 ok)
- ocrmypdf mixed/redo-ocr: median 6.65 s/page (6/6 ok)
- ocrmypdf mixed/skip-text: median 6.67 s/page (6/6 ok)
- ocrmypdf native/no-flag: median None s/page (0/4 ok, 4 skipped rc=6)
- ocrmypdf native/skip-text: median 0.39 s/page (4/4 ok)
- ocrmypdf photo/image-dpi300: median 6.33 s/page (6/6 ok)
- ocrmypdf photo/image-dpi300+deskew+rotate: median 9.43 s/page (6/6 ok)
- ocrmypdf scanned/force-ocr: median 5.98 s/page (8/8 ok)
- ocrmypdf scanned/force-ocr+deskew+rotate: median 9.62 s/page (8/8 ok)
CER/WER medians: fake_invisible/force-ocr 0.0015/0.0098; fake_invisible/redo-ocr 0.0007/0.0054; fake_invisible/skip-text 0.9608/0.9973; fake_visible/force-ocr 0.0364/0.0455; fake_visible/redo-ocr 0.0346/0.0429; fake_visible/skip-text 0.9669/0.9961; mixed/redo-ocr 0.0004/0.0027; mixed/skip-text 0.0004/0.0027; native/skip-text 0.0000/0.0000; photo/image-dpi300 0.0021/0.0149; photo/image-dpi300+deskew+rotate 0.0007/0.0054; scanned/force-ocr 0.0010/0.0069; scanned/force-ocr+deskew+rotate 0.0016/0.0111; best 0.0007/0.0053; fast 0.0011/0.0077

## Corpus and embedding
- Functional corpus 434 files, 6003 pages (13.8/file), image-only share 46.8%, 3.0 chunks/page, 769 tokens/page (synthetic words tokenise heavily).
- Embedding tokens/s by threads (batch 32): 1t 2198, 2t 4524, 4t 7613, 8t 9139; 30k-chunk run at 8t: 8505 tok/s.

## Backfill (1M pages, 8 cores, indicative)
- OCR: 468,000 image-only pages x 2.76 s/page/core = 359 core-h; embedding: 855 M tokens at 1142 tok/s/core = 208 core-h.
- Wall-clock on 8 cores, box dedicated: 71 h; with an 8 h night window: 8.9 nights. Keyword lane first: OCR+index only = 45 h.

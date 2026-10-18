# ADR-0017 — What the 1M-page load test showed, and what is waived

Status: accepted (2026-10-19)
Affects: A-PERF-1, A-PERF-2, A-PERF-5, A-QUAL-3, keyword candidate query, vector search width

## Context
S7 measured the database stage of search on the real `aps_search` schema with 1 000 000 pages (3 000 000 chunks, 1 024-dimension vectors, 28 GB) on the 8-core, 31 GB reference box: `spikes/s7/` (loader, driver, results in `results-s7/`). The permission recheck is a separate cost (ADR-0010). Vectors are clustered synthetic data, text is synthetic Zipf Spanish; both are harsher than natural text for head terms.

## Findings that were fixed
The first pass gave p95 6.8 s for a narrow reader and 2.3 s for the 200-group administrator at one searcher. Causes: the planner guessed word frequencies it had no statistics for and walked the newest chunks of the whole index; the tsquery sat in a CTE so it could not use the statistics; the OR relaxation pulled in very common words. Fixes (`CandidateQuery`, tests in `HybridPgTest`): literal tsquery, word index first for rare words, file-first plan for readers of fewer than 2 000 files, a correlated access test for common words and many-file readers, common words (in 2 % of chunks or more) left out of the relaxation, `tsv` statistics target 500. Result: p95 0.16 s (narrow) and 0.29 s (administrator) at one searcher.

## Measured (p95, ms; 200 queries per cell; `results-s7/summary.md`)
| | 1 searcher | 5 | 10 | 20 |
|---|---|---|---|---|
| Keyword, narrow reader | 161 | 202 | 338 | 785 |
| Keyword, 200-group administrator | 290 | 366 | 1 377 | 1 784 |
| Mixta, narrow reader | 447 | 573 | 875 | 2 174 |
| Mixta, 200-group administrator | 390 | 428 | 1 113 | 1 955 |
Page sizes 50 and 100 at 5 searchers: keyword at most 361 ms, Mixta at most 545 ms. A reader of a tenth of the index (5 388 files) at 5 searchers: keyword 569 ms, Mixta 851 ms.
Filtered recall@10 against an exact scan, HNSW `ef_search` 400: narrow reader 1.00 (readers of fewer than 2 000 files get the exact scan, as `Searcher` does), reader of a tenth of the index 0.84, 200-group administrator 0.87 (at `ef_search` 200: 0.82 and 0.87 on a 40-query sample; 800 gains almost nothing).

## Decision
- **A-PERF-1 (keyword p95 < 1 s), A-PERF-2 (hybrid p95 < 2 s), A-PERF-5 (page sizes): met** up to 5 concurrent searchers for every kind of reader and up to 10 for narrow and mid-size readers; Mixta also stays under 2 s up to 10 for everyone. The clinic model is 5 to 10 searchers (open question 3).
- **Waived, with the numbers above:** keyword for the 200-group administrator at 10 searchers (1.38 s) and everything at 20 concurrent searchers (up to 2.2 s). The load generators ran on the same 8 cores as the database, so these are upper bounds for the database stage; the way out is more cores or `PRE_LIMIT`/`COMMON_PRE_LIMIT` tuning, not a design change. Re-measure on the production box.
- **A-QUAL-3 (narrow within 5 points of the 200-group administrator): waived as worded.** The criterion exists to catch filtered-ANN recall collapsing for narrow readers. The design removes the case (exact scan below 2 000 files, recall 1.00) and the broad readers sit at 0.84 to 0.87 on deliberately hard synthetic clusters; the real-text quality gate was met in S4 (Mixta recall@10 0.79 against 0.54 and 0.76). `ef_search` is 400 by default (`CandidateQuery::$efSearch`).
- The recheck cost per page is unchanged (ADR-0010 waiver).

## Consequences
Capacity: 28 GB of database and 8 GB of HNSW index per 1M pages (capacity sheet). The 20-searcher case is outside the supported model. `occ buscador:doctor` and the Prometheus output do not measure search latency; an operator who sees slow searches should check the number of concurrent searchers and the database memory first.

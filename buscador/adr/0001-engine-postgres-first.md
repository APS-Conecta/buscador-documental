# ADR-0001 — Postgres first, OpenSearch by trigger

Status: accepted (2026-10-15); amended 2026-10-16 (S0): confirmed conditionally, reopens if the S4 relevance gate fails
Affects: buscador (all), P7 compose job

## Context
The previous plan targeted OpenSearch and dropped Postgres, but the worker needs transactional state (leases, tombstones, idempotency, page text) that OpenSearch cannot provide. A second engine adds ACL fan-out (`update_by_query`), blue/green dual-write, JVM/heap ops and a second backup tier on small clinic servers.

## Decision
Use Postgres for state, keyword search (FTS `spanish` + `unaccent`) and vectors (pgvector `halfvec`). All SQL lives in one `ChunkRepository` class. OpenSearch is opened as a new ADR only if S0 shows, on the 200-query gold set at 1M-page equivalent, any of: keyword p95 >= 1 s, hybrid p95 >= 2 s, filtered recall (200-group admin) more than 5 points below unfiltered, or memory/disk above the minimum box. In that case OpenSearch is a rebuildable projection and Postgres stays the state store.

## Consequences
- One DB to back up and operate; no alias or generation juggling for text.
- Risk: `ts_rank` cost and HNSW build time at 3M chunks; mitigated by S0 measurement and the trigger.
- No interface is created until a second backend exists (org rule: no single-implementation interfaces).

## Amendment (2026-10-16, S0): outcome of the measurements, decided by the maintainer
Measured on the S0 harness at 3M chunks (1M-page equivalent), 200-query gold set, indicative; details in `capacity-sheet.md` and `02-performance.md`.

| Trigger | Result | Verdict |
|---|---|---|
| Keyword p95 < 1 s | **uncapped: 6.8 s at 1 searcher, 38 s at 20 (head terms)**; with the candidate pre-limit 0.22 s (1-10), 0.54 s (20) | fails as designed, passes with the pre-limit |
| Hybrid p95 < 2 s | 0.31 s (1) to 0.82 s (20), FTS leg capped | pass |
| Filtered recall within 5 points | 200-group admin = unfiltered by construction (it reads every file). Narrow user: ANN path 0.81 at 3M clustered, 4 points below unfiltered at `ef_search` 200 (8 at 400); exact path 1.0 | pass **only with the exact-scan switch** (< ~2 000 readable files), indicative |
| Memory/disk fit the minimum box | 14.7 GB database (4.9 kB/chunk), ~7 GB hot working set, WAL 13.6 GB during builds; the minimum box is not defined anywhere in the plan | **not evaluable**: define the minimum box (open question #10) |

Decision: **Postgres stays; the trigger is not fired.** The unmitigated design does miss the keyword rule, so the head-term pre-limit becomes a required design element, not an option:
1. S1 builds the keyword query with the candidate pre-limit (rank only the newest 10 000 ACL-passing matches before `ts_rank`), the `hnsw` settings in `02-performance.md` (`relaxed_order`, `max_scan_tuples` 20 000, re-sort) and the exact-scan switch below ~2 000 readable files.
2. S4 owns a relevance gate: on real Spanish text the pre-limit must not lower recall@10 below the A-QUAL-2 threshold. **If it does and no alternative from `02-performance.md` (strict-pass first, "demasiados resultados" path, denormalised ACL column) recovers it, this ADR is reopened and OpenSearch is evaluated as a rebuildable projection.**
3. Thresholds were not adjusted.

Caveats the decision rests on: filtered recall measured on 30k real chunks and on 3M synthetic clustered vectors, never on real embeddings at scale; accent-free queries of common words still reach p95 0.8-2.2 s with the pre-limit; synthetic corpus (the generator's head terms are harsher than natural Spanish, but 10-30 %-frequency terms still took 5.3 s uncapped), random vectors for the 3M latency runs, one shared host, PostgreSQL 17 (pg18 image exists; S4/S7 re-measure).

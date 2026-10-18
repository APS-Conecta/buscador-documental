# 01. Architecture

Status: draft v3. Engineering doc (English). Decisions are recorded in `adr/`.

## 1. Components
```
 Browser ──► Nextcloud app (PHP) ──────────────► Postgres (NC DB + aps_search schema)
              │  listeners → outbox              ▲   ▲
              │  unified-search provider         │   │ (role: worker_rw, no write on file_acl)
              │  sidebar API, admin, occ         │   │
              │  retrieve() + live recheck       │   │
              │  app endpoint: file bytes/meta ◄─┼───┤
              ▼                                  │   │
        query-embed process (warm model) ────────┘   │
                                                     │
        Worker container (pull, no inbound port) ────┘
          extract → OCR → chunk → embed → write
```
- **Nextcloud app** (PHP): owns permissions. Writes `file_acl`, `outbox`, `tombstone`, `audit_log`; reads everything. Only code path that returns hits to users is `retrieve()`.
- **Worker** (Python, own container): claims jobs and writes results **only through SECURITY DEFINER SQL functions** (`claim_job(lane,n)`, `write_result(job_id, lease_epoch, ...)`, `fail_job`). `write_result` is a compare-and-set on `job.id` + `lease_epoch` + `(file_id, etag)`, and inserts rows with the *claimed job's* `file_id`, so a zombie worker or a bug cannot overwrite newer data or write text under another file. The worker role has no direct DML on any table. Bytes come from an app endpoint (HMAC bearer, `If-Match` etag on every Range call) that only serves files with an active claim. Never modifies user files. **Trust boundary:** the worker is trusted for *content of the file it claimed*, never for ACL; a fully compromised worker can at worst poison the index text of files currently claimed, and the recheck still gates every hit.
- **Query-embed process**: small separate service holding the active model warm. Isolates search latency from ingestion load.
- **Nothing leaves the box**: no telemetry, no model downloads at runtime (models come from a pinned, checksummed bundle).

## 2. Data model (schema `aps_search`)
| Table | Key columns | Notes |
|---|---|---|
| `file_state` | `file_id` PK, `etag`, `content_hash`, `text_state`, `vec_state`, `acl_dirty bool`, `skip_reason`, `updated_at` | per-lane states, see section 4 |
| `job` | `id`, `lane`, `file_id`, `idem_key`, `lease_until`, `lease_epoch bigint not null default 0`, `claimed_by text`, `claimed_etag text`, `claimed_hash text`, `attempts`, `not_before` | claim with `FOR UPDATE SKIP LOCKED` |
| `page` | `file_id`, `page_no`, `text`, `ocr_conf`, `ocr_ver`, `source` (layer/ocr) | text TOASTed |
| `chunk` | `chunk_id`, `file_id`, `page_no`, `ord`, `heading`, `text`, `tsv` (generated, `spanish_unaccent`), `chunker_ver` | GIN on `tsv`; `pg_trgm` on filename in `file_meta` |
| `chunk_vec_<gen>` | `chunk_id`, `vec halfvec(d)` | one table per profile generation; HNSW built after bulk load |
| `file_meta` | `file_id`, `name`, `mime`, `mtime` | filters; the displayed **path and owner are computed per user from the live node** returned by the recheck, never from stored columns (a stored path would leak the owner's folder names to sharees) |
| `file_acl` | `file_id`, `principals text[]`, `acl_ver` | GIN; principals = `g:<gid>`, `u:<uid>` (owner and direct shares), catch-all `x:recheck` stored on files whose access is not fully modelled (circle, Talk, Deck, mail, guest and federated shares; groupfolders with advanced ACL), which every caller's set also contains, so the live recheck decides; circles are not stored as `c:` principals (decided 2026-10-17) |
| *(no cache table)* | | compute dedupe = **copy on hit**: a new file whose `content_hash`, `ocr_ver`, `chunker_ver` (and `profile` for vectors) match an existing indexed file gets its rows by `INSERT … SELECT` from that file. No shared payload, so exclusion and erasure never need reference counts |
| `outbox` | `id`, `file_id`, `kind` (`file`/`subtree`/`acl`/`rule`), `created_at`, `processed_at`, `attempts` | written by the app listeners (ids and kinds only, never throwing); unique partial index on `(file_id, kind)` while unprocessed; processed rows pruned after 7 days. **Level-triggered:** a row means "re-evaluate this file"; the processor recomputes truth from the file cache, tags, shares and scope rules, so event loss, duplication and order cannot corrupt the index |
| `tombstone` | `file_id`, `reason`, `created_at`, `purge_after` | ledger for rows with nothing left to re-read (permanent delete, user deletion) and the 30-day trash record |
| `scope_rule` | `id`, `kind` (`folder`/`all_groupfolders`), `file_id`, `created_by_token`, `created_at` | admin-managed rules keyed on `fileid`; under `opt_in` nothing is indexed outside a rule |
| `reconcile_run` | `run_id`, `phase`, `cursor_file_id`, `rules_fp`, `counts`, `finished_at` | nightly set-based reconciliation with a resumable cursor |
| `settings`, `purge_pending`, `pseudonym` | | `scope_mode`, `paused`, reconcile window; deleted-user bookkeeping; uid -> random token mapping that exists only while the user does |
| `embedding_profile` | `gen`, `profile_id`, `model`, `dim`, `quant`, `prefixes`, `max_tokens`, `active` | threads/batch are read from `profiles.yaml`; editable, see ADR-0004 |
| `audit_log` | `at`, `actor`, `action`, `subject_token`, `detail` | actor and subject are pseudonyms; `detail` is a closed schema (counts, reason codes, rule ids); no query text, no content, no file names; 90-day retention (counsel to confirm); unlinkable once the user's `pseudonym` row is deleted |
Migrations are expand/contract; the worker refuses an unknown schema version. **Text search config:** `tsv` uses the two-argument, IMMUTABLE form `to_tsvector('spanish_unaccent', …)`; the `unaccent` extension and the `spanish_unaccent` configuration are created in a migration and versioned (`ts_config_ver`; any change forces a reindex). Restore runbook creates extension and config before data. The app declares Postgres (minimum version pinned in S0) in `info.xml`; `CREATE EXTENSION vector/unaccent` needs privileges the NC DB user may lack, so install docs give the admin step.

## 3. Permission model
1. NC computes the user's principals per request: `u:<uid>` plus current group ids (`g:<gid>`).
2. SQL prefilter: `file_acl.principals && :principals`. Group-membership changes need no reindex.
3. Share or folder-ACL change: NC marks `acl_dirty`, a PHP job recomputes principals for the affected files and bumps `acl_ver`. One row per file; folders of 100k files are throttled batches.
4. **Recheck (authoritative)** on every hit before snippet building: `IRootFolder->getUserFolder($uid)->getById($fileId)` returns at least one readable node (when mounted more than once, any readable node counts), the share is not expired, the share attribute *download disabled* is not set (otherwise the hit shows title only, no snippet), and the file is not excluded (`rag-excluded` tag on file or ancestor). Failures are dropped silently; none of the dropped content is logged.
5. "Shared with me / my files" is resolved live through the share API and intersected with the hit set; it is not derived from `principals`.
6. Every caller's principal set always includes `x:recheck`, so files with unmodelled access are never dropped by the prefilter; the recheck decides. Anonymous link shares are out of scope (search requires a logged-in user); federated shares are out of scope in R1. Groupfolder advanced-ACL, circle and deny-rule fixtures are part of the RBAC matrix.
`acl_dirty` is processed ahead of backfill; its backlog age is exposed in `occ status` and the admin page (a new sharee sees false negatives until it clears). Staleness window of the prefilter = time until `acl_dirty` is processed; the recheck makes it a false-negative window only (never a leak).

## 4. State machine and idempotency
Per lane: `text_state`: pending → extracting → ocr → chunking → indexed. `vec_state`: pending → embedding → embedded. Terminal/side: `skipped(reason)`, `tombstoned → purged`. `acl_dirty` is a boolean, orthogonal.
Idempotency key, computed in this order: `(file_id, etag)` from NC → `content_hash` after fetch → `(chunker_ver, ocr_ver)` → `profile` for the vector lane. If `content_hash` matches an already indexed file (same versions), skip OCR/embedding and copy that file's rows to the new `file_id` (ADR-0005). A tombstone with a later timestamp always beats queued work.

## 5. Worker contract (v1, OpenAPI in S1)
Two channels. (a) **DB**: `claim_job`, `heartbeat`, `write_result`, `fail_job` functions (above); no direct table access. (b) **App HTTP** (bytes and meta only): `GET /files/{id}/meta`, `GET /files/{id}/content` (Range; requires `If-Match: <etag>`, 412 if changed; only for files with an active claim; per-worker concurrency cap). The worker hashes the full stream and `write_result` rejects a `content_hash` that differs from the claimed one. Principals are never accepted from the worker. Versioned; contract tests on both sides. Auth: bearer + HMAC of method/path/body-hash/timestamp (replay window), secret in sensitive `IAppConfig`, rotated by `occ`.

## 6. Search flow
`retrieve(uid, query, mode, filters, page)` → principals → mode: **Exacta** `websearch_to_tsquery('spanish_unaccent', q)` (AND) + `ts_rank` over the **candidate pre-limit** (newest 10 000 ACL-passing matches, ADR-0001 outcome), relaxing to OR over the query terms when fewer than N documents match; acronym and term expansion at query time from the versioned glossary (both directions, ambiguous keys never expanded; ADR-0014 session notes in the S4 design); tokenisation tests for RUT, folios and law numbers (e.g. `21.719`) in the gold set; **Por significado** query-embed → HNSW (`hnsw.iterative_scan = relaxed_order`, `max_scan_tuples` 20 000, `ef_search` 200, MATERIALIZED re-sort by distance) under prefilter, or an exact scan when the user can read fewer than ~2 000 files (S0 measured; setting `exact_scan_files`); **Mixta** both, fused with RRF (k=60) in SQL → over-fetch → group by `file_id` (best chunk per file) → recheck top documents, refill if short → snippets (`ts_headline`) only for final hits, HTML-escaped. Query-embed timeout → keyword fallback with a message. Unified-search provider calls Exacta only.

### Paging, order and filters (API)
`retrieve(uid, query, mode, filters, sort, page_size ∈ {25,50,100}, cursor)`. Filters (type, folder, modified range, ownership, low-confidence flag) are applied in SQL before ranking, except "Compartidos conmigo / Mis archivos", which is a live intersection (section 3.5). Sorts: `relevance`, `modified_desc`, `modified_asc`, `name_asc`, `name_desc`. Relevance results page over a candidate list capped at 1 000 documents (rank + recheck run once per page, cached for the session of the request chain with a short TTL); every sort is applied to the relevance-ranked top 1 000 stored as an id-only snapshot and paged by an opaque position cursor (S4 design: in the semantic modes every chunk matches, so keyset over all matches is meaningless). The recheck runs per page, so a page may come back short; the API refills from the next candidates until the page is full or candidates run out, and the total is exact up to the cap (`total_capped: true` beyond it). Page 1 of a new query always starts from the full candidate set so permission changes show up immediately.

## 7. Embedding profiles
Catalogue file `profiles.yaml` (shipped, versioned, editable by admin via `occ buscador:profile` or settings): `bge-m3` (Ollama, 1024-d, no prefixes) and `qwen3-embedding:0.6b` (Ollama); the ONNX small-cpu profile is a cut (ADR-0014). Each profile has hard eligibility rules (minimum measured tokens/s and RAM for index + graph); preflight never recommends an ineligible profile (e.g. `large` on a CPU-only box where 1M pages would take weeks). Preflight measures cores, RAM, AVX2/AVX512, optional GPU, and *measured* embed throughput, then recommends one; the admin confirms or edits. Changing profile creates generation N+1: keyword search is untouched; re-embedding fills `chunk_vec_<N+1>`; switch when coverage >= threshold. Chunk size is set for the **smallest max-token profile in the catalogue** (target 300-400 tokens including the heading/page prefix, counted conservatively), so every profile accepts the same chunks and generation N+1 reuses the same `chunk_id`s. Over-long input fails loudly (no silent truncation).

## 8. Exclusion, erasure, retention
`rag-excluded` (file or ancestor) → delete chunks, vectors, pages, cache entries referencing only that file; audit entry. User deletion → erase owner rows and audit pseudonymisation. Trash and old versions: not searchable; purged after the 30-day grace.

## 8b. Maintenance
`job`: partial index `(lane, not_before) WHERE lease_until < now()` for claims, aggressive per-table autovacuum. HNSW: periodic `REINDEX CONCURRENTLY` after heavy deletes/re-embeds, bloat metric in health. WAL volume during backfill and HNSW builds sized in the capacity sheet.

## 9. Failure modes (to be turned into tests in S7)
Postgres full or read-only; worker dead (heartbeat alert); lease expiry mid-OCR; etag changed during fetch; query-embed down; HNSW build in progress; `acl_dirty` backlog; zip bomb/malicious PDF; encrypted file; model bundle checksum mismatch; clock skew on HMAC; restore from backup with a newer schema.

## 10. Sandbox
The worker supervisor (holds DB and HMAC secrets, has network) hands each file to a separate `parser` container (ADR-0011; `unshare -n` is not available under the default seccomp profile): `network_mode: none`, no secrets, `cap_drop: [ALL]`, `no-new-privileges`, cgroup CPU/memory/PID limits, read-only rootfs plus tmpfs scratch, non-root, per-file timeout enforced by the supervisor. No Docker socket is ever mounted. Caps on size, pages, pixels, zip ratio come from S0 measurements. S2 proves the isolation with tests (cannot read worker env, no route to Postgres/app).

## 10. Operations controls (S5, ADR-0015)
Night window, concurrency caps, worker liveness, secret rotation with a grace period, an internal network for the worker and encrypted schema backups are described in ADR-0015; the runbooks are in the administrator page of the site.

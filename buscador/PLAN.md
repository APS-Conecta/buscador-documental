# PLAN: Buscador Documental v3

Source of truth for slices. Requirements and evidence live in `acceptance.md`. Architecture in `01-architecture.md`, performance in `02-performance.md`, UI in `03-ui-guidelines.md` + `mockup/`, security in `04-security-privacy.md`, decisions in `adr/`.
"Acceptance" lists the ids a slice must satisfy or measure; `acceptance.md` has the owning slice per id. A-DOC-1 applies to every slice.
Every slice follows the workflow in `.claude/CLAUDE.md` (fresh context per step, artifacts in `.rpiv/artifacts/`) and has a docs exit item (`/bd-docs`).

## Order
`P7 S3 compose harness (S0 consumes it and only adds corpus + measurements)` → **U0 mockup** (parallel with S0) → **S0** → **S1** → (**S2** ‖ **S3**) → **S4** → **S5** → **S6** → **S7**.
Hard gates: U0 human design-review before S4 mode UI and S6; S3 legal gate before any real-data install; S0 engine ADR before S1 schema is frozen.

## Slices
### U0. UI mockup (path: design-review only)
Entry: `03-ui-guidelines.md`. Work: refine `mockup/index.html` (all states, 360 px, microcopy from glossary); run `bd-ux-auditor`. Exit: user accepts the mockup (design-review gate); frozen as `mockup/v1/`. Acceptance: A-UX-1..3. Docs: copy for the user page.

### S0. Harness and measurements (path: full)
Nature: a **throwaway-schema spike**, not the final code. Numbers are two-stage: S0 gives indicative values (Zipf-distributed Spanish terms incl. head terms, random vectors for latency, a public Spanish corpus for recall); S4 and S7 re-measure with the real chunker/embedder and the 1M-equivalent load. Work: compose job (NC 34 + Postgres + pgvector >= 0.8 + worker + tesseract; generated users/groups/groupfolder ACLs, no committed dumps); synthetic Spanish corpus generator (native, scanned, photographed, mixed, fake text layer); 200-query retrieval gold set (single-user and 200-group-admin cases); measure everything in `02-performance.md`; fill `capacity-sheet.md`. Exit: ADR-0001 confirmed or trigger fired; thresholds `[M0]` written as numbers in `acceptance.md`; caps set. Acceptance: A-PERF-3, A-PERF-4; indicative A-PERF-1, A-QUAL-3. Docs: dev page (how to run the harness).

### S1. Walking skeleton and permission canary (path: full)
Work: schema (ADR-0002) with migrations and a minimal synchronous principal writer (full `acl_dirty` machinery is S3); worker contract v1 (OpenAPI) + contract tests; one scanned PDF → worker → OCR → chunk → Postgres → `retrieve()` with live recheck. Canary: user A finds it, user B does not, revoking the share removes the hit, `rag-excluded` removes it. Exit: canary green in the compose job. Acceptance: A-SEC-1..4.

### S2. Extraction, OCR, chunking (path: blueprint)
Work: tier 1-2 extractors, per-page text-layer detection, rotation/OSD/deskew, `spa+eng`, confidence, retry; structure-aware chunking (Artículo/Capítulo, recursive 300-500 tokens, 10-15 % overlap, heading/page prefix) with property tests; sandbox, caps, encrypted skip. Exit: gold OCR CER/WER and chunking properties pass; hostile-file tests end in `skipped`. Acceptance: A-QUAL-1, A-SAFE-1..3.

### S3. Events, reconciliation, scope and erasure (path: full; starts after S2's table shapes are merged, then runs in parallel on separate tables)
Work: listeners → outbox; `acl_dirty` job; nightly reconciliation (manifest diff, tombstones); scope filter (`opt_in | all_readable`), `rag-excluded` deletion; erasure on user deletion; audit log; first-run notice and pause. Exit (technical): RBAC adversarial matrix 100 % (incl. circles, groupfolder ACL/deny, share expiry, download-disabled, multi-mount), stale-index test, log-hygiene canary. The counsel/DPIA gate (A-SEC-7) is an external dependency tracked as a release gate in S7 and as a hard block on any real-data install; it does not hold up S4+. Acceptance: A-SEC-1, A-SEC-3, A-SEC-5, A-SEC-6, A-OPS-3.

### S4. Embedding profiles and hybrid search (path: full)
Work: preflight (independent of Ollama), `profiles.yaml`, editable setting + `occ buscador:profile`, query-embed process, `chunk_vec_<gen>`, modes Exacta/Por significado/Mixta (RRF k=60), filters, sort (relevance, newest, oldest, name), paging 25/50/100 with keyset cursors and the 1 000 cap, grouping, snippets, fallback on timeout. Exit: hybrid beats both single modes on the gold set; filtered recall within tolerance. Acceptance: A-QUAL-2, A-QUAL-3, A-QUAL-4, A-PERF-2, A-PERF-5, A-FUNC-1, A-FUNC-2. Needs U0 accepted for the mode UI.

### S5. Backfill and operations (path: blueprint)
Work: throttled, pausable, checkpointed backfill with ETA; keyword lane first, vector lane after; night window; health; `occ status|reindex|pause|resume|purge-user|doctor|profile|rotate-secret`; backup including page text; alert rules; runbooks (install, upgrade, rollback, rebuild, restore). Exit: ETA within stated margin; pause/resume survives restart; vector coverage % is measured and exposed (it gates the semantic release). Acceptance: A-OPS-1, A-OPS-2 (backup), A-OPS-4.

### S6. UI (path: blueprint)
Work: Vue 3 + `@nextcloud/vue` 9 app per mockup using `NcContent`/`NcAppNavigation`/`NcAppContent`/`NcAppSidebar`/`NcButton` and the APS Conecta theme variables (no hard-coded colours); pager, order and filters; unified-search provider; Files tab; state matrix; PDF.js `#page=N` spike with fallback. Exit: axe clean, keyboard path, 360 px, bundle <= 250 KB gzip. Acceptance: A-UX-4..11.

### S7. Hardening and release (path: full)
Work: STRIDE review (worker channel, DB role); failure-mode tests (`01-architecture` section 9); load test at 1M-page equivalent; restore rehearsal with measured rebuild time; tarball install on clean NC 34; NC 35 job non-blocking. Exit: all acceptance rows green or waived with an ADR; legal gate recorded. Acceptance: A-SEC-7, A-SEC-8, A-PERF-1, A-PERF-2, A-QUAL-3, A-OPS-2, A-OPS-5 (plus all earlier rows re-run).

## Workflow path per slice
| Path | Steps |
|---|---|
| full | discover → research → design → design-review (human) → plan → implement → validate → review → docs → commit |
| blueprint | research → blueprint → implement → validate → review → docs → commit |
| UI (U0) | `/bd-mockup` → ux-audit → design-review (human) |

## Cross-plan impacts (applied in the "fixes" step)
README, acceptance-matrix, P2 (S2, S4, S8), P4, P6, P7 S3 — see `CROSSREF.md`.

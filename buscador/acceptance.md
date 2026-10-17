# Acceptance (v3), single source of truth for the Buscador

`[M0]` = number fixed in S0 and written here; until then the value shown is the working target. S0 (2026-10-16) fixed the thresholds below and recorded the indicative measurement beside each; `[M0 pending S4]` marks a number S0 cannot fix. Evidence is a command or test name; `TBD-S<n>` means the harness for it is created in that slice.

| ID | Requirement | Slice | Evidence |
|---|---|---|---|
| A-SEC-1 | User A never sees B's private content (owner, sharee, revoked share, expired share, download-disabled share (no snippet), group removed, ACL narrowed, circle, groupfolder advanced ACL/deny, multi-mount, moved, user deleted) | S1, S3 | RBAC adversarial matrix 100 % in compose job: `make test-rbac` (TBD-S1) |
| A-SEC-2 | Live recheck on every hit before snippets | S1 | stale-index test: revoke after indexing, hit absent |
| A-SEC-3 | `rag-excluded` (file or ancestor) removes hits and deletes index data | S1, S3 | tag test + row-count query |
| A-SEC-4 | Worker has no direct DML; `write_result` forces claimed `file_id`; zombie worker (expired lease) cannot overwrite | S1 | DB privilege test + zombie-worker test |
| A-SEC-5 | Zero document or query text in logs, errors, history | S3 | log-hygiene canary (CI) |
| A-SEC-6 | Erasure on user deletion; `occ purge-user` | S3 | test + audit pseudonymisation |
| A-SEC-7 | Legal gate recorded before real-data install (counsel, DPIA, notice, pause) | S7 (release gate; blocks real-data install from day one) | ADR-0006 supersession note present |
| A-SEC-8 | STRIDE review closed for worker channel | S7 | review artifact with no open high |
| A-SAFE-1 | User files unchanged by indexing | S2 | hash test |
| A-SAFE-2 | Sandbox: zip bomb and malicious PDF end `skipped` | S2 | sandbox tests |
| A-SAFE-3 | Encrypted files skipped with visible reason | S2 | skipped-files table test |
| A-PERF-1 | Keyword p95 < 1 s at 1M-page equivalent `[M0 = 1 s]` | S0, S7 | load test report. **S0 (indicative, 3M chunks, 200-query gold set): 0.22 s at 1-10 searchers, 0.54 s at 20, only with the head-term pre-limit; 6.8-38 s without it.** `capacity-sheet.md` |
| A-PERF-2 | Hybrid p95 < 2 s `[M0 = 2 s]` | S4, S7 | load test report. S0 indicative: 0.31 s at 1 searcher, 0.82 s at 20 |
| A-PERF-3 | `isReadable` for 50 hits p95 < 300 ms `[M0 = 300 ms]` else cache design | S0 | measurement in capacity sheet. **S0: not met** (naive 760-820 ms, per-request folder cache 330-405 ms); recheck design delivered in S1. **S1: not met** with the corrected probe (50 hits, worst user: shipped design 377 ms, best variant 308 ms, FS setup apart); waiver accepted in ADR-0010 (2026-10-17) (`02-performance.md`, `capacity-sheet.md`) |
| A-PERF-4 | Capacity sheet filled; caps set | S0 | `capacity-sheet.md`; caps table in `02-performance.md` (zip ratio and xlsx cells are policy defaults, proven in S2). **Met 2026-10-16** |
| A-QUAL-1 | OCR CER/WER <= `[M0 = CER 2 %, WER 5 %]` on Spanish gold set | S2 | `make eval-ocr` (TBD-S2). S0 baseline on clean synthetic pages: CER 0.07-0.16 %, WER 0.5-1.1 % (scanned and deskewed photos); provisional until real documents (open question #2) |
| A-QUAL-2 | recall@10 keyword/semantic/hybrid >= `[M0 pending S4]` | S4 | `make eval-search` (TBD-S4). Not measurable in S0 (needs the gold set on real text); S4 must also show the head-term pre-limit does not hurt recall |
| A-QUAL-3 | Filtered recall (narrow user vs 200-group admin) within 5 points `[M0 = 5 points]` | S0 (indicative), S4, S7 (1M-equivalent) | eval report. **S0 (indicative, 3M clustered synthetic + 30k real):** 200-group admin = unfiltered by construction; narrow user 4 points below on the ANN path at `ef_search` 200 (8 at 400), recall 1.0 on the exact path used below ~2 000 readable files; S4 must confirm on real embeddings |
| A-QUAL-4 | Hybrid beats both single modes on gold set | S4 | eval report |
| A-OPS-1 | Backfill ETA within stated margin; pause/resume across restarts | S5 | restart test |
| A-OPS-2 | Backup includes page text; restore rehearsal; measured rebuild time | S5, S7 | runbook + rehearsal log |
| A-OPS-3 | Nightly reconciliation repairs missed events; per-file status visible | S3 | reconciliation test |
| A-OPS-4 | No outbound network during indexing and search | S5 | egress test |
| A-OPS-5 | Clean install on NC 34 from tarball | S7 | install job |
| A-UX-1 | Mockup covers every state in the state matrix | U0 | mockup review artifact |
| A-UX-2 | Mockup accepted by user | U0 | design-review verdict |
| A-UX-3 | Microcopy uses glossary terms; no jargon or vendor names | U0 | `bd-ux-auditor` report |
| A-UX-4 | axe clean | S6 | Vitest + axe |
| A-UX-5 | Keyboard-only path search → open result | S6 | e2e |
| A-UX-6 | 360 px, no horizontal scroll | S6 | e2e viewport |
| A-UX-7 | Initial JS <= 250 KB gzip; barrel-import gate | S6 | CI size budget |
| A-UX-8 | `prefers-contrast: more` and reduced motion honoured | S6 | e2e |
| A-DOC-1 | Spanish site pages drafted per slice; `make html` + `term-check` green in scratch checkout | each | `/bd-docs` report |
| A-PERF-5 | Page size 50 and 100: keyword p95 < 1.5 s, hybrid p95 < 2.5 s `[M0 = 1.5 s / 2.5 s]` | S4, S7 | load test report per page size. S0 indicative (SQL stage, 5 searchers): keyword 0.22 s, hybrid 0.35 s; recheck cost scales with the page and is the open item |
| A-FUNC-1 | Paging: sizes 25/50/100, summary "Mostrando a–b de N", first/last/prev/next, filters or sort reset to page 1, 1 000 cap message | S4, S6 | API + e2e tests |
| A-FUNC-2 | Order: relevance, newest, oldest, name A-Z/Z-A are correct and stable across pages (keyset, no duplicates or gaps) | S4 | property test on a fixture with ties and permission changes between pages |
| A-UX-9 | Colours and fonts come only from the APS Conecta theme variables; no hard-coded colours; small text contrast >= 4.5:1 (dark gold for small gold text) | U0, S6 | style lint + axe + `prefers-contrast: more` check |
| A-UX-10 | Layout uses `NcContent`/`NcAppNavigation`/`NcAppContent`/`NcAppSidebar` and `NcButton` variants; one primary button per area | S6 | component test + peer-comparator vs farmacia |
| A-UX-11 | Mockup covers paging, order, filters, sidebar and layout | U0 | mockup review artifact |


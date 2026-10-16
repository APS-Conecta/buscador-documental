# CROSSREF: audit findings and where v3 resolves them

Audits run 2026-10-15 (cross-file, internal feasibility, environment). IDs from the audit reports.

| ID | Finding | Resolution in v3 |
|---|---|---|
| X1 (D1/A1) | No state store in OpenSearch design | ADR-0001/0002: Postgres is state + search |
| X2 (A2/A6) | Blue/green vs ACL/event dual-write; seam claim "A untouched" | ACL per file (ADR-0003); vectors in `chunk_vec_<gen>`; keyword unaffected by re-embed |
| X3 (A3) | Recheck misses exclusion tag; staleness unbounded | recheck includes exclusion tag; staleness = false negatives only (01 §3) |
| X4 (A4/D5) | Dedupe vs per-copy ACL | ADR-0005 compute-only |
| X5 (A5/G3) | "semantic required" vs "keyword first" | 00-scope release table; U0 decides disabled-with-progress |
| X6 (A8/E9) | `document_changes` and facts have no owner | NOT in search release; P6 owns a feed from outbox/`file_state` (banner in P6) — **still open: user to confirm** |
| X7 (A6 audit) | Backfill hours unmeasured | withdrawn; S0 capacity sheet (02) |
| X8 (A7) | Latency budget under-specified, recheck weak | budget table, refill design, S0 measurement (02) |
| X9 (A10/D3) | Group-only principals vs direct shares | `u:<uid>` principals + live "shared with me" (ADR-0003) |
| X10 (A11/D2) | Worker channel unspecified; worker could set ACL | ADR-0007, DB role without `file_acl` write |
| X11 (A12) | Nightly batch vs listeners | event-driven + nightly reconciliation (00, 01) |
| X12 (A13) | No audit log | `audit_log` (01, 04) |
| X13 (A14) | OpenSearch security | moot unless trigger fires; then new ADR must include TLS/auth/pin/snapshots |
| X14 (A15/F3) | Rebuild vs backup contradiction | P7 amended: `aps_search` backup required |
| X15 (A16) | Slice ordering | PLAN.md order and gates; docs per slice |
| X16 (A17) | Profile flapping | ADR-0004 never auto-switch after install |
| X17 (B1/B5/B6) | Slice numbering in matrix | matrix rows re-pointed to v3 slices + pointer to `buscador/acceptance.md`; P3 rows without a v3 A-id are marked as traceability only |
| X18 (B3) | Opt-in row vs index-everything | ADR-0006 opt-in default; matrix row replaced |
| X19 (B9/C3) | `[M0]` owner | search/OCR numbers in S0; others P2 S8 |
| X20 (C2/C4) | Gold set size; preflight tied to Ollama | P2 S4 = 200 queries; preflight addendum |
| X21 (E1-E3) | P4 duplicates retrieval/embeddings | banner in P4; P4 consumes `retrieve()` |
| X22 (F1/F2/F5) | P7 compose lacks engine/dim params, licences | P7 S3 amended; licence register for new stack still TODO in S0 |
| X23 (G1) | l10n | ADR-0008 proposed (Spanish literals) |
| B-series | scope bloat | 00-scope "Cuts" table |

## Still open after v3
X6 owner decision; app id/repo name; counsel answers; real corpus; docs-repo PR acceptance (open-questions.md).

## Round 2 (re-audit of v3, 2026-10-15)
Fixed: worker write path and trust boundary (SECURITY DEFINER + lease fencing), principal model gaps (circles, `x:recheck`, share expiry/download-disabled, multi-mount, per-user path), sandbox mechanism, head-term FTS risk, pgvector >= 0.8 and iterative-scan settings, profile eligibility rules, S0 as throwaway spike, legal gate moved to S7, S1 principal writer, copy-on-hit dedupe, chunk budget, tsvector/config versioning, OR-relaxation and synonyms, maintenance, audit minimisation, mode naming, outbox table, `rotate-secret`, README/matrix/P4/P7 stale rows, workflow status chain, mockup states/modes/copy, glossary format and Spanish drafts.
Not fixed on purpose (needs user or measurement): X6 changes-feed owner, app id, docs-repo PR acceptance, pgvector version/planner behaviour (S0 research), mockup not yet rendered in a browser.

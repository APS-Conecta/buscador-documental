# 00. Scope: Buscador Documental v3

Status: draft v3 (2026-10-15). Supersedes `P3-buscador-documental.md` and the search parts of `P3-document-intelligence-core.md`. Language of this file: English (engineering). User-facing text is Spanish and lives in `docs-site/`.

## Goal
Every user finds, by keywords or by meaning, any document they may read in Nextcloud, including scanned and photographed ones, in a Spanish interface, on hardware that differs per clinic.

## Non-goals (release 1)
Chat/RAG (P4), shipped models (P5), Informe semanal (P6), taxonomy and expiry extraction, handwriting OCR, external-storage indexing guarantees, multi-clinic tenancy (one install per clinic), languages other than Spanish (the `lang` column exists, no detection).

## Releases
| Release | Contains | Gate to enable |
|---|---|---|
| R1 | OCR + keyword search (Exacta), unified-search provider, sidebar app, admin basics, opt-in folders | S3 counsel/DPIA gate before any real-data install |
| R1 semantic | Por significado / Mixta modes on the same app | vector coverage >= threshold (set in S0) **and** hybrid beats both single modes on the gold set (A-QUAL-4); until then the modes are shown disabled with a progress note |
| R2 | items in "Cuts" below, when their trigger fires | per item |

## Decisions (v3)
| Topic | Decision |
|---|---|
| Engine | Postgres: FTS (`spanish` + `unaccent`) and pgvector `halfvec`. OpenSearch only if an ADR-0001 trigger fires. |
| State | Same Postgres is the system of record (ADR-0002). |
| ACL | Principals stored per **file**, not per chunk; live recheck authoritative (ADR-0003). |
| Embedding profile | Auto-recommended by preflight at first install, stored in an editable catalogue/setting, edited by an admin, never auto-switched afterwards (ADR-0004). |
| Dedupe | Compute only (content-hash cache); chunks stay per file (ADR-0005). |
| Default scope | Opt-in folders until counsel + DPIA sign; `scope_mode = opt_in | all_readable`; the `rag-excluded` tag always wins (ADR-0006). |
| Freshness | Event-driven continuous indexing; nightly = reconciliation and backfill window; per-file status visible. |
| Result unit | Chunk index, results grouped per document with best page. |
| Versions | Current version only; trash/old versions purged after 30 days (assumption). |
| OCR quality | Index what we can, flag low confidence, allow retry (owner or admin, rate-limited). |
| Targets | keyword p95 < 1 s, hybrid p95 < 2 s at 1M-page equivalent (measured in S0/S7). |
| Surfaces | Unified search (keyword, top 5, no model call) + sidebar app "Buscador Documental" + minimal Files tab. |

## Format tiers (R1)
1. Full text with pages: PDF text layer, docx, odt, pptx, xlsx (capped), txt, md.
2. OCR: jpg, png, tiff, scanned and mixed PDFs (per-page text-layer detection, EXIF rotation, OSD, deskew, `spa+eng`).
3. Everything else: filename and metadata only; listed as "no legible" in the skipped-files table. (ods/odp/rtf/csv/html/email move to R2.)
Encrypted content is skipped with a visible reason.

## Cuts from the previous plan (each reversible)
| Cut | Bring back when |
|---|---|
| GPU profile automation and blue/green alias | a clinic has a GPU and measured gain justifies it; until then profile change = offline re-embed into a new `chunk_vec_<gen>` table |
| Page-level vectors | chunk vectors miss the memory budget on a measured box |
| Synonym editor | requested by an admin; R1 ships a static versioned starter file |
| `.ragignore` | tag-only exclusion proves too coarse |
| Meilisearch comparison | never (already rejected) |
| Per-user "delete my index" | counsel asks; erasure on user deletion and `occ purge-user` stay |
| Tier-3 text formats | demand shown by the skipped-files table |
| Separate low-OCR admin list | folded into skipped-files table filter |
| `occ` surface | kept to `status, reindex, pause, resume, purge-user, doctor, profile, rotate-secret` |

## Carried assumptions
~5-10 concurrent searchers per clinic; 30-day grace period; synthetic Spanish corpus until a de-identified real folder exists; app id/repo name pending (P2 S6), placeholder `buscador`.

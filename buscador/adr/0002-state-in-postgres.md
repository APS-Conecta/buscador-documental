# ADR-0002 — Postgres is the system of record for index state

Status: accepted (2026-10-15)
Affects: buscador schema `aps_search`, worker contract

## Context
Jobs need leases and compare-and-set; tombstones must beat earlier events; page text and OCR confidence must survive an engine rebuild.

## Decision
Tables `file_state`, `job`, `page`, `chunk`, `chunk_vec_<gen>`, `file_meta`, `file_acl`, `content_cache`, `outbox`, `tombstone`, `embedding_profile`, `audit_log` (see 01-architecture). Per-lane state (`text_state`, `vec_state`) plus boolean `acl_dirty`. Expand/contract migrations; the worker refuses an unknown schema version.

## Consequences
Backups include page text (OCR is the expensive part to redo). Rebuilding vectors never requires re-OCR.

# ADR-0005 — Deduplicate compute, not chunks

Status: accepted (2026-10-15)
Affects: `content_cache`, worker

## Context
Identical files in different folders have different permissions; sharing chunks would need a union ACL and complicate deletion.

## Decision
On a `content_hash` match (same `ocr_ver`, `chunker_ver`, profile) copy the existing file's `page`, `chunk` and vector rows to the new `file_id` (`INSERT … SELECT`); each `file_id` keeps its own rows and its own `file_acl`. No shared payload and no reference counting.

## Consequences
Storage is not deduplicated; OCR and embedding cost is. Deleting a copy never affects another copy.

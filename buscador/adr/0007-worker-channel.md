# ADR-0007 — Worker channel: pull, HMAC, app-served bytes

Status: proposed (confirmed in S1)
Affects: worker, app endpoints, OpenAPI contract

## Context
OCR and embedding must not run in PHP cron. The worker must read file bytes consistently with Nextcloud storage (encryption, external storage) and must not decide permissions.

## Decision
Worker pulls jobs and writes results only through SECURITY DEFINER functions that fence on `job.id` + `lease_epoch` + `(file_id, etag)` and force the claimed `file_id` on every inserted row (no direct DML for the worker role). Bytes and meta come from an app endpoint (Range with `If-Match`, only for files with an active claim, system root), authenticated with bearer + HMAC and a timestamp window. Trust boundary: worker trusted for the content of claimed files only, never for ACL. Contract is OpenAPI, versioned, with contract tests on both sides. Server-side-encrypted and E2EE files are skipped with a reason; external storage is best effort and flagged.

## Consequences
No shared volume or WebDAV dependency. Secret rotation via `occ`. STRIDE review in S7.

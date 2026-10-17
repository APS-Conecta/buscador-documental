# ADR-0013 — Level-triggered events, durable state in Postgres, erasure at once

Status: accepted (2026-10-17, S3 design review)
Affects: `app/lib/Listener/*`, `ProcessOutbox`, `Reconcile`, `IndexEraser`, `outbox`, `tombstone`, `audit_log`, A-SEC-3, A-SEC-6, A-OPS-3

## Context
Nextcloud 34 gives incomplete and uneven events (S3 research, verified): a folder move fires one cache event for the root only; user deletion clears the file cache with no per-file events; tag deletion, share updates and groupfolder ACL/grant changes have no typed event; mount events fire only when a user's filesystem is set up. A `QueuedJob` is removed from the job list before it runs and a crashed `TimedJob` stays reserved for 12 hours; job arguments are logged at debug level.

## Decision
1. **Level-triggered processing.** Listeners store only a file id and a kind in `outbox` (deduplicated, never throwing). The processor recomputes truth from the file cache, tags, shares and scope rules for each row, so loss, duplication and reordering cannot corrupt the index. A nightly reconciliation (set-based, resumable) covers every change that produced no event.
2. **Durable state in Postgres.** Jobs are triggers only; queue, cursor, settings and tombstones live in tables. Job arguments never contain paths, names or uids.
3. **Removal before addition.** Exclusion, scope exit and erasure run before indexing and are not held back by pause.
4. **Erasure at once.** `rag-excluded` (file or ancestor), trash and user deletion delete chunks, pages, vectors, ACL and metadata immediately (`erase_files`, one transaction per batch, same lock order as the worker functions). A tombstone ledger entry (30-day `purge_after`) records trash. A restored file is read again in full unless an identical file is still indexed (ADR-0005 dedupe is copy-on-hit, not a cache).
5. **Audit with pseudonyms.** Actor and subject are random tokens from a `pseudonym` table that holds the uid only while the user exists; erasing the user deletes the mapping, so audit rows become unlinkable. `detail` is a closed schema. Retention 90 days (counsel default).
6. **Exclusion tag is public and user-assignable**, so folder owners can opt a folder out.

## Consequences
- The index may lag a change by up to one processor interval, or one night for changes that produce no event; the live recheck keeps reads correct meanwhile (ADR-0003).
- Groupfolder ACL/grant changes are found by a 15-minute fingerprint poll, not an event.
- Anyone who can edit a file can trigger erasure of its index data by tagging it (the file itself is untouched).
- Reconcile cost at 1M files is unmeasured until the S3 plan's `EXPLAIN` check.

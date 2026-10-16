# ADR-0003 — ACL principals per file, live recheck authoritative

Status: accepted (2026-10-15)
Affects: `retrieve()`, `file_acl`, worker DB role

## Context
Per-chunk ACLs make every share or folder-ACL change a mass update. Group-only principals cannot express direct shares.

## Decision
Store `principals text[]` (`g:<gid>`, `u:<uid>` for owner and direct shares) once per file with a GIN index; filter with `&&` against the caller's principals; then recheck every candidate with `getUserFolder($uid)->getById()` + `isReadable()` + exclusion tag before building snippets. Only Nextcloud writes `file_acl`; the worker DB role has no privilege on it. "Shared with me" is resolved live and intersected. Link and federated shares rely on the recheck only.

## Consequences
ACL change cost is one row per file. Prefilter staleness yields only false negatives, never leaks. Recheck cost is budgeted and measured in S0 (target p95 < 300 ms for 50 hits).

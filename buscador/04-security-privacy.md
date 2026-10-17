# 04. Security and privacy

Status: draft v3. Engineering doc.

## Data classification
Indexed text may contain clinical or personal data even though the suite is "no patient data" by policy: staff can upload any file. Treat the index as sensitive: same trust level as the files, lives on the same box, backups encrypted.

## Gate on real data (blocks any install on real documents)
1. Counsel confirms the legal basis under Ley 21.719 and Ley 20.584 and the effective date (P2 S7).
2. DPIA exists with an owner.
3. Admin sees the plain-language first-run notice; "Pausar lectura" is one click; per-folder exclusion preview works.
4. Default scope is `opt_in` until 1-3 are recorded in an ADR. Flipping to `all_readable` is a config change, not code.

## Threat model (STRIDE-lite, expanded in S7)
| Asset / boundary | Threat | Control |
|---|---|---|
| Result list (NC → user) | Information disclosure: hit for a file the user cannot read, stale ACL | prefilter + authoritative live recheck + exclusion-tag check before snippets; stale-index test |
| Worker → DB | Elevation / cross-file poisoning: worker widens ACL or writes text under another file | worker has no direct DML; SECURITY DEFINER `write_result` forces the claimed `file_id` and fences on lease epoch + etag; NC alone computes principals; byte endpoint serves only actively claimed files; zombie-worker test in S1 |
| Worker ↔ app channel | Spoofing, replay, tampering | bearer + HMAC (method, path, body hash, timestamp window), TLS or private network, secret in sensitive `IAppConfig` with `occ` rotation |
| Hostile file | DoS, code execution in parsers, secret theft | supervisor holds secrets; parsing runs in a separate `parser` container (ADR-0011): no network, no secrets, `cap_drop: ALL`, `no-new-privileges`, Docker's default seccomp profile (no custom one), cgroup memory/CPU/pids limits, `RLIMIT_FSIZE` and `RLIMIT_CORE` on every child, read-only rootfs + tmpfs, `init`, restart policy and a heartbeat health check; the secret-holding worker is read-only and capability-free too; no Docker socket; the supervisor validates every answer. Tests: `make -C worker test-sandbox` (9 properties), hostile-file suite, `test-e2e-formats`. Residual: supervisor and parser share one work volume under one uid (S7 hardening: per-job directories, distinct uids, a custom seccomp profile, `RLIMIT_AS`); caps, size-scaled per-file timeout, `skipped` outcome |
| Snippets in browser | XSS | server-side escape, `ts_headline` with safe delimiters replaced after escape, CSP |
| Logs / errors / history | Content leak | log-hygiene canary in CI (no document text, no query text); audit log stores ids and actions only |
| Query text | Profiling of users | not stored; audit log excludes it; rate limits |
| Backups / snapshots | Disclosure | encrypted, access-controlled, retention stated; included in restore rehearsal |
| Model bundle | Supply chain | pinned version, sha256 check at start, licence register entry, no runtime download |
| Deletion / exclusion | Residual data | exclusion and erasure delete chunks, vectors, pages, cache entries; verified by test |

## Audit log
Fields: time, actor, action (`retry_ocr`, `exclude`, `pause`, `resume`, `reindex`, `scope_mode_change`, `profile_change`, `purge_user`, plus `open_hit` only if counsel requires access records), file id when relevant. Searches are counted in aggregate only (no per-user search log). No query text, no content. Retention 90 days (counsel to confirm). Pseudonymised on user erasure.

## Privacy surfaces for users
A short page "Qué lee el Buscador y qué no" (site: usuario page) covering: what is read, who sees what (same as Files), how to exclude a folder (`rag-excluded` tag), that nothing leaves the server.

## Compliance traceability
Acceptance rows A-SEC-* in `acceptance.md`; counsel and DPIA outcomes recorded as ADR-0006 supersession note.

## HIPAA & NIST SP 800-111 Encryption at Rest (AUD-HIPAA-014)
- **Mandatory Storage Encryption:** Under HIPAA Security Rule 45 CFR § 164.312(a)(2)(iv) and NIST SP 800-111, all PostgreSQL storage directories hosting OCR text and precomputed full-text tsvectors (`aps_search.page` and `aps_search.chunk`) must be encrypted at rest using AES-256 (LUKS2 host volume encryption or transparent tablespace encryption).
- **Access Control & Least Privilege:** Workers have no direct table SELECT/UPDATE privileges on `page` or `chunk`; insertions and modifications are guarded strictly by SECURITY DEFINER routines (`write_result`).
- **Encrypted Backup Targets:** All database backups, basebackups, and snapshots containing search indexes must be encrypted with AES-256 before offsite replication or disk archiving.

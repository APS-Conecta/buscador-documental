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
| Hostile file | DoS, code execution in parsers, secret theft | supervisor holds secrets; parser runs as a child with scrubbed env, own network namespace (no route to Postgres/app), rlimits, seccomp profile, cgroup v2 limits, read-only rootfs + tmpfs; no Docker socket; S2 test: parser cannot read worker env or reach Postgres; caps, per-file timeout, `skipped` outcome |
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

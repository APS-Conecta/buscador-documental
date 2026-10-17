# ADR-0011 — Parsing runs in a separate no-network container, not under `unshare`

Status: accepted (2026-10-17)
Affects: `worker/`, `parser/`, `compose.yaml`, A-SAFE-2, `01-architecture.md` (worker sandbox)

## Context
`01-architecture.md` required each parser/OCR step to run as a child of the worker supervisor in its own network namespace (`unshare -n`). S2 tested that on 2026-10-17 in the S1 worker image under Docker's default seccomp and no extra capabilities: `unshare -n` and `unshare -rn` both fail with "Operation not permitted". Making them work needs a weaker seccomp profile or `CAP_SYS_ADMIN`, which weakens the container that holds the DB and HMAC secrets. The same image started with `--network none --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges --pids-limit 64 --memory 512m` runs Python, cannot resolve `db`, and does not see `BD_DB_DSN`.

## Decision
Two compose services. `worker` (supervisor) holds the database role, the HMAC secret and the network, downloads the file, and never parses it. `parser` runs extraction and OCR with `network_mode: none`, read-only rootfs, tmpfs scratch, `cap_drop: [ALL]`, `no-new-privileges`, `pids_limit`, `mem_limit`, a non-root user and no secrets. They exchange one input file and one result JSON per job through a shared tmpfs volume. The supervisor enforces the per-file timeout and kills the parser's process group on expiry. No Docker socket is mounted.

## Consequences
- A-SAFE-2 is proven by tests that run inside `parser`: no `BD_*` in the environment, no route to `db`, no write outside tmpfs, bounded processes and memory.
- Two containers per worker deployment; the per-job IPC adds a file copy.
- `01-architecture.md` is updated: the supervisor/child wording stays, the mechanism changes from `unshare` to a separate container.
- If a host ever allows unprivileged user namespaces cleanly, a single-container variant can be reconsidered in a new ADR.

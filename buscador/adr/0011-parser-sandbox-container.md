# ADR-0011 — Parsing runs in a separate no-network container, not under `unshare`

Status: accepted (2026-10-17)
Affects: `worker/`, `parser/`, `compose.yaml`, A-SAFE-2, `01-architecture.md` (worker sandbox)

## Context
`01-architecture.md` required each parser/OCR step to run as a child of the worker supervisor in its own network namespace (`unshare -n`). S2 tested that on 2026-10-17 in the S1 worker image under Docker's default seccomp and no extra capabilities: `unshare -n` and `unshare -rn` both fail with "Operation not permitted". Making them work needs a weaker seccomp profile or `CAP_SYS_ADMIN`, which weakens the container that holds the DB and HMAC secrets. The same image started with `--network none --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges --pids-limit 64 --memory 512m` runs Python, cannot resolve `db`, and does not see `BD_DB_DSN`.

## Decision
Two compose services. `worker` (supervisor) holds the database role, the HMAC secret and the network, downloads the file, and never parses it. `parser` runs extraction and OCR with `network_mode: none`, read-only rootfs, tmpfs scratch, `cap_drop: [ALL]`, `no-new-privileges`, `pids_limit`, `mem_limit`, a non-root user and no secrets. They exchange one input file and one result JSON per job through a shared tmpfs volume. The supervisor enforces the per-file timeout and kills the parser's process group on expiry. No Docker socket is mounted.

## What is enforced (S2 review, 2026-10-17)
Enforced and tested: no network, no `BD_*` secrets, read-only rootfs, non-root, no capabilities, `no-new-privileges`, a pids limit and a fork-loop test, memory and CPU cgroup limits, `RLIMIT_FSIZE` (512 MiB) and `RLIMIT_CORE` 0 on every child, process-group kill on timeout, a parser that survives malformed requests and a heartbeat health check, `init` and `restart: unless-stopped`, answer validation and a size cap on the supervisor side.
Not enforced (earlier text overstated it): a custom seccomp profile (Docker's default applies), `RLIMIT_AS`/`RLIMIT_NPROC`, and isolation between jobs inside the shared work volume (one uid, one directory). A compromised parser dependency could therefore read other queued inputs or forge another job's answer within the volume. That needs a prior exploit of ocrmypdf, Ghostscript, Tesseract, Pillow, pikepdf, pypdf or openpyxl plus a `setsid` escape; S7 hardening replaces the shared directory with per-job directories under distinct uids and adds a seccomp profile.

## Consequences
- A-SAFE-2 is proven by tests that run inside `parser`: no `BD_*` in the environment, no route to `db`, no write outside tmpfs, bounded processes and memory.
- Two containers per worker deployment; the per-job IPC adds a file copy.
- `01-architecture.md` is updated: the supervisor/child wording stays, the mechanism changes from `unshare` to a separate container.
- If a host ever allows unprivileged user namespaces cleanly, a single-container variant can be reconsidered in a new ADR.

## Addendum (S7, STRIDE review H7)
The hardening this ADR deferred to S7 (per-job directories, distinct uids) is not needed for the design as built: a worker process claims one job at a time, `jobbox.prepare` empties `in/` and `out/` before every submission and the submission cleans up after itself, so the shared work volume holds only the file being parsed. A compromised parser can forge only the answer for that file, which the supervisor validates and the application re-checks at search time; filling the volume delays only the current job. What this relies on: **one `/work` volume per worker replica** (never shared by two supervisors) and one job at a time per supervisor. If either changes, per-job directories and distinct uids become mandatory.

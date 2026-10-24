# ADR-0015 — Operations controls live in Postgres and are enforced where work is handed out

Status: accepted (2026-10-18)
Affects: `claim_job`, `settings`, `worker_seen`, `occ buscador:window|throttle|doctor|rotate-secret|adopt-secret`, compose networks, backup tooling

## Context
S5 needs a night window, adjustable concurrency, a way to tell whether a worker is alive, secret rotation, no internet for the worker and encrypted backups. The worker cannot read tables (ADR-0011/A-SEC-4), pause already lives in `settings` and is read by `claim_job`, and the queue is the checkpoint of every backfill.

## Decision
- The window (`work_window` "HH:MM-HH:MM", `work_tz`, `work_window_lanes` `vec` or `all`) and the caps (`cap_text`, `cap_vec`) are settings read by `claim_job`. Outside the window the claim returns nothing for the lanes it covers; pause wins over everything. Defaults change nothing: no window, caps 4 and 2. The window covers only the embedding lane unless the administrator says `all`, so edits and new files are still indexed by day.
- `claim_job` records `worker_seen(worker, lane, at)` for every call, paused or not, so `occ buscador:doctor` can distinguish "paused" from "no worker".
- `WorkerAuth` accepts the current secret and a previous one until an expiry set by `occ buscador:rotate-secret`; rotation never interrupts a running worker inside the grace period.
- `occ buscador:adopt-secret` (instalador #43) takes the worker secret from `BD_WORKER_SECRET` in the environment — the suite runs it at install time instead of rotating by hand. Adopting a different secret ends any rotation grace, because the suite's worker knows only the environment's secret; adopting the secret already in use changes nothing (a repeated provisioning run).
- The worker container is on an `internal: true` network with the database, Nextcloud and the embedding endpoint; it has no route to the internet. A remote embedding endpoint is the administrator's deliberate exception.
- Backups are `pg_dump -Fc -n aps_search` encrypted with AES-256 (openssl, PBKDF2) before they leave the host; the restore script refuses a target that lacks the roles or extensions.

## Consequences
- Operators change behaviour with `occ` and the change takes effect on the next claim, without restarting anything.
- A window set in a time zone that PostgreSQL does not know is ignored (the work runs) rather than blocking the index; the occ command validates the zone first.
- Egress is enforced by the network, not by trust in the code; the static test that only `EmbedClient` makes HTTP calls is a second line.

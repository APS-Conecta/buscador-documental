---
slice: S5
step: validate
status: ready
inputs: [reviews/2026-10-18_20-00-00_S5_review.md]
acceptance_ids: [A-OPS-1, A-OPS-2, A-OPS-4]
---
# S5 final validation (after the review fixes)

| Check | Result |
|---|---|
| `make -C app test-unit` | OK, 286 tests, 1233 assertions |
| `make -C app test-contract` | OK, 2 tests |
| `make -C app test-db` | OK, 248 tests, 892 assertions |
| `make -C app test-search` | OK, 37 tests, 134 assertions |
| `make -C worker test` / `test-glossary` | 220 passed, 1 skipped / 10 passed |
| Live, CI order: canary (20), sandbox, formats (12), groupfolder (4), scope (19), events (12), erasure (8), reconcile (5), logs (6), rbac (22), vectors (24), **egress (5), restore (16), ops (15)**, e2e-one | all OK |
| A-OPS-1 | pause survives restarting database, Nextcloud and the parser; resume finishes the work; window and caps obeyed by the real worker; ETA within +-30 % for steady rates (200 random cases) |
| A-OPS-2 | encrypted backup holds text and vectors, wrong key/missing extension/missing checksum refused, restore runs as `bd_owner`, restored database answers a search with all chunks and vectors (3 files: backup 0.5 s, restore 1.1 s) |
| A-OPS-4 | worker cannot reach public addresses or resolve names (probe also proven able to see a leak on the default network); parser has no network; a file is still read and found; only `EmbedClient` makes outbound calls in PHP |
| Negative controls | the egress probe succeeds on the default network; a rotated-away secret is rejected after the cache window; a double rotation cuts the first secret off |

Not run: GitHub CI for the new steps; the 1M-page restore rehearsal and load test (S7); DNS tunnelling probe.

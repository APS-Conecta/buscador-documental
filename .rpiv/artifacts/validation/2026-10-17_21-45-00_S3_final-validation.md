---
slice: S3
step: validate
status: ready
inputs: [.rpiv/artifacts/reviews/2026-10-17_21-30-00_S3_review.md]
acceptance_ids: [A-SEC-1, A-SEC-3, A-SEC-6, A-OPS-3]
---
# S3 final validation (after the review fixes, batches A, B and C)

| Check | Result |
|---|---|
| `make -C app test-unit` | OK, 154 tests, 253 assertions |
| `make -C app test-contract` | OK, 2 tests |
| `make -C app test-db` | OK, 149 tests, 544 assertions |
| `make -C app test-search` | OK, 9 tests |
| `make -C worker test` | 193 passed, 1 skipped |
| `make -C worker test-sandbox` | all checks ok |
| Live, CI order: canary (20), formats (12 files), groupfolder (4), scope (19), events (12), erasure (8), reconcile (5), logs (6), rbac (22), e2e-one | all OK |
| `make -C app clean` | 0 `bd-s1` containers left |

Not run here: GitHub CI for the S3 steps; `bench-reconcile` was not repeated (no change to the reconcile SQL since phase 6 except `due()`/housekeeping gating).

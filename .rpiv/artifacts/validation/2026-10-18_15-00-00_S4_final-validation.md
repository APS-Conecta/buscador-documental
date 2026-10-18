---
slice: S4
step: validate
status: ready
inputs: [reviews/2026-10-18_14-00-00_S4_review.md]
acceptance_ids: [A-QUAL-2, A-QUAL-3, A-QUAL-4, A-PERF-2, A-PERF-5, A-FUNC-1, A-FUNC-2]
---
# S4 final validation (after the review fixes)

| Check | Result |
|---|---|
| `make -C app test-unit` | OK, 254 tests, 564 assertions |
| `make -C app test-contract` | OK, 2 tests |
| `make -C app test-db` | OK, 202 tests, 745 assertions |
| `make -C app test-search` | OK, 37 tests, 134 assertions |
| `make -C worker test` | 219 passed, 1 skipped |
| `make -C worker test-glossary` | 10 passed |
| Live, CI order: canary (20), sandbox, formats (12 files), groupfolder (4), scope (19), events (12), erasure (8), reconcile (5), logs (6), rbac (22), vectors (24), e2e-one | all OK |
| Planted regressions | removing the unmodelled-file recheck and the `text_state` guard each fail their new tests; a broken refill position fails the paging tests |
| `make -C app eval-search` (bge-m3, qwen3-embedding:0.6b) | recall@10 Exacta 0.53, Por significado 0.76/0.77, Mixta 0.79/0.81; run before the review fixes, none of which touches ranking |

Not run: GitHub CI for the new steps; real documents; 1M-page latency; the semantic modes stay off until an administrator turns them on.

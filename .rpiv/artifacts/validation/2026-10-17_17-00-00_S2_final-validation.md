---
slice: S2
step: validate
status: ready
inputs: [.rpiv/artifacts/validation/2026-10-17_14-00-00_S2_validation.md, .rpiv/artifacts/reviews/2026-10-17_15-00-00_S2_review.md]
acceptance_ids: [A-QUAL-1, A-SAFE-1, A-SAFE-2, A-SAFE-3]
---
# S2 final validation (branch s2-extraction after the review fixes, commit e65485b plus doc edits)

Supersedes the earlier S2 validation, which ran before the review fixes.

| id | command | result | excerpt |
|---|---|---|---|
| all | `make -C worker test` | pass | 180 passed, 1 skipped (real laws, local fixture) |
| P4 | `make -C worker test-laws` | pass | 6 real laws; every page appears as a chunk `page_no`; max chunk 350 tokens; article headers found 14/14, 25/27, 59/59, 48/48, 61/61, 84/86 |
| A-SAFE-2 | `make -C worker test-sandbox` | pass | 9 isolation properties |
| live parser | malformed request injected into the running parser | pass | answered `internal`, file removed, health `healthy`, restarts 0, `init` true |
| app | `make -C app test-unit` / `test-db` / `test-search` / `test-contract` | pass | 92 tests / db OK (29) / search OK (8) / contract OK (2), all re-run after the last PHP change |
| A-SAFE-1/3 | `make -C app test-e2e-formats` | pass | formats OK (12 files), sources byte-identical |
| regression | `make -C app test-e2e-one` | pass | e2e-one OK (stored confidence) |
| A-SEC-1..3 | `make -C app test-canary` | pass | canary OK (20 checks), after a regression this batch introduced (share type 2) was caught by the canary and fixed |
| A-QUAL-1 | `make -C worker eval-ocr PAGES=400` | pass | 406 pages, CER 0.25 %, WER 0.57 %, 0 catastrophic; 2 461 s; `spikes/s2/results-s2/ocr_eval_400p_final_PASS.json` |
| docs | `term-check` on the three Spanish drafts in a docs-repo scratch copy | pass | 544 pages, 0 problems |
| cleanup | `make -C app clean` | pass | 0 `bd-s1` containers |

## Notes
- Evidence limits unchanged: synthetic corpus; 4 photo and 2 invisible-fake-layer pages; `fake_visible` CER 3.9 % because the visible garbage line is read as text.
- CI for the new steps (`test-sandbox`, `test-e2e-formats`) has not run on GitHub.
- Deferred with reasons in the review: shared work volume isolation (S7), re-OCR and double OCR cost (S5).

---
description: Dispatch reviewer agents on the diff or doc set and write a report
argument-hint: <slice>
---
Slice: $ARGUMENTS. Launch in parallel: `bd-diff-auditor`, `bd-peer-comparator`, `bd-acl-adversary` (any slice touching permissions or the worker: S1-S4, S7), `bd-ux-auditor` (U0, S4 mode UI, S6, and any `docs-site/` page). Then `bd-claim-verifier` on the merged report. Write `reviews/<ts>_<slice>_review.md` with severity-ranked, verified findings only. Next: fix, `/bd-docs`, `/bd-commit`.

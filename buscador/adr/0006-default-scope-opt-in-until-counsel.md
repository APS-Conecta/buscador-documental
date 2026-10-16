# ADR-0006 — Opt-in folders until counsel and DPIA sign

Status: accepted (2026-10-15); to be superseded when the legal gate passes
Affects: scope filter, first-run notice, S3

## Context
The earlier decision was "index everything readable", gated on counsel (Ley 21.719 / Ley 20.584). Clinical files may exist despite the "no patient data" policy.

## Decision
`scope_mode = opt_in` by default. `all_readable` is selectable only after counsel confirmation and a DPIA are recorded. The `rag-excluded` tag always wins and deletes existing index data.

## Consequences
Same code path either way (one scope filter). A pilot on real data is safe before the legal answer.

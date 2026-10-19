# ADR-0006 — Opt-in folders until counsel and DPIA sign

Status: accepted (2026-10-15); to be superseded when the legal gate passes
Affects: scope filter, first-run notice, S3

## Context
The earlier decision was "index everything readable", gated on counsel (Ley 21.719 / Ley 20.584). Clinical files may exist despite the "no patient data" policy.

## Decision
`scope_mode = opt_in` by default. `all_readable` is selectable only after counsel confirmation and a DPIA are recorded. The `rag-excluded` tag always wins and deletes existing index data.

## Consequences
Same code path either way (one scope filter). A pilot on real data is safe before the legal answer.

## Release gate record (S7, A-SEC-7)
Status of the gate on 2026-10-19: **open, external**. The product is built, tested and installable, but it must not be installed on real documents until this table is filled in by the people who own each line; none of it can be decided or signed by the development work.

| Condition | Owner | Evidence to attach | State |
|---|---|---|---|
| Counsel's opinion on Ley 21.719 and Ley 20.584 for indexing the text of staff documents (legal basis, retention of the index and of the audit log; 90 days proposed) | counsel | opinion or memo reference | open |
| DPIA completed, with a named owner, covering the index, the optional remote embedding endpoint and backups | data protection owner | DPIA reference | open |
| Notice text for staff approved (the first-run notice in the administration page is the draft) | counsel and the establishment | approved wording | open |
| Decision on `all_readable` mode: stays off unless counsel and the DPIA both allow it | establishment | recorded decision | open |
| Pause and exclusion procedure known to the administrators (`occ buscador:pause`, `rag-excluded`, `occ buscador:purge-user`) | administrator | runbook read, drill done | documented in the administrator page of the site |

### Deferral decision (2026-10-19)
The project owner decided to allow installation while the four rows above are still **open**, and to resolve them later. This is a recorded deferral by the owner, **not** counsel's opinion, a DPIA, an approved notice or a decision on `all_readable`; none of those exist yet and no row is marked met. Consequences kept in force: scope stays opt-in folders, `all_readable` stays off, the first-run notice stays the draft, and the pause (`occ buscador:pause`) and `rag-excluded` remain the controls. Whoever installs on real documents before the rows are filled does so on the establishment's own responsibility, and the rows must still be filled in as they are obtained.

What is already in place whatever the answer: scope is opt-in folders by default; nothing leaves the server unless the administrator points the optional endpoint elsewhere; the `rag-excluded` tag always wins; erasure follows account deletion; the pause is one click. When every row is filled, this ADR is superseded by a short note recording the dates and references, and A-SEC-7 turns green.

---
description: Implement exactly one phase of the approved plan
argument-hint: <slice> [phase]
---
Slice and phase: $ARGUMENTS. Input: newest plan with `status: ready` or `accepted` (full path: its design must be `accepted`; blueprint path: the blueprint's micro-checkpoints were answered). Implement only that phase (default: first unchecked). Work only inside Docker for PHP/Python tools; compose projects prefixed `bd-`. Run the phase's success-criteria commands; tick the phase in the plan only if all pass, otherwise stop and report. Do not commit. Next: `/bd-implement` for the next phase, or `/bd-validate`.

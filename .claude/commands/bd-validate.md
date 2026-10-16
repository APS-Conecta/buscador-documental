---
description: Re-run every phase's evidence commands and report pass/fail
argument-hint: <slice>
---
Slice: $ARGUMENTS. Run each success criterion and each `acceptance.md` evidence command for the slice's ids. Write `validation/<ts>_<slice>_validation.md` (table id | command | result | output excerpt). On failure: stop, list failing ids; suggest `/bd-implement` fixes. Never mark pass without running. Next: `/bd-review`.

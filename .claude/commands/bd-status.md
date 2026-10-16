---
description: Show current slice, last artifact and the next command
argument-hint: [slice]
---
Read `buscador/PLAN.md`, then list the newest artifact per kind in `.rpiv/artifacts/` (filter by slice `$ARGUMENTS` if given). Determine the slice's path (full / blueprint / UI) and which step is next from the artifact `status` fields. Print: slice, path, last completed step with file, open gates, and the exact next command. Do not modify anything.

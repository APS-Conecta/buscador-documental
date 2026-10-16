---
description: Update the UI mockup, audit it, and request the human design-review
argument-hint: [change]
---
Change: $ARGUMENTS. Edit `buscador/mockup/index.html` (all states in `03-ui-guidelines.md`, 360 px, Spanish microcopy from glossary). Open-check it by reading the file structure; run `bd-ux-auditor` on mockup + guidelines; write `.rpiv/artifacts/reviews/<ts>_U0_mockup-audit.md`. Verification: confirm every state listed in 03-ui-guidelines is selectable in the mockup (grep the state selector) and, if a headless browser is available in Docker, render it at 1280 px and 360 px. Then HUMAN GATE: AskUserQuestion accept/adjust. On accept copy to `buscador/mockup/v1/` and record the verdict in `.rpiv/artifacts/verdicts/<ts>_U0_design-review.md`. Optionally offer to publish a private Artifact preview (ask first).

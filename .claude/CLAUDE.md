# Buscador Documental: working rules for Claude Code

Project: search + OCR for the APS Conecta Nextcloud 34 suite. Plan: `buscador/PLAN.md`. Acceptance: `buscador/acceptance.md`. Decisions: `buscador/adr/`. UI: `buscador/mockup/` + `buscador/03-ui-guidelines.md`.

## Workflow (rpiv-style, driven by slash commands)
`/bd-status` tells you the next step. One step = one command = one fresh context: run `/clear` between steps. State lives only in `.rpiv/artifacts/<kind>/`, never in the chat.
Chain per slice (see PLAN.md "Workflow path"):
- full: `/bd-discover` → `/bd-research` → `/bd-design` → `/bd-design-review` (human) → `/bd-plan` → `/bd-implement` → `/bd-validate` → `/bd-review` → `/bd-docs` → `/bd-commit`
- blueprint: `/bd-research` → `/bd-blueprint` → `/bd-implement` → `/bd-validate` → `/bd-review` → `/bd-docs` → `/bd-commit`
- UI (U0): `/bd-mockup` → ux-audit → human design-review.
Pause/resume: `/bd-handoff`, `/bd-resume <file>`.

## Windows
One slice per Claude Code window. Start it with `/bd-resume <handoff>` (or `/bd-status <slice>`), run `/clear` between steps, and end the window with `/bd-handoff` when the slice's exit criteria pass or the context gets heavy. Keep replies short; the work lives in artifacts.

## Artifact rules
- Path: `.rpiv/artifacts/<kind>/<YYYY-MM-DD_HH-MM-SS>_<slice>_<topic>.md` (kinds: discover, research, slices, designs, plans, validation, reviews, verdicts, handoffs).
- Frontmatter: `slice, step, status (draft|ready|accepted|failed), inputs: [paths], acceptance_ids: [..]`.
- A command reads only the artifacts named in its arguments or the newest matching one for the slice; it ends by printing the artifact path and the next command.

## Gates (human only, never auto-approved)
design-review, legal gate (release gate in S7; blocks any real-data install from the start), any push/PR to the org or the documentation repo, any install on real data. Ask with AskUserQuestion; no default answers.

## Conventions
English for code, comments, commits, ADRs. User-facing strings and site docs in Spanish (usted/impersonal, terms from the docs repo `glosario.yml`). Conventional Commits; follow the session's commit-trailer rule. ADRs in MADR format `# ADR-NNNN — Title` / Status / Affects / Context / Decision / Consequences. No interface with a single implementation. Never edit generated files (`js/`, `openapi.json`) by hand.

## Safety on this shared VPS
This box hosts other containers. Never kill processes by pattern, never stop/remove containers you did not create, bind only to localhost, use throwaway compose projects with a `bd-` prefix, clean only those. Nothing runs outside Docker (no PHP/Tesseract on the host).

## Reviewers
`bd-claim-verifier`, `bd-diff-auditor`, `bd-peer-comparator`, `bd-acl-adversary`, `bd-ux-auditor` (in `.claude/agents/`). Treat their output as claims to verify, not as truth.

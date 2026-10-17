# Buscador Documental

Document search and OCR app for the APS Conecta Nextcloud 34 suite: keyword and meaning-based search, scans and photos included, respecting Nextcloud permissions. Status: design and measurement (slice S0 done; the app code starts in S1). The app id `buscador` is a placeholder.

- `buscador/`: plan (`PLAN.md`), acceptance (`acceptance.md`), architecture, performance and capacity (`02-performance.md`, `capacity-sheet.md`), security, UI guidelines and mockup, ADRs, drafts of the Spanish site pages (`docs-site/`).
- `.claude/`: workflow commands and reviewer agents (`/bd-status` says the next step; state lives in `.rpiv/artifacts/`).
- `.rpiv/artifacts/`: discovery, research, design, plan, validation, review and handoff artifacts per slice.
- `spikes/s0-harness/`: the throwaway measurement harness behind the S0 numbers, with the committed result snapshot of the run.

The documentation of record is the Spanish site (https://aps-conecta.github.io/documentation/); the pages here are drafts for it. No licence file yet (open question #13).

## Security & HIPAA Compliance
Under 45 CFR § 164.312(a)(2)(iv) and NIST SP 800-111, all PostgreSQL storage hosting extracted document OCR text and tsvectors (`aps_search.page` and `aps_search.chunk`) requires AES-256 encryption-at-rest (LUKS2 host volume encryption or transparent tablespace encryption), along with encrypted backup targets.

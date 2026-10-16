# ADR-0008 — UI strings: Spanish literals, glossary-governed

Status: proposed (aligns with org practice; confirm in P2 S6)
Affects: Vue UI, docs site, `glosario.yml`

## Context
The org writes Spanish source strings without an `l10n/` catalogue; v1 demanded English source plus catalogue.

## Decision
Spanish literals in source, usted/impersonal, terms from `glosario.yml`; new terms are added there with an l10n source. Code, comments and commits stay in English.

## Theming
UI colours and fonts come from the APS Conecta theme (Nextcloud variables; palette in `03-ui-guidelines.md`); components are the `Nc*` ones used by the sibling apps.

## Consequences
No catalogue pipeline; term-check in the docs repo can verify microcopy.

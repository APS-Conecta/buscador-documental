# ADR-0009 — Download-disabled files: title-only hits and an accepted match oracle

Status: accepted (2026-10-16)
Affects: `Recheck`, `Retrieve`, A-SEC-1

## Context
A share with the `permissions.download` attribute disabled lets the sharee see the file but not its content. Search ranks on chunk text, so a hit reveals that the query terms occur in the file.

## Decision
A hit whose recheck returns `snippetAllowed = false` carries the file title only: no `heading`, `page_no`, snippet or matched-chunk identity. The canary asserts the full hit JSON.

## Consequences
A sharee can still probe whether a word occurs in a download-disabled file (a match oracle). This is accepted: the sharee already sees the file name, the leak is one bit per query, and removing it would require hiding such files from search entirely. Revisit if counsel (ADR-0006) or a customer requires otherwise.

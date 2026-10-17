# ADR-0012 — OCR policy: per-page classification, TSV confidence on suspicious pages, owner-password PDFs readable

Status: accepted (2026-10-17)
Affects: `parser/`, `worker/ocr.py`, `page.ocr_conf`, `page.source`, `ocr_ver`, A-QUAL-1, A-SAFE-3

## Context
S0 measured `--skip-text` keeping fake text layers at CER 0.96-0.97, so one document-wide mode is not enough. The ocrmypdf sidecar carries no confidence. S1's `ocr.py` uses `--skip-text` and stores `ocr_conf = NULL`.

## Decision
1. Each PDF page is classified: `native` (plausible text layer, kept, `source = layer`, `ocr_conf = NULL`), `image-only` (OCR, `source = ocr`), or `untrusted layer` (text present but failing cheap plausibility tests: characters per page, letter share, U+FFFD/private-use/control characters, Spanish+English stopword hit rate; re-OCR with `--redo-ocr`, or `--force-ocr` when the layer is visible).
2. Confidence comes from a Tesseract TSV pass on OCR'd and untrusted pages only (mean word confidence, 0-100). Native pages keep `NULL`.
3. PDFs protected only by an owner password (empty user password) are read. PDFs that need a user password are skipped `encrypted`.
4. `ocr_ver` and `chunker_ver` embed the policy name and flags, so any policy change reprocesses files through the idempotency key (ADR-0005).
5. Hostile inputs (zip/pixel bombs, sandbox violations) are skipped with the new reason `unsafe`; unparseable files with `corrupt`.

## Consequences
- Cost: the TSV pass adds about 2.3-2.8 s per OCR'd page; native pages cost nothing extra.
- An invisible fake layer that passes the cheap tests stays undetected; the plan measures how often on the S0 fake kinds (A-QUAL-1 report).
- Page confidence is partial; the UI "uncertain reading" filter in S6 applies to OCR'd pages only.

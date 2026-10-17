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

## Evidence that changed the decision (2026-10-17)
The first policy (`perpage-1`: text-only plausibility tests) passed a 120-page evaluation (CER 0.07 %, WER 0.52 %), but that sample held no fake-layer files. On a 406-page corpus (33 files, seed 1) it failed A-QUAL-1: pooled CER 4.80 %, WER 5.24 %, with `fake_invisible` CER 0.96 and `fake_visible` CER 0.83 (`spikes/s2/results-s2/ocr_eval_400p_perpage-1_FAIL.json`). The generator's fake layer is a short line of reversed words under a full-page scan; it passes vocabulary tests often enough (reversed `la` is `al`) and nothing in the text says it is wrong.
Decision 6 (`perpage-2`): a page that carries a scan-sized image (>= 1 000 000 pixels) is always OCR'd, whatever its text layer says; comparing the layer with the image costs as much as OCR, so no cheaper check is attempted. Cost: well-OCR'd scans from a scanner are re-read (about 6 s per page, S0 figure); the saving of trusting scanner layers is a later optimisation (S5) that must compare against an OCR sample.

## Consequences
- Cost: the TSV pass adds about 2.3-2.8 s per OCR'd page; native pages cost nothing extra.
- A fake layer on a born-digital page with no scan-sized image, and prose-like, stays undetected; the S0 fake kinds are all scans over images and are covered by decision 6.
- Page confidence is partial; the UI "uncertain reading" filter in S6 applies to OCR'd pages only.

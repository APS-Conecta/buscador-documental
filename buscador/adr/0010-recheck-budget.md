# ADR-0010 — Recheck budget: A-PERF-3 not met, waiver accepted with the measured number

Status: amended (2026-10-27): page 1 rechecks in list order before storing (decisions 2 and 3); accepted 2026-10-17, waiver approved at the human gate
Affects: `Recheck`, `ExclusionChecker`, `Searcher`, A-PERF-3, A-PERF-5, `02-performance.md`

## Context
A-PERF-3 asks for a live recheck of 50 hits in under 300 ms at p95. S0 measured 330-405 ms with a per-request folder cache, but its probe listed download-disabled hits as plainly readable, enforced neither share expiry nor "any readable node", and walked ancestors only to the viewer's mount root (C7). S1 corrected the probe and measured five variants on the same fixture (`spikes/s1/results-s1/`, `m9_summary.json`): 50 hits (20 bulk, 3 multi-mount, 10 behind a download-disabled share, 2 behind an expired share, 5 ACL-denied, 10 tagged), 3 users (1 group; 200 groups; share-only), 30 fresh PHP processes each, interleaved, on the shared 8-core VPS (load average 2.3 before and after, so "quiet" only relative to its usual 4-7). Check time excludes the per-request FS setup, reported apart.

| Variant | Worst-user check p95 | Setup p95 | Same decisions as V0 |
|---|---|---|---|
| V0 naive: per-hit `getById`, per-hit ancestor walk, `haveTag` per ancestor | 644 ms | 176 ms | reference |
| V1 + folder memo | 466 ms | 156 ms | yes |
| V2 + parents memoed, one bulk `getTagIdsForObjects` | 381 ms | 168 ms | yes |
| V3 V2 with the readable stage done by `Folder::search` constrained to the candidate ids | 308 ms | 163 ms | yes |
| prod: the shipped `Recheck` + `ExclusionChecker` (V2 design) | 377 ms | 168 ms | yes |

The worst user is the 200-group one; the 1-group user is 165-288 ms and the share-only user 156-259 ms. All 450 runs decided every hit exactly as V0.

## Decision
Neither the shipped design (377 ms) nor the best semantics-preserving variant (V3, 308.5 ms) reaches 300 ms on the worst user, and setup is not the only overage, so the decision rule gives a waiver, not a setup-budget change. Proposed:
1. A-PERF-3 is recorded as **not met: 377 ms shipped, 308 ms best variant**, against 300 ms. The semantics are not loosened to hit the number (no direct `oc_filecache.permissions` reads, no cached outcomes across requests).
2. The user-visible budget in `02-performance.md` stays "live recheck of the page's top ~20 documents, <= 150 ms": the 50-hit figure is a stress case. Per hit the worst user costs about 6.5 ms (p50), so 20 documents are about 130 ms at p50 (an extrapolation, not a separate measurement; S4 measures the real page sizes with A-PERF-5). *(Amended below for page 1 of `Searcher`: its recheck is budgeted by A-PERF-5's page-of-100 figures; cursor pages keep this budget.)*
3. `retrieve()` keeps rechecking only the top ~2x page candidates and refilling when short, and FS setup stays once per request. *(Amended below for page 1 of `Searcher`: it rechecks in list order until `LISTED` readable files are found.)*
4. V3 stays a candidate, not adopted: it is the cheapest variant and agreed with V0 on this fixture, but it replaces the per-id `getById` semantics with search semantics, and one fixture is not proof. S4 adopts it only if the canary and the unit matrix pass unchanged under it.

## Consequences
A worst-case user (200 groups, many mounts) spends roughly 0.4 s in recheck for 50 hits, 0.15-0.2 s for a default cursor page and about 0.65 s for page 1 (up to `LISTED` rechecks, amendment below), on top of about 0.1-0.17 s of FS setup that any Nextcloud request pays. If this is not acceptable, the next levers are, in order: adopt V3 after S4 tests, page size 25 as the enforced default, and parallel batches (A-PERF-5 text). Revisit when real mount counts are known (open question #2).

## Amendment (2026-10-27): page 1 rechecks in list order before storing (decisions 2 and 3)

Page 1 of `Searcher` ranks at most `CAP` (1 000) files, then rechecks them in list order until it has found `LISTED`
files the caller can read, or the list ends, and stores only those. The first batch is `LISTED` files; a later batch is
what is still missing, but at least a page. Page 1 reuses those recheck results for its own hits, so it does not recheck
them twice. Cursor pages are unchanged: they recheck their own page and refill from later positions when short.
`LISTED` is proposed at 100; the owner decides the value.

Cost: up to `LISTED` rechecks on page 1, the same number as a page of 100 today, which A-PERF-5 budgets (keyword
p95 < 1.5 s, hybrid p95 < 2.5 s at 100 per page). At about 6.5 ms per hit for the worst user (p50), that is about 0.65 s;
this figure is an extrapolation, not a separate measurement. Page 1's recheck is therefore budgeted by A-PERF-5, not by
decision 2's ~20 documents / <= 150 ms, which cursor pages keep. `UnifiedSearchProvider` (the Nextcloud search bar, page
size 25) now pays up to `LISTED` rechecks instead of about 25. Worst case: `CAP` rechecks, when almost every candidate is
refused.

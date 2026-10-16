"""Collate results/*.json into results/summary.md: the numbers that go into capacity-sheet.md, acceptance.md and 02-performance.md.
Every figure is indicative (synthetic corpus, one shared host); the derivations are printed next to the inputs."""
import json
import sys

R = "/work/results"
J = lambda n: json.load(open(f"{R}/{n}.json"))
out = []


def p(s=""):
    out.append(s)


def cfg(d, conc, page):
    return next(c for c in d["configs"] if c["conc"] == conc and c["page"] == page)


v, m3, m4, m4t, m5, m11, m1, m2, m9 = (J(n) for n in ("versions", "m3", "m4_embed_recall_corpus", "m4_threads", "m5_full", "m11_full", "m1", "m2", "m9"))
m6, m6c, m7, m10 = J("m6"), J("m6_capped"), J("m7"), J("m10")
m8r, m8s = J("m8_bd_recall"), J("m8_bd_spike")

p("# S0 results summary (indicative)\n")
p(f"Stack: PostgreSQL {v['postgres']}, pgvector {v['pgvector']}, Nextcloud {v['nextcloud']}, {v['tesseract']}, ocrmypdf {v['ocrmypdf']}. Box: 8 cores, 31 GB, no GPU, shared VPS.\n")

p("## Storage and index builds (3 000 000 chunks = 1M-page equivalent at 3 chunks/page)")
p(f"- Database {m11['database_bytes'] / 1e9:.1f} GB ({m11['bytes_per_chunk_total']:.0f} B/chunk); chunk table {m11['chunk']['table_bytes'] / 1e9:.1f} GB (+{m11['chunk']['indexes_bytes'] / 1e9:.2f} GB indexes), "
  f"chunk_vec {m11['chunk_vec']['table_bytes'] / 1e9:.1f} GB (+{m11['chunk_vec']['indexes_bytes'] / 1e9:.1f} GB, HNSW {m11['per_index_bytes']['bd_spike.chunk_vec_hnsw'] / 1e9:.1f} GB), tsv GIN {m11['per_index_bytes']['bd_spike.chunk_tsv_gin'] / 1e9:.2f} GB; stored tsv {m11['avg_tsv_bytes']:.0f} B/chunk avg.")
ix = m5["indexes"]
p(f"- Load {m5['load_seconds']:.0f} s (4 procs); GIN {ix['chunk_tsv_gin']['seconds']:.0f} s; HNSW {ix['chunk_vec_hnsw']['seconds']:.0f} s (maintenance_work_mem {m5['maintenance_work_mem']}); "
  f"all builds {m5['index_build_minutes_per_100k_chunks']:.2f} min per 100k chunks; WAL {m5['wal_bytes'] / 1e9:.1f} GB.\n")

p("## Keyword (Exacta) latency at 3M chunks, p95 ms (median of 3 repeats; spread)")
p("| searchers | page | uncapped | head-term pre-limit (cap 10 000) |\n|---|---|---|---|")
for c, pg in [(1, 25), (5, 25), (10, 25), (20, 25), (5, 50), (5, 100)]:
    a, b = cfg(m6, c, pg), cfg(m6c, c, pg)
    p(f"| {c} | {pg} | {a['p95_median_ms']:.0f} ({a['p95_min_ms']:.0f}-{a['p95_max_ms']:.0f}) | {b['p95_median_ms']:.0f} ({b['p95_min_ms']:.0f}-{b['p95_max_ms']:.0f}) |")
p("\np50 uncapped / capped at 1 searcher: " + f"{cfg(m6, 1, 25)['pooled']['p50_ms']:.0f} / {cfg(m6c, 1, 25)['pooled']['p50_ms']:.0f} ms.")
p("Uncapped p95 by query type at 1 searcher: " + ", ".join(f"{k[5:]} {x['p95_ms']:.0f}" for k, x in cfg(m6, 1, 25)["breakdown"].items() if k.startswith("type=")))
p(f"Uncapped at 20 searchers: {cfg(m6, 20, 25)['pooled']['errors']} of {len(cfg(m6, 20, 25)['repeats']) * 4000} requests hit the 60 s timeout.")
cold = sorted(__import__("glob").glob(f"{R}/m6_cold_*.json"))
p("Cold (shared_buffers only, uncapped, first 60 queries after a db restart): p95 " + ", ".join(f"{json.load(open(f))['configs'][0]['pooled']['p95_ms']:.0f}" for f in cold) + " ms.\n")
p("## Head-term mitigation (m10), p95 ms / top-10 overlap with the full ranking")
for r in m10["variants"]:
    p(f"- {r['class']} {r['variant']}: p95 {r['p95_ms']:.0f} (narrow {r['p95_narrow_ms']}, admin {r['p95_admin_ms']}), overlap {r['top10_overlap_with_baseline']}")
p("Synthetic text has no real relevance, so overlap shows the ranking differs, not that quality is lost; S4 must measure it on real text.\n")

p("## Hybrid (Mixta) latency p95 ms (FTS leg capped; strict_order vs relaxed_order + re-sort)")
p("| searchers | page | strict | relaxed |\n|---|---|---|---|")
for c, pg in [(1, 25), (5, 25), (10, 25), (20, 25), (5, 100)]:
    a = next(x for x in m7["configs"] if x["iterative_scan"] == "strict_order" and x["conc"] == c and x["page"] == pg)
    b = next(x for x in m7["configs"] if x["iterative_scan"] == "relaxed_order" and x["conc"] == c and x["page"] == pg)
    p(f"| {c} | {pg} | {a['p95_median_ms']:.0f} | {b['p95_median_ms']:.0f} |")
p()

p("## Filtered recall (real e5-small embeddings, 30 000 Wikipedia-es chunks, 200 queries, HNSW forced and plan-verified)")
for vname, r in m8r["views"].items():
    p(f"- {vname}: " + "; ".join(f"{c['iterative_scan'][:7]} ef{c['ef_search']} mst{c['max_scan_tuples']}: recall@10 {c['recall_at_10_mean']}, short<50 {c['shortfall_lt50']}" for c in r["configs"] if (c["ef_search"], c["max_scan_tuples"]) in ((100, 20000), (100, 5000), (400, 20000))))
p("\n## ANN shortfall and exact-scan switch at 3M random vectors (latency and shortfall only)")
p("| readable files | exact p50 ms | ANN p50 ms | ANN shortfall<50 |\n|---|---|---|---|")
for r in m8s["exact_switch_sweep"]:
    p(f"| {r['readable_files']} | {r['exact_p50_ms']} | {r['ann_p50_ms']} | {r['ann_shortfall_lt50']} |")
p("\n## isReadable / getById, 50 hits, fresh PHP process per call (30 runs)")
for k, s in m9["summary"].items():
    p(f"- {k}: check p50 {s['check_ms_p50']} p95 {s['check_ms_p95']} ms; FS setup p95 {s['setup_ms_p95']} ms; outcome {s['outcome_example']}")

p("\n## OCR (300 dpi, spa+eng, OMP_THREAD_LIMIT=1)")
for f, r in m1["raw_tesseract"].items():
    p(f"- raw tesseract tessdata_{f}: {r['s_per_page_1proc_median']} s/page (1 proc), {r['pages_per_s_8proc']} pages/s with 8 procs = {r['s_per_page_per_core_8proc']} s/page/core")
for k, r in m1["ocrmypdf"].items():
    p(f"- ocrmypdf {k}: median {r['s_per_page_median']} s/page ({r['ok']}/{r['n']} ok" + (f", {len(r['skipped'])} skipped rc={r['skipped'][0]['rc']}" if r["skipped"] else "") + ")")
p("CER/WER medians: " + "; ".join(f"{k.split('/', 1)[1]} {x['cer_median']:.4f}/{x['wer_median']:.4f}" for k, x in m2["groups"].items()))

p("\n## Corpus and embedding")
p(f"- Functional corpus {m3['files']} files, {m3['pages']} pages ({m3['pages_per_file']}/file), image-only share {m3['image_only_share']:.1%}, {m3['chunks_per_page_mean']} chunks/page, {m3['tokens_per_page_mean']:.0f} tokens/page (synthetic words tokenise heavily).")
p("- Embedding tokens/s by threads (batch 32): " + ", ".join(f"{r['threads']}t {r['tokens_per_s']:.0f}" for r in m4t["runs"]) + f"; 30k-chunk run at 8t: {m4['tokens_per_s']:.0f} tok/s.")

# Backfill derivation, 1M pages: OCR only on image-only pages, then embed every chunk; both share the same 8 cores.
raw = m1["raw_tesseract"]["fast"]
img_pages = 1_000_000 * m3["image_only_share"]
ocr_core_s = img_pages * raw["s_per_page_per_core_8proc"]
tok = 3_000_000 * (m4t["runs"][-1]["tokens"] / 256)
emb_core_s = tok / (m4t["runs"][-1]["tokens_per_s"] / 8)
total_h = (ocr_core_s + emb_core_s) / 8 / 3600
p(f"\n## Backfill (1M pages, 8 cores, indicative)")
p(f"- OCR: {img_pages:,.0f} image-only pages x {raw['s_per_page_per_core_8proc']} s/page/core = {ocr_core_s / 3600:,.0f} core-h; embedding: {tok / 1e6:,.0f} M tokens at {m4t['runs'][-1]['tokens_per_s'] / 8:.0f} tok/s/core = {emb_core_s / 3600:,.0f} core-h.")
p(f"- Wall-clock on 8 cores, box dedicated: {total_h:.0f} h; with an 8 h night window: {total_h / 8:.1f} nights. Keyword lane first: OCR+index only = {ocr_core_s / 8 / 3600:.0f} h.")
open(f"{R}/summary.md", "w").write("\n".join(out) + "\n")
print("\n".join(out))

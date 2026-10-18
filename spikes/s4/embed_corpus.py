# SPDX-License-Identifier: AGPL-3.0-or-later
"""Embeds the evaluation corpus and queries through the Ollama endpoint with the product's own worker client (worker/embed.py)
and the profile's prefixes. Writes vectors.json and timing. Usage: embed_corpus.py DIR URL PROFILE_ID"""
import json
import statistics
import sys
import time

sys.path.insert(0, "/worker")
import embed  # noqa: E402

CATALOG = {p["id"]: p for p in json.load(open("/profiles.json"))["profiles"]}


def main(d, url, profile_id):
    p = CATALOG[profile_id]
    docs = json.load(open(f"{d}/docs.json"))
    gold = json.load(open(f"{d}/gold.json"))
    e = embed.Embedder(url, timeout=300, batch=16)
    digest = e.digest(p["model"])
    if not digest:
        sys.exit(f"model {p['model']} is not available at the endpoint")
    t0 = time.time()
    dvec = e.embed(p["model"], [p["passage_prefix"] + x["text"] for x in docs], p["max_tokens"])
    doc_seconds = time.time() - t0
    qtimes, qvec = [], []
    for g in gold:
        t = time.time()
        qvec.append(e.embed(p["model"], [p["query_prefix"] + g["query"]], p["max_tokens"])[0])
        qtimes.append((time.time() - t) * 1000)
    # throughput at the chunk length the chunker really produces (300-400 tokens, about 220 Spanish words): five documents glued together
    long_texts = [" ".join(x["text"] for x in docs[i:i + 5]) for i in range(0, 160, 5)]
    words = statistics.mean(len(t.split()) for t in long_texts)
    t1 = time.time()
    e.embed(p["model"], [p["passage_prefix"] + t for t in long_texts], p["max_tokens"])
    long_seconds = time.time() - t1
    json.dump({"profile": p, "digest": digest, "docs": dvec, "queries": qvec}, open(f"{d}/vectors.json", "w"))
    qs = sorted(qtimes)
    json.dump({"model": p["model"], "digest": digest, "dim": len(dvec[0]), "documents": len(docs), "documents_seconds": round(doc_seconds, 2),
               "documents_per_second": round(len(docs) / doc_seconds, 2),
               "long_chunks": len(long_texts), "long_chunk_words": round(words), "long_chunks_per_second": round(len(long_texts) / long_seconds, 2), "query_embed_ms_p50": round(statistics.median(qs), 1),
               "query_embed_ms_p95": round(qs[int(len(qs) * 0.95) - 1], 1), "queries": len(qs)}, open(f"{d}/embed_timing.json", "w"), indent=1)
    print(open(f"{d}/embed_timing.json").read())


if __name__ == "__main__":
    main(*sys.argv[1:4])

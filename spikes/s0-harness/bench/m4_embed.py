"""m4: embedding throughput (tokens/s) of the small-cpu profile class vs thread count, batch 32.
The 30k-chunk run in prep_recall_corpus.py (8 threads) is the large-sample data point; this sweeps threads."""
import json
import sys
import time

sys.path.insert(0, "/work/bench")
from chunking import chunk_page  # noqa: E402
from embed import Embedder, sha256  # noqa: E402
from lib import write_result  # noqa: E402

files = [json.loads(l) for l in open("/work/corpus/out/functional/manifest.jsonl", encoding="utf-8")]
base = Embedder(threads=1)
tok = base.tok
chunks = []
for f in files[::7]:
    for t in f["page_text"][:2]:
        chunks += chunk_page(tok, t)
    if len(chunks) >= 256:
        break
chunks = chunks[:256]
runs = []
for threads in (1, 2, 4, 8):
    e = Embedder(threads=threads)
    e.embed(chunks[:32], "passage: ")                                  # warm-up
    t = time.time()
    _, tokens = e.embed(chunks, "passage: ")
    s = time.time() - t
    runs.append({"threads": threads, "tokens": tokens, "seconds": round(s, 2), "tokens_per_s": round(tokens / s, 1)})
    print(runs[-1], flush=True)
write_result("m4_threads", {"model": "Xenova/multilingual-e5-small int8 (dynamic)", "model_sha256": sha256(base.paths["model"]),
                            "batch": 32, "chunks": len(chunks), "runs": runs})

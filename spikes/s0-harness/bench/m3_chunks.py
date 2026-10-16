"""m3: image-only share, pages/file, tokens/page and chunks/page on the functional corpus (naive chunker)."""
import json
import sys

sys.path.insert(0, "/work/bench")
from embed import Embedder  # noqa: E402
from chunking import chunk_page  # noqa: E402
from lib import write_result  # noqa: E402

files = [json.loads(l) for l in open("/work/corpus/out/functional/manifest.jsonl", encoding="utf-8")]
tok = Embedder(threads=1).tok
tok.no_padding()
tok.no_truncation()
pages = sum(f["pages"] for f in files)
img_pages = sum(sum(f["image_only"]) for f in files)
sample = [t for f in files[::10] for t in f["page_text"][:3]]          # every 10th file, first 3 pages
tokens_per_page = [len(tok.encode(t, add_special_tokens=False).ids) for t in sample]
chunks_per_page = [len(chunk_page(tok, t)) for t in sample]
by_kind = {}
for f in files:
    k = by_kind.setdefault(f["kind"], {"files": 0, "pages": 0})
    k["files"] += 1
    k["pages"] += f["pages"]
write_result("m3", {"files": len(files), "pages": pages, "pages_per_file": round(pages / len(files), 1),
                    "image_only_pages": img_pages, "image_only_share": round(img_pages / pages, 3), "by_kind": by_kind,
                    "sample_pages": len(sample), "tokens_per_page_mean": round(sum(tokens_per_page) / len(sample), 1),
                    "chunks_per_page_mean": round(sum(chunks_per_page) / len(sample), 2), "chunk_target_tokens": 350,
                    "note": "synthetic Zipf text tokenises worse than natural Spanish; S4 re-measures with the real chunker"})
print("m3 done")

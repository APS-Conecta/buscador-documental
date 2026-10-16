"""S0 corpus self-checks: manifest/file consistency, kind mix, ACL shares, gold-set sanity.
Usage: python3 selfcheck.py <corpus_dir> <gold.json>. Exit 1 on any failure."""
import collections
import json
import os
import sys

from gen_corpus import KINDS, KIND_P

corpus, gold_path = sys.argv[1:3]
fails = []


def check(ok: bool, msg: str) -> None:
    print(("ok   " if ok else "FAIL ") + msg)
    if not ok:
        fails.append(msg)


files = [json.loads(l) for l in open(f"{corpus}/manifest.jsonl", encoding="utf-8")]
check(all(os.path.getsize(f"{corpus}/{f['path']}") > 0 for f in files), f"{len(files)} files exist and are non-empty")
check(all(len(f["page_text"]) == f["pages"] == len(f["image_only"]) for f in files), "page counts match page_text/image_only")
kinds = collections.Counter(f["kind"] for f in files)
check(all(abs(kinds[k] / len(files) - p) < 0.08 for k, p in zip(KINDS, KIND_P)),
      "kind mix within 8 points of spec: " + ", ".join(f"{k}={kinds[k]}" for k in KINDS))
pages = sum(f["pages"] for f in files)
img = sum(sum(f["image_only"]) for f in files)
check(0.25 <= img / pages <= 0.75, f"image-only share {img / pages:.0%} of {pages} pages (expect OCR work on roughly half)")
check(all(f["kind"] != "photo" or f["pages"] == 1 for f in files), "photos are single-page")

gold = json.load(open(gold_path, encoding="utf-8"))
nar, tot = gold["n_narrow_files"], gold["n_files"]
check(0.005 <= nar / tot <= 0.04 and nar >= 3, f"F1 u_narrow reads {nar}/{tot} files ({nar / tot:.1%}; spec 1-2 %, tolerance 0.5-4 %)")
qs = gold["queries"]
check(len(qs) == 200, f"{len(qs)} gold queries")
check(all(q["n_relevant"] >= 1 for q in qs), "every query has >= 1 relevant file readable by its user")
byfile = {f["file_id"]: f for f in files}
narrow_ok = all(set(byfile[i]["principals"]) & {"g:g001", "g:g002", "u:u_narrow"}
                for q in qs if q["user"] == "u_narrow" for i in q["relevant_files"])
check(narrow_ok, "u_narrow relevant files are all readable by u_narrow")
types = collections.Counter(q["type"] for q in qs)
check(set(types) == {"head", "rare", "two", "three", "phrase", "accentless", "heading"}, f"query types: {dict(types)}")
users = collections.Counter(q["user"] for q in qs)
check(users["u_narrow"] >= 40 and users["u_admin200"] >= 40, f"user split: {dict(users)}")
head = [q for q in qs if q["type"] == "head"]
check(all(q["n_relevant"] / (tot if q["user"] == "u_admin200" else nar) > 0.3 for q in head), "head terms hit > 30 % of readable files")
sys.exit(1 if fails else 0)

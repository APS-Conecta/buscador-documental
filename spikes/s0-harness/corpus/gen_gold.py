"""200-query gold set over the functional corpus (manifest.jsonl). Ground truth = exact normalized match.

Types: head (very common term), rare, two (AND of 2), three (AND of 3), phrase ("a b c"), accentless
(accented term typed without accent -> must hit through unaccent), heading ("artículo N").
Users: u_narrow (F1, ~1-2 % of files readable) and u_admin200 (F2, all files).
"""
import argparse
import json
import re
import unicodedata

import numpy as np

from zipf import STOP, TextModel

COUNTS = {"head": 24, "rare": 30, "two": 60, "three": 40, "phrase": 20, "accentless": 16, "heading": 10}
NARROW_SHARE = 0.40           # share of queries issued as u_narrow
NARROW_PRINCIPALS = {"g:g001", "g:g002", "u:u_narrow"}
MAX_LISTED = 200


def norm(s: str) -> str:
    return "".join(c for c in unicodedata.normalize("NFD", s.lower()) if unicodedata.category(c) != "Mn")


def toks(s: str) -> list[str]:
    return re.findall(r"[a-z0-9ñ]+", norm(s))


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--seed", type=int, default=1)
    ap.add_argument("--corpus", required=True)
    ap.add_argument("--out", required=True)
    a = ap.parse_args()
    rng = np.random.default_rng([a.seed, 3])
    model = TextModel(a.seed)
    files = [json.loads(l) for l in open(f"{a.corpus}/manifest.jsonl", encoding="utf-8")]
    stop = set(map(norm, STOP))
    for f in files:
        f["narrow"] = bool(NARROW_PRINCIPALS & set(f["principals"]))
        f["pages_toks"] = [toks(t) for t in f["page_text"]]
        f["tokset"] = {w for p in f["pages_toks"] for w in p}
        f["articles"] = max([int(m) for t in f["page_text"] for m in re.findall(r"Artículo (\d+)\.", t)] or [1])
    n_files = len(files)
    df: dict[str, int] = {}
    for f in files:
        for w in f["tokset"]:
            df[w] = df.get(w, 0) + 1
    narrow_files = [f for f in files if f["narrow"]]

    def readable(user: str):
        return files if user == "u_admin200" else narrow_files

    def content(tokens):  # content words only
        return [w for w in tokens if w not in stop and len(w) >= 4]

    def relevant(pred, user):
        return [f["file_id"] for f in readable(user) if pred(f)]

    queries = []
    users = [("u_narrow" if rng.random() < NARROW_SHARE else "u_admin200") for _ in range(sum(COUNTS.values()))]
    ui = 0
    head_pool = [norm(w) for w in model.vocab[:40] if df.get(norm(w), 0) / n_files > 0.3][:20]
    if not head_pool:
        raise SystemExit("no vocabulary term appears in > 30 % of files: cannot build head-term queries (corpus too small?)")
    for typ, n in COUNTS.items():
        made, attempts = 0, 0
        while made < n:
            attempts += 1
            if attempts > 5000:
                raise SystemExit(f"cannot build {n} '{typ}' queries after 5000 attempts")
            user = users[ui % len(users)]
            pool = readable(user)
            src = pool[int(rng.integers(len(pool)))]
            page = src["pages_toks"][int(rng.integers(src["pages"]))]
            words = content(page)
            if len(words) < 6:
                continue
            q = None
            if typ == "head" and head_pool:
                t = head_pool[int(rng.integers(len(head_pool)))]
                q, pred = t, (lambda f, t=t: t in f["tokset"])
            elif typ == "rare":
                rare = [w for w in words if 1 <= df[w] <= 3]
                if rare:
                    t = rare[int(rng.integers(len(rare)))]
                    q, pred = t, (lambda f, t=t: t in f["tokset"])
            elif typ in ("two", "three"):
                k = 2 if typ == "two" else 3
                mid = [w for w in set(words) if 3 <= df[w] <= max(4, int(0.05 * n_files))]
                if len(mid) >= k:
                    t = sorted(rng.choice(sorted(mid), k, replace=False).tolist())
                    q, pred = " ".join(t), (lambda f, t=t: all(w in f["tokset"] for w in t))
            elif typ == "phrase":
                i = int(rng.integers(len(page) - 3))
                ph = page[i:i + 3]
                if all(w not in stop for w in ph):
                    q = '"' + " ".join(ph) + '"'
                    pred = lambda f, ph=ph: any(" ".join(ph) in " ".join(p) for p in f["pages_toks"])
            elif typ == "accentless":
                raw = [w for w in re.findall(r"\w+", src["page_text"][0].lower()) if re.search("[áéíóúñ]", w) and w not in stop]
                if raw:
                    t = norm(raw[int(rng.integers(len(raw)))])
                    q, pred = t, (lambda f, t=t: t in f["tokset"])
            elif typ == "heading":
                nth = int(rng.integers(20, 60))
                q = f"artículo {nth}"
                pred = lambda f, nth=nth: f["articles"] >= nth
            if q is None:
                continue
            rel = relevant(pred, user)
            if not rel:
                continue
            queries.append({"id": len(queries) + 1, "type": typ, "user": user, "q": q,
                            "n_relevant": len(rel), "relevant_files": rel[:MAX_LISTED]})
            made += 1
            ui += 1
    json.dump({"seed": a.seed, "n_files": n_files, "n_narrow_files": len(narrow_files), "queries": queries},
              open(a.out, "w", encoding="utf-8"), ensure_ascii=False, indent=1, sort_keys=True)
    print(f"queries={len(queries)} narrow_files={len(narrow_files)}/{n_files}")


if __name__ == "__main__":
    main()

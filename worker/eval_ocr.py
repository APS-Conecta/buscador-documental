# SPDX-License-Identifier: AGPL-3.0-or-later
"""A-QUAL-1: pooled CER <= 2 % and WER <= 5 % of the page-by-page pipeline against ground truth.

Input: a corpus directory made by spikes/s0-harness/corpus/gen_corpus.py (manifest.jsonl with `page_text`). Each file goes
through the real parser path (extract + pdfocr). Output: JSON with per-kind and overall rates and the gate verdict; exit
status 1 when the gate fails. Honest limits are printed with the result (synthetic clean text, small tilt, no 90-degree photos)."""
import json
import os
import sys
import time
import unicodedata

from rapidfuzz.distance import Levenshtein

CER_MAX = 0.02
WER_MAX = 0.05
CATASTROPHIC_CER = 0.5          # a page with more than half its characters wrong


def norm(s: str) -> str:
    return " ".join(unicodedata.normalize("NFC", s).split())


def char_edits(hyp: str, ref: str) -> tuple:
    h, r = norm(hyp), norm(ref)
    return Levenshtein.distance(h, r), len(r)


def word_edits(hyp: str, ref: str) -> tuple:
    h, r = norm(hyp).split(), norm(ref).split()
    return Levenshtein.distance(h, r), len(r)


def summarize(rows: list) -> dict:
    out = {}
    for key in sorted({r["kind"] for r in rows}) + ["all"]:
        sel = [r for r in rows if key == "all" or r["kind"] == key]
        ce, cr = sum(r["c_edits"] for r in sel), sum(r["c_ref"] for r in sel)
        we, wr = sum(r["w_edits"] for r in sel), sum(r["w_ref"] for r in sel)
        out[key] = {"pages": len(sel), "cer": ce / cr if cr else 0.0, "wer": we / wr if wr else 0.0,
                    "catastrophic": sum(1 for r in sel if r["c_ref"] and r["c_edits"] / r["c_ref"] > CATASTROPHIC_CER)}
    return out


def gate(summary: dict) -> dict:
    a = summary["all"]
    ok = a["pages"] > 0 and a["cer"] <= CER_MAX and a["wer"] <= WER_MAX
    return {"pass": ok, "cer": a["cer"], "wer": a["wer"], "criterion": {"cer": CER_MAX, "wer": WER_MAX}}


def main(corpus: str, out_json: str) -> int:
    import extract
    import pdfocr
    rows, started = [], time.time()
    for line in open(os.path.join(corpus, "manifest.jsonl"), encoding="utf-8"):
        rec = json.loads(line)
        path = os.path.join(corpus, rec["path"])
        try:
            pages = pdfocr.run(path, layer=extract.extract(path) if path.endswith(".pdf") else None)["pages"]
        except Exception as e:                      # a refused or failed file counts as every page wrong
            print(f"file {rec['file_id']} ({rec['kind']}) failed: {type(e).__name__}: {e}", file=sys.stderr)
            pages = [""] * rec["pages"]
        for i, truth in enumerate(rec["page_text"]):
            hyp = pages[i] if i < len(pages) else ""
            ce, cr = char_edits(hyp, truth)
            we, wr = word_edits(hyp, truth)
            rows.append({"kind": rec["kind"], "file_id": rec["file_id"], "page": i + 1,
                         "c_edits": ce, "c_ref": cr, "w_edits": we, "w_ref": wr})
    summary = summarize(rows)
    verdict = gate(summary)
    result = {"summary": summary, "gate": verdict, "seconds": round(time.time() - started, 1), "ocr_ver": pdfocr.version(),
              "limits": ["synthetic clean DejaVu Sans text from a Zipf word model", "photos tilted +-4 degrees only (no 90/180/270 photos)",
                         "fake_invisible layers are garbage text, not plausible prose"]}
    with open(out_json, "w") as fh:
        json.dump(result, fh, indent=1, ensure_ascii=False)
    for k, v in summary.items():
        print(f"{k:14} pages={v['pages']:4} CER={v['cer']:.4f} WER={v['wer']:.4f} catastrophic={v['catastrophic']}")
    print(f"gate: {'PASS' if verdict['pass'] else 'FAIL'} (CER <= {CER_MAX}, WER <= {WER_MAX})")
    return 0 if verdict["pass"] else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1], sys.argv[2]))

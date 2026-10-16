"""m2: indicative CER/WER of the sidecar texts written by m1 against the ground-truth page text (A-QUAL-1 baseline; S2 owns
the gate). Whitespace-normalised; rapidfuzz Levenshtein on characters (CER) and on word lists (WER)."""
import glob
import json
import os
import re
import statistics
import sys

from pypdf import PdfReader
from rapidfuzz.distance import Levenshtein

sys.path.insert(0, "/work/bench")
from lib import write_result  # noqa: E402

SAMPLES, SIDECARS, OUTDIR = "/work/results/ocr_samples", "/work/results/ocr_sidecars", "/work/results/ocr_out"


def norm(t):
    return re.sub(r"\s+", " ", t).strip()


def pdf_text(path):
    """Text layer of an output PDF (pypdf): what search would index. Ghostscript txtwrite cannot read OCR layers."""
    return "\n".join(p.extract_text() or "" for p in PdfReader(path).pages)


def cer_wer(ref, hyp):
    ref, hyp = norm(ref), norm(hyp)
    return (Levenshtein.distance(ref, hyp) / max(len(ref), 1), Levenshtein.distance(ref.split(), hyp.split()) / max(len(ref.split()), 1))


def main():
    truth = {s["id"]: s["truth"] for s in json.load(open(f"{SAMPLES}/samples.json"))}
    groups = {}
    for p in sorted(glob.glob(f"{SIDECARS}/raw_*.txt")):             # raw tesseract: its own text output
        _, flavor, sid = os.path.basename(p)[:-4].split("_", 2)
        if sid in truth:
            groups.setdefault(f"raw_tesseract/{flavor}", []).append(cer_wer(truth[sid], open(p, encoding="utf-8", errors="replace").read()))
    for p in sorted(glob.glob(f"{OUTDIR}/*.pdf")):                    # ocrmypdf: the output PDF's text layer
        kind, pol, sid = os.path.basename(p)[:-4].split("__")
        if sid in truth:
            groups.setdefault(f"ocrmypdf/{kind}/{pol}", []).append(cer_wer(truth[sid], pdf_text(p)))
    out = {k: {"n": len(v), "cer_median": round(statistics.median(x[0] for x in v), 4), "cer_max": round(max(x[0] for x in v), 4),
               "wer_median": round(statistics.median(x[1] for x in v), 4)} for k, v in sorted(groups.items())}
    for k, v in out.items():
        print(k, v)
    print(write_result("m2", {"groups": out, "note": "synthetic clean-rendered Spanish text at 300 dpi; real documents will be worse"}))


if __name__ == "__main__":
    main()

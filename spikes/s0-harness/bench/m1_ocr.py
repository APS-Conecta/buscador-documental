"""m1: OCR throughput on 300 dpi samples (Tesseract 5, spa+eng), OMP_THREAD_LIMIT=1 everywhere.

  A. raw tesseract on the page image: tessdata_fast vs tessdata_best, 1 process vs 8 parallel processes.
  B. ocrmypdf end-to-end on 1-page PDFs/images, per policy: --force-ocr (scanned), --skip-text and --redo-ocr (mixed and
     fake text layers, native), --deskew --rotate-pages (photos). Exit codes 6/8 or a timeout are counted as `skipped`
     with the reason, not as failures. Output PDFs are kept for m2 (CER/WER): with --skip-text the sidecar holds only a skip marker, so m2 reads the text layer of the output PDF.
"""
import json
import os
import shutil
import statistics
import subprocess
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor

sys.path.insert(0, "/work/bench")
from lib import write_result  # noqa: E402

MODELS = "/work/models"
SAMPLES = "/work/results/ocr_samples"
SIDECARS = "/work/results/ocr_sidecars"
OUTDIR = "/work/results/ocr_out"
TESS_URL = {"fast": "https://github.com/tesseract-ocr/tessdata_fast/raw/main/{}.traineddata",
            "best": "https://github.com/tesseract-ocr/tessdata_best/raw/main/{}.traineddata"}
TIMEOUT = 180


def tessdata(flavor):
    d = f"{MODELS}/tessdata_{flavor}"
    os.makedirs(d, exist_ok=True)
    for sub in ("configs", "tessconfigs"):                      # ocrmypdf needs the `pdf`/`txt` configs next to the models
        if not os.path.exists(f"{d}/{sub}"):
            shutil.copytree(f"/usr/share/tesseract-ocr/5/tessdata/{sub}", f"{d}/{sub}")
    for lang in ("spa", "eng", "osd"):
        p = f"{d}/{lang}.traineddata"
        if not os.path.exists(p):
            urllib.request.urlretrieve(TESS_URL[flavor].format(lang), p + ".part")
            if os.path.getsize(p + ".part") < 100_000:              # truncated or an error page
                raise SystemExit(f"FATAL: bad download for {flavor}/{lang}")
            os.replace(p + ".part", p)
    return d


def run(cmd, env_extra, timeout=TIMEOUT + 60):
    env = {**os.environ, "OMP_THREAD_LIMIT": "1", **env_extra}
    t = time.perf_counter()
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=timeout)
        return time.perf_counter() - t, p.returncode, p.stdout, p.stderr[-300:]
    except subprocess.TimeoutExpired:
        return time.perf_counter() - t, "timeout", "", ""


def raw_tesseract(img, flavor, out_base):
    s, rc, _, err = run(["tesseract", img, out_base, "-l", "spa+eng", "--dpi", "300"], {"TESSDATA_PREFIX": tessdata(flavor)})
    if rc != 0 or not os.path.exists(out_base + ".txt") or os.path.getsize(out_base + ".txt") == 0:
        raise SystemExit(f"FATAL: raw tesseract failed on {img} (rc {rc}): {err}")
    return {"seconds": s, "rc": rc}


def main():
    os.makedirs(SIDECARS, exist_ok=True)
    os.makedirs(OUTDIR, exist_ok=True)
    subprocess.run(["/usr/bin/python3", "/work/bench/extract_pages.py", "/work/corpus/out/functional/manifest.jsonl", SAMPLES], check=True)
    samples = json.load(open(f"{SAMPLES}/samples.json"))
    imgs = [s for s in samples if s.get("image") and s["kind"] in ("scanned", "photo")]
    res = {"tesseract_version": subprocess.run(["tesseract", "--version"], capture_output=True, text=True).stdout.splitlines()[0],
           "dpi": 300, "omp_thread_limit": 1}

    # A. raw tesseract
    raw = {}
    for flavor in ("fast", "best"):
        tessdata(flavor)
        single = [raw_tesseract(f"{SAMPLES}/{s['image']}", flavor, f"{SIDECARS}/raw_{flavor}_{s['id']}") for s in imgs]
        t = time.perf_counter()
        with ThreadPoolExecutor(8) as ex:
            list(ex.map(lambda s: raw_tesseract(f"{SAMPLES}/{s['image']}", flavor, f"{SIDECARS}/raw8_{flavor}_{s['id']}"), imgs * 2))
        wall8 = time.perf_counter() - t
        secs = [x["seconds"] for x in single]
        raw[flavor] = {"pages": len(imgs), "s_per_page_1proc_median": round(statistics.median(secs), 2), "s_per_page_1proc_max": round(max(secs), 2),
                       "pages_per_s_8proc": round(len(imgs) * 2 / wall8, 3), "s_per_page_per_core_8proc": round(8 * wall8 / (len(imgs) * 2), 2)}
        print("raw", flavor, raw[flavor], flush=True)
    res["raw_tesseract"] = raw

    # B. ocrmypdf end-to-end
    policies = {"scanned": [("force-ocr", ["--force-ocr"]), ("force-ocr+deskew+rotate", ["--force-ocr", "--deskew", "--rotate-pages"])],
                "photo": [("image-dpi300", ["--image-dpi", "300"]), ("image-dpi300+deskew+rotate", ["--image-dpi", "300", "--deskew", "--rotate-pages"])],
                "mixed": [("skip-text", ["--skip-text"]), ("redo-ocr", ["--redo-ocr"])],
                "fake_visible": [("skip-text", ["--skip-text"]), ("redo-ocr", ["--redo-ocr"]), ("force-ocr", ["--force-ocr"])],
                "fake_invisible": [("skip-text", ["--skip-text"]), ("redo-ocr", ["--redo-ocr"]), ("force-ocr", ["--force-ocr"])],
                "native": [("skip-text", ["--skip-text"]), ("no-flag", [])]}
    omp = {}
    env = {"TESSDATA_PREFIX": tessdata("fast")}
    for kind, pols in policies.items():
        for name, flags in pols:
            rows = []
            for s in [x for x in samples if x["kind"] == kind]:
                src = f"{SAMPLES}/{s['image'] if kind == 'photo' else s['pdf']}"
                side = f"{SIDECARS}/{kind}__{name}__{s['id']}.txt"
                sec, rc, _, err = run(["ocrmypdf", "-l", "spa+eng", "--output-type", "pdf", "--optimize", "0", "--jobs", "1",
                                       "--tesseract-timeout", str(TIMEOUT), "--sidecar", side, *flags, src, f"{OUTDIR}/{kind}__{name}__{s['id']}.pdf"], env)
                # Guard against a silent no-text run (missing tessdata configs gave rc 0 and an empty text layer once).
                if rc == 0 and (not os.path.exists(side) or os.path.getsize(side) == 0):
                    raise SystemExit(f"FATAL: ocrmypdf rc 0 but empty sidecar for {kind}/{name}/{s['id']}")
                if rc not in (0, 6, 8, "timeout"):                    # 6 = already has text, 8 = unreadable page; anything else is a crash
                    raise SystemExit(f"FATAL: ocrmypdf crashed (rc {rc}) on {kind}/{name}/{s['id']}: {err}")
                rows.append({"id": s["id"], "seconds": round(sec, 2), "rc": rc, "status": "ok" if rc == 0 else "skipped", "stderr": err if rc != 0 else ""})
            ok = [r["seconds"] for r in rows if r["rc"] == 0]
            omp[f"{kind}/{name}"] = {"n": len(rows), "ok": len(ok), "skipped": [{"id": r["id"], "rc": r["rc"], "why": r["stderr"]} for r in rows if r["rc"] != 0],
                                      "s_per_page_median": round(statistics.median(ok), 2) if ok else None, "s_per_page_max": round(max(ok), 2) if ok else None}
            print(kind, name, omp[f"{kind}/{name}"]["ok"], "/", len(rows), omp[f"{kind}/{name}"]["s_per_page_median"], flush=True)
    res["ocrmypdf"] = omp
    res["ocrmypdf_version"] = subprocess.run(["ocrmypdf", "--version"], capture_output=True, text=True).stdout.strip()
    print(write_result("m1", res))


if __name__ == "__main__":
    main()

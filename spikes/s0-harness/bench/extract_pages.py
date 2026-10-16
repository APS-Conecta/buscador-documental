"""Run with the SYSTEM python (has pikepdf via the ocrmypdf apt package): /usr/bin/python3 extract_pages.py <manifest> <out_dir>
Picks a fixed deterministic sample per kind and writes, per sample, a 1-page PDF and (for image pages) the embedded JPEG.
Writes <out_dir>/samples.json. Nothing here times anything."""
import json
import os
import shutil
import sys

import pikepdf
from pikepdf import PdfImage

manifest, out = sys.argv[1:3]
corpus = os.path.dirname(manifest)
N = {"scanned": 8, "mixed": 6, "fake_visible": 6, "fake_invisible": 6, "photo": 6, "native": 4}
os.makedirs(out, exist_ok=True)
files = [json.loads(l) for l in open(manifest, encoding="utf-8")]
samples = []
for kind, n in N.items():
    for f in [x for x in files if x["kind"] == kind][:n]:
        pg = next((i for i, io_ in enumerate(f["image_only"]) if io_ or kind == "native"), 0) if kind != "mixed" else 1
        base = f"{kind}_{f['file_id']:06d}_p{pg}"
        rec = {"id": base, "kind": kind, "file_id": f["file_id"], "page": pg, "truth": f["page_text"][pg]}
        src = f"{corpus}/{f['path']}"
        if kind == "photo":
            shutil.copy(src, f"{out}/{base}.jpg")
            rec["image"] = f"{base}.jpg"
        else:
            with pikepdf.open(src) as pdf:
                dst = pikepdf.Pdf.new()
                dst.pages.append(pdf.pages[pg])
                dst.save(f"{out}/{base}.pdf")
                imgs = list(pdf.pages[pg].images.values())
                if imgs:
                    PdfImage(imgs[0]).extract_to(fileprefix=f"{out}/{base}")
                    rec["image"] = next(x for x in os.listdir(out) if x.startswith(base + ".") and not x.endswith(".pdf"))
            rec["pdf"] = f"{base}.pdf"
        samples.append(rec)
json.dump(samples, open(f"{out}/samples.json", "w"), ensure_ascii=False, indent=1)
print(len(samples), "samples")

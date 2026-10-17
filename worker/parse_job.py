# SPDX-License-Identifier: AGPL-3.0-or-later
"""Child process of the parser container: reads the request file, writes the result file. argv: request, result."""
import json
import sys

import extract
import ocr

OCR_KINDS = (b"%PDF", b"\x89PNG", b"\xff\xd8\xff", b"II*\x00", b"MM\x00*")


def main(req_path: str, res_path: str) -> None:
    with open(req_path) as fh:
        req = json.load(fh)
    try:
        pages = extract.extract(req["input"])              # refuses hostile or unusable files before anything heavy runs
        with open(req["input"], "rb") as fh:
            needs_ocr = fh.read(4).startswith(OCR_KINDS)
        if needs_ocr:
            pages = ocr.run(req["input"])                  # STUB(S2 phase 3): per-page text-layer policy
        out = {"status": "ok", "pages": pages, "ocr_ver": ocr.version() if needs_ocr else "extract-1"}
    except extract.Refusal as e:
        out = {"status": "error", "reason": e.reason, "detail": str(e)}
    except ocr.OcrTimeout:
        out = {"status": "error", "reason": "timeout"}
    except ocr.OcrFailed:
        out = {"status": "error", "reason": "ocr_failed"}
    with open(res_path, "w") as fh:
        json.dump(out, fh)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])

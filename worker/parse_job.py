# SPDX-License-Identifier: AGPL-3.0-or-later
"""Child process of the parser container: reads the request file, writes the result file. argv: request, result."""
import json
import sys

import extract
import jobbox
import ocr
import pdfocr

OCR_KINDS = (b"%PDF", b"\x89PNG", b"\xff\xd8\xff", b"II*\x00", b"MM\x00*")


def main(req_path: str, res_path: str) -> None:
    with open(req_path) as fh:
        req = json.load(fh)
    try:
        pages = extract.extract(req["input"])              # refuses hostile or unusable files before anything heavy runs
        with open(req["input"], "rb") as fh:               # one header read answers both routing questions below
            head = fh.read(4)
        if head.startswith(OCR_KINDS):
            layer = pages if pages and head == b"%PDF" else None
            out = {"status": "ok", **pdfocr.run(req["input"], layer=layer,
                                                announce=lambda s: jobbox.announce(res_path, s)),
                   "ocr_ver": pdfocr.version()}
        else:
            out = {"status": "ok", "pages": pages, "ocr_ver": "extract-1"}
    except extract.Refusal as e:
        out = {"status": "error", "reason": e.reason, "detail": str(e)}
    except ocr.OcrTimeout:
        out = {"status": "error", "reason": "timeout"}
    except ocr.OcrFailed:
        out = {"status": "error", "reason": "ocr_failed"}
    except Exception as e:                                 # a bug or a hostile shape we did not foresee: terminal, not retried
        out = {"status": "error", "reason": "internal", "detail": type(e).__name__}      # never the message: it can hold document text
    with open(res_path, "w") as fh:
        json.dump(out, fh)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])

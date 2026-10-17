# SPDX-License-Identifier: AGPL-3.0-or-later
"""Child process of the parser container: reads the request file, writes the result file. argv: request, result."""
import json
import sys

import ocr


def main(req_path: str, res_path: str) -> None:
    with open(req_path) as fh:
        req = json.load(fh)
    try:
        if req.get("kind") != "pdf":
            out = {"status": "error", "reason": "corrupt"}      # STUB(S2 phase 2): other formats
        else:
            out = {"status": "ok", "pages": ocr.run(req["input"]), "ocr_ver": ocr.version()}
    except ocr.OcrTimeout:
        out = {"status": "error", "reason": "timeout"}
    except ocr.OcrFailed:
        out = {"status": "error", "reason": "ocr_failed"}
    with open(res_path, "w") as fh:
        json.dump(out, fh)


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])

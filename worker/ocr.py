# SPDX-License-Identifier: AGPL-3.0-or-later
"""OCR through ocrmypdf with a sidecar text file. Real extractors for born-digital formats are S2."""
import os
import subprocess
import tempfile
from functools import lru_cache

TIMEOUT = int(os.environ.get("BD_OCR_TIMEOUT", "900"))
LANGS = os.environ.get("BD_OCR_LANGS", "spa+eng")


class OcrFailed(Exception):
    pass


class OcrTimeout(Exception):
    pass


@lru_cache(maxsize=1)
def version() -> str:
    """Tool versions recorded with every result (ocr_ver)."""
    def first(cmd):
        r = subprocess.run(cmd, capture_output=True, text=True)
        return (r.stdout or r.stderr).strip().splitlines()[0] if (r.stdout or r.stderr) else "unknown"
    return f"ocrmypdf {first(['ocrmypdf', '--version'])}; {first(['tesseract', '--version'])}; langs={LANGS}"

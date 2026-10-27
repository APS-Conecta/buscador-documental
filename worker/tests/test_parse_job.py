# SPDX-License-Identifier: AGPL-3.0-or-later
import json

import pytest

import extract
import fixtures as fx
import ocr
import parse_job
import pdfocr


def run(tmp_path, path, **extra):
    req, res = tmp_path / "r.json", tmp_path / "out.json"
    req.write_text(json.dumps({"kind": "file", "input": path, **extra}))
    parse_job.main(str(req), str(res))
    return json.loads(res.read_text())


def test_born_digital_documents_are_extracted_without_ocr(tmp_path, monkeypatch):
    monkeypatch.setattr(pdfocr, "run", lambda *a, **k: (_ for _ in ()).throw(AssertionError("no OCR for a docx")))
    out = run(tmp_path, fx.docx(tmp_path / "a.docx", ["Hola"]))
    assert out["status"] == "ok" and out["pages"] == ["Hola"] and "ocr_ver" in out


def test_pdfs_and_images_go_through_ocr_after_the_safety_checks(tmp_path, monkeypatch):
    monkeypatch.setattr(pdfocr, "run", lambda path, layer=None: {"pages": ["texto reconocido"], "conf": [88.0], "source": ["ocr"]})
    out = run(tmp_path, fx.pdf_text(tmp_path / "a.pdf"))
    assert out["status"] == "ok" and out["pages"] == ["texto reconocido"] and out["conf"] == [88.0] and out["source"] == ["ocr"]


def test_refusals_carry_the_database_reason(tmp_path, monkeypatch):
    monkeypatch.setattr(pdfocr, "run", lambda *a, **k: {"pages": ["no"], "conf": [None], "source": ["layer"]})
    assert run(tmp_path, fx.zip_bomb_docx(tmp_path / "b.docx"))["reason"] == "unsafe"
    assert run(tmp_path, fx.pdf_text(tmp_path / "e.pdf", encrypt=("o", "u")))["reason"] == "encrypted"
    assert run(tmp_path, fx.truncated_zip(tmp_path / "t.docx"))["reason"] == "corrupt"
    assert run(tmp_path, fx.pdf_text(tmp_path / "m.pdf", pages=301))["reason"] == "too_large"
    assert run(tmp_path, fx.pixel_bomb_png(tmp_path / "p.png"))["reason"] == "unsafe"


def test_an_unexpected_error_in_the_child_is_a_terminal_internal_answer(tmp_path, monkeypatch):
    monkeypatch.setattr(pdfocr, "run", lambda *a, **k: (_ for _ in ()).throw(TypeError("surprise")))
    out = run(tmp_path, fx.pdf_text(tmp_path / "a.pdf"))
    assert out["status"] == "error" and out["reason"] == "internal" and "TypeError" in out["detail"]


@pytest.mark.parametrize("kind,make", [
    ("pdf", lambda p: fx.pdf_text(p / "a.pdf")),
    ("image", lambda p: fx.scan_image().save(str(p / "a.png")) or str(p / "a.png")),
], ids=["pdf", "image"])
def test_the_input_header_is_read_once_and_the_layer_is_routed_by_kind(tmp_path, monkeypatch, kind, make):
    """Issue 16: one header read answers both routing questions (OCR-worthy? PDF?), where before the file was opened
    twice. A PDF keeps the layer extract already produced; an image is routed without one."""
    import builtins
    p = make(tmp_path)
    got = {}

    def fake_run(path, layer=None):
        got["layer"] = layer
        return {"pages": ["x"], "conf": [None], "source": ["layer"]}

    monkeypatch.setattr(extract, "extract", lambda path: ["página uno"])     # count parse_job's own file reads only
    monkeypatch.setattr(pdfocr, "run", fake_run)
    real_open, seen = builtins.open, []

    def counting(file, *a, **k):
        if str(file) == p:
            seen.append(file)
        return real_open(file, *a, **k)

    monkeypatch.setattr(builtins, "open", counting)
    out = run(tmp_path, p)
    assert out["status"] == "ok"
    assert seen.count(p) == 1                    # one open of the input for the routing decisions
    assert got["layer"] == (["página uno"] if kind == "pdf" else None)


SCANS = pytest.mark.parametrize("scan", [
    lambda p, lines=fx.LINES: fx.scan_pdf(p / "s.pdf", lines=lines),
    lambda p, lines=fx.LINES: fx.scan_image(lines).save(p / "s.png") or str(p / "s.png"),
], ids=["pdf", "png"])


@SCANS
def test_a_page_past_the_page_timeout_fails_the_file_as_timeout(tmp_path, monkeypatch, scan):
    """Issue 48: a page whose OCR outlasts PAGE_TIMEOUT fails the file with the retryable `timeout`, read at most once."""
    import os
    path = scan(tmp_path)
    bin_dir, log = fx.slow_tesseract(tmp_path, 8)
    monkeypatch.setenv("PATH", f"{bin_dir}:{os.environ['PATH']}")
    monkeypatch.setattr(pdfocr, "PAGE_TIMEOUT", 4)
    assert run(tmp_path, path) == {"status": "error", "reason": "timeout"}
    assert len(log.read_text().splitlines()) <= 1      # 0 when ocrmypdf's start-up alone outlasts the limit


@SCANS
def test_a_blank_scan_is_an_empty_page_and_still_indexes(tmp_path, scan):
    """Issue 48: a page tesseract finds blank is empty text, not a failure."""
    out = run(tmp_path, scan(tmp_path, lines=[]))
    assert out["status"] == "ok" and out["pages"] == [""]

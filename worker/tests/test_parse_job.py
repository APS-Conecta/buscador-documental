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
    monkeypatch.setattr(pdfocr, "run", lambda path, layer=None, **k: {"pages": ["texto reconocido"], "conf": [88.0], "source": ["ocr"]})
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

    def fake_run(path, layer=None, **k):
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


def test_the_ocr_time_is_announced_before_ocr_starts(tmp_path, monkeypatch):
    """Issue 46: the child tells the parser how long its OCR will take before the first page is read."""
    prose = "La paciente fue derivada a nutricion y control en tres meses"
    pdf = fx.merge_pdfs(tmp_path / "m.pdf", [
        fx.pdf_text(tmp_path / "n.pdf", text=prose),
        fx.scan_pdf(tmp_path / "s.pdf"),
        fx.scan_pdf(tmp_path / "l.pdf", layer_text=prose)])       # a prose layer: only the scan flag sends it to OCR
    sidecar = tmp_path / "out.json.extra"                          # next to the result run() asks for
    seen = []

    def first_ocr(*a, **k):
        if not seen:
            seen.append(sidecar.read_text() if sidecar.exists() else None)
        return "texto reconocido", str(tmp_path / "o.pdf")

    monkeypatch.setattr(pdfocr, "ocr_page", first_ocr)
    monkeypatch.setattr(pdfocr, "page_confidence", lambda *a, **k: 50.0)
    out = run(tmp_path, pdf)
    assert out["source"] == ["layer", "ocr", "ocr"]
    assert seen == [str(2 * pdfocr.OCR_PAGE_SECONDS)]

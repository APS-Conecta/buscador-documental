# SPDX-License-Identifier: AGPL-3.0-or-later
import json

import fixtures as fx
import ocr
import parse_job


def run(tmp_path, path, **extra):
    req, res = tmp_path / "r.json", tmp_path / "out.json"
    req.write_text(json.dumps({"kind": "file", "input": path, **extra}))
    parse_job.main(str(req), str(res))
    return json.loads(res.read_text())


def test_born_digital_documents_are_extracted_without_ocr(tmp_path, monkeypatch):
    monkeypatch.setattr(ocr, "run", lambda p: (_ for _ in ()).throw(AssertionError("no OCR for a docx")))
    out = run(tmp_path, fx.docx(tmp_path / "a.docx", ["Hola"]))
    assert out["status"] == "ok" and out["pages"] == ["Hola"] and "ocr_ver" in out


def test_pdfs_and_images_go_through_ocr_after_the_safety_checks(tmp_path, monkeypatch):
    monkeypatch.setattr(ocr, "run", lambda p: ["texto reconocido"])
    out = run(tmp_path, fx.pdf_text(tmp_path / "a.pdf"))
    assert out["status"] == "ok" and out["pages"] == ["texto reconocido"]


def test_refusals_carry_the_database_reason(tmp_path, monkeypatch):
    monkeypatch.setattr(ocr, "run", lambda p: ["no"])
    assert run(tmp_path, fx.zip_bomb_docx(tmp_path / "b.docx"))["reason"] == "unsafe"
    assert run(tmp_path, fx.pdf_text(tmp_path / "e.pdf", encrypt=("o", "u")))["reason"] == "encrypted"
    assert run(tmp_path, fx.truncated_zip(tmp_path / "t.docx"))["reason"] == "corrupt"
    assert run(tmp_path, fx.pdf_text(tmp_path / "m.pdf", pages=301))["reason"] == "too_large"
    assert run(tmp_path, fx.pixel_bomb_png(tmp_path / "p.png"))["reason"] == "unsafe"

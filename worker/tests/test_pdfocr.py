# SPDX-License-Identifier: AGPL-3.0-or-later
"""Per-page OCR policy (ADR-0012): classify every page, OCR only what needs it, report confidence for OCR'd pages."""
import pytest

import fixtures as fx
import pdfocr

KEY = "hemoglobina glicosilada"


def has(text, words=KEY):
    t = " ".join(text.lower().split())
    return all(w in t for w in words.split())


# ---- classification ----
@pytest.mark.parametrize("text,expected", [
    ("La paciente fue derivada a nutricion y control en tres meses. Se indica dieta y ejercicio.", "native"),
    ("The patient was referred to nutrition and follow-up in three months for review.", "native"),
    ("", "image-only"),
    ("  \n ", "image-only"),
    ("a", "image-only"),
    ("#@!$%^&*()_+{}|:<>?~" * 6, "untrusted"),
    ("��� xx �" * 10, "untrusted"),
    ("qzxv wjkp bnmt rtyu plkj hgfd sdfg" * 5, "untrusted"),
])
def test_classify(text, expected):
    assert pdfocr.classify(text) == expected


def test_short_but_real_text_is_native():
    assert pdfocr.classify("Página 3 de 10") == "native"


# ---- image-dominated pages ----
def test_plausible_text_on_an_image_dominated_page_is_not_trusted():
    prose = "La paciente fue derivada a nutricion y control en tres meses. Se indica dieta y ejercicio."
    assert pdfocr.classify(prose) == "native"
    assert pdfocr.classify(prose, image_heavy=True) == "untrusted"
    assert pdfocr.classify("", image_heavy=True) == "image-only"


# ---- pipeline ----
def test_native_pages_are_kept_without_ocr(tmp_path, monkeypatch):
    monkeypatch.setattr(pdfocr, "ocr_page", lambda *a, **k: pytest.fail("no OCR for a native page"))
    pdf = fx.pdf_text(tmp_path / "n.pdf", text="La paciente fue derivada a nutricion y control en tres meses", pages=2)
    out = pdfocr.run(pdf)
    assert out["source"] == ["layer", "layer"] and out["conf"] == [None, None]
    assert "derivada a nutricion" in out["pages"][0]


def test_scanned_page_is_ocrd_with_confidence(tmp_path):
    out = pdfocr.run(fx.scan_pdf(tmp_path / "s.pdf"))
    assert out["source"] == ["ocr"] and has(out["pages"][0])
    assert 50 <= out["conf"][0] <= 100


@pytest.mark.parametrize("angle", [90, 180, 270])
def test_rotated_scans_are_read(tmp_path, angle):
    out = pdfocr.run(fx.scan_pdf(tmp_path / f"r{angle}.pdf", rotate=angle))
    assert has(out["pages"][0]), out["pages"][0][:200]


@pytest.mark.parametrize("lines", [fx.LINES, ["Informe de laboratorio", "", "Hemoglobina glicosilada 6,8 por ciento."], ["Hemoglobina glicosilada"]],
                         ids=["4-lines", "2-lines", "1-line"])
def test_upright_sparse_pages_are_not_rotated_by_mistake(tmp_path, lines):
    out = pdfocr.run(fx.scan_pdf(tmp_path / "u.pdf", lines=lines))
    assert has(out["pages"][0]), out["pages"][0][:200]


def test_a_garbage_text_layer_is_replaced_by_ocr(tmp_path):
    out = pdfocr.run(fx.scan_pdf(tmp_path / "g.pdf", garbage_layer=True))
    assert out["source"] == ["ocr"] and has(out["pages"][0]) and "#@!" not in out["pages"][0]


def test_mixed_document_is_decided_page_by_page(tmp_path):
    native = fx.pdf_text(tmp_path / "n.pdf", text="La paciente fue derivada a nutricion y control en tres meses")
    scan = fx.scan_pdf(tmp_path / "s.pdf")
    out = pdfocr.run(fx.merge_pdfs(tmp_path / "m.pdf", [native, scan, native]))
    assert out["source"] == ["layer", "ocr", "layer"]
    assert out["conf"][0] is None and out["conf"][1] is not None
    assert has(out["pages"][1]) and "derivada" in out["pages"][2]


def test_a_page_that_times_out_fails_the_job_as_a_timeout(tmp_path, monkeypatch):
    import ocr
    def slow(*a, **k):
        raise ocr.OcrTimeout()
    monkeypatch.setattr(pdfocr, "ocr_page", slow)
    with pytest.raises(ocr.OcrTimeout):
        pdfocr.run(fx.scan_pdf(tmp_path / "s.pdf"))


def test_ocr_version_changes_with_the_policy(monkeypatch):
    a = pdfocr.version()
    monkeypatch.setattr(pdfocr, "POLICY", "perpage-test-policy")
    pdfocr.version.cache_clear()
    assert pdfocr.version() != a
    pdfocr.version.cache_clear()


def test_images_are_ocrd_as_one_page(tmp_path):
    p = tmp_path / "scan.png"
    fx.scan_image().save(p)
    out = pdfocr.run(str(p))
    assert out["source"] == ["ocr"] and has(out["pages"][0])


REVERSED = " ".join(w[::-1] for w in "la paciente fue derivada a nutricion y control en tres meses de la unidad".split())


@pytest.mark.parametrize("visible", [False, True], ids=["invisible", "visible"])
def test_a_plausible_looking_wrong_layer_under_a_scan_is_replaced(tmp_path, visible):
    """The S0 'fake layer' kinds: a short line of reversed words that passes the vocabulary test."""
    out = pdfocr.run(fx.scan_pdf(tmp_path / "f.pdf", layer_text=REVERSED, visible=visible))
    assert out["source"] == ["ocr"] and has(out["pages"][0])
    if not visible:
        assert "etneicap" not in out["pages"][0]


def test_a_small_logo_does_not_turn_a_born_digital_page_into_a_scan(tmp_path, monkeypatch):
    monkeypatch.setattr(pdfocr, "ocr_page", lambda *a, **k: pytest.fail("a logo is not a scan"))
    out = pdfocr.run(fx.native_with_logo(tmp_path / "l.pdf"))
    assert out["source"] == ["layer"] and "derivada" in out["pages"][0]

# SPDX-License-Identifier: AGPL-3.0-or-later
"""Per-page OCR policy (ADR-0012): classify every page, OCR only what needs it, report confidence for OCR'd pages."""
import tempfile
from pathlib import Path

import pytest

import fixtures as fx
import ocr
import pdfocr

KEY = "hemoglobina glicosilada"
NATIVE = "La paciente fue derivada a nutricion y control en tres meses"


@pytest.fixture
def scratch(tmp_path, monkeypatch):
    """The run's temp root. tempfile.gettempdir() caches its answer, so TMPDIR set here would not take effect."""
    root = tmp_path / "scratch"
    root.mkdir()
    monkeypatch.setattr(tempfile, "tempdir", str(root))
    return root


def has(text, words=KEY):
    t = " ".join(text.lower().split())
    return all(w in t for w in words.split())


# ---- classification ----
@pytest.mark.parametrize("text,expected", [
    ("La paciente fue derivada a nutricion y control en tres meses. Se indica dieta y ejercicio.", "native"),
    ("The patient was referred to nutrition and follow-up in three months for review.", "native"),
    ("", "image-only"),
    ("  \n ", "image-only"),
    ("a", "untrusted"),                       # 1-4 characters: a bare page number cannot be told from debris
    ("3 1", "untrusted"),
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


def test_a_page_with_only_a_page_number_is_ocrd_without_the_skip_marker(tmp_path):
    out = pdfocr.run(fx.pdf_text(tmp_path / "num.pdf", text="3", pages=1))
    assert out["source"] == ["ocr"]
    assert not out["pages"][0].startswith("[OCR skipped")


def test_the_skip_marker_is_never_stored_as_text(tmp_path, monkeypatch):
    calls = []
    def fake(src, force, workdir, image=False):
        calls.append(force)
        return ("[OCR skipped on page(s) 1]" if not force else "texto real"), str(tmp_path / "o.pdf")
    monkeypatch.setattr(pdfocr, "_ocr_page_once", fake)
    monkeypatch.setattr(pdfocr, "page_confidence", lambda *a: 50.0)
    out = pdfocr.run(fx.pdf_text(tmp_path / "x.pdf", text="La paciente fue derivada a nutricion y control", pages=1), layer=[""])
    assert out["pages"] == ["texto real"] and calls == [False, True]


def test_a_ghostscript_timeout_is_an_ocr_timeout(tmp_path, monkeypatch):
    import subprocess
    import ocr
    def slow(cmd, *a, **k):
        raise subprocess.TimeoutExpired(cmd, 1)
    monkeypatch.setattr(pdfocr.subprocess, "run", slow)
    with pytest.raises(ocr.OcrTimeout):
        pdfocr.page_confidence(str(tmp_path / "x.pdf"), str(tmp_path))


def test_a_scan_wrapped_in_a_form_xobject_is_still_a_scan(tmp_path):
    import pikepdf
    p = fx.pdf_with_image(tmp_path / "f.pdf", 2000, 1500, in_form=True)
    with pikepdf.open(p) as pdf:
        assert pdfocr._image_heavy(pdf) == [True]


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


def test_the_source_pdf_is_opened_once_no_matter_how_many_pages_need_ocr(tmp_path, monkeypatch):
    """Issue 16: scan detection and per-page extraction share one pikepdf parse; before, every OCR'd page reopened
    the whole document (O(N^2) on multi-page scans)."""
    import pikepdf
    monkeypatch.setattr(pdfocr, "ocr_page", lambda *a, **k: ("texto ocr", str(tmp_path / "o.pdf")))
    monkeypatch.setattr(pdfocr, "page_confidence", lambda *a, **k: 50.0)
    real_open, opens = pikepdf.open, []

    def counting(path, *a, **k):
        opens.append(str(path))
        return real_open(path, *a, **k)

    monkeypatch.setattr(pikepdf, "open", counting)
    pdf = fx.pdf_text(tmp_path / "m.pdf", text="#@!", pages=3)      # every layer is debris: all pages are OCR'd
    out = pdfocr.run(pdf, layer=["", "", ""])
    assert out["source"] == ["ocr", "ocr", "ocr"]
    assert opens == [pdf]                                            # one open, not one per page


def test_each_ocrd_page_frees_its_scratch_before_the_next_page(tmp_path, monkeypatch, scratch):
    """Issue 47: every OCR'd page's in.pdf, out.pdf, sidecar and PNG stayed until the whole document was done, so a
    long grey scan ran the parser's 512 MB tmpfs out of space part-way through."""
    leftovers, calls = [], []

    def fake_ocr(src, force, workdir, image=False):
        work = Path(workdir)
        assert scratch in work.parents
        leftovers.append(sorted(str(p.relative_to(scratch)) for p in scratch.rglob("*")
                                if p != work and work not in p.parents and p not in work.parents))
        calls.append(src)
        out = work / "out.pdf"
        out.write_bytes(b"%PDF")
        (work / "side.txt").write_text("sidecar")
        return f"texto {len(calls)}", str(out)

    def fake_confidence(pdf, workdir):
        assert Path(pdf).is_file() and Path(pdf).parent == Path(workdir)
        (Path(workdir) / "page.png").write_bytes(b"\x89PNG")
        return float(len(calls))

    monkeypatch.setattr(pdfocr, "ocr_page", fake_ocr)
    monkeypatch.setattr(pdfocr, "page_confidence", fake_confidence)
    pdf = fx.pdf_text(tmp_path / "m.pdf", text="#@!", pages=3)
    out = pdfocr.run(pdf, layer=["", NATIVE, ""])
    assert leftovers == [[], []]
    assert out == {"pages": ["texto 1", NATIVE, "texto 2"], "conf": [1.0, None, 2.0], "source": ["ocr", "layer", "ocr"]}
    assert list(scratch.iterdir()) == []


@pytest.mark.parametrize("error", [ocr.OcrFailed, ocr.OcrTimeout])
def test_a_failing_page_leaves_no_scratch_behind(tmp_path, monkeypatch, scratch, error):
    calls = []

    def fake_ocr(src, force, workdir, image=False):
        calls.append(src)
        out = Path(workdir) / "out.pdf"
        out.write_bytes(b"%PDF")
        if len(calls) == 2:
            raise error()
        return "texto", str(out)

    def fake_confidence(pdf, workdir):
        (Path(workdir) / "page.png").write_bytes(b"\x89PNG")
        return 50.0

    monkeypatch.setattr(pdfocr, "ocr_page", fake_ocr)
    monkeypatch.setattr(pdfocr, "page_confidence", fake_confidence)
    with pytest.raises(error):
        pdfocr.run(fx.pdf_text(tmp_path / "f.pdf", text="#@!", pages=2), layer=["", ""])
    assert list(scratch.iterdir()) == []


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

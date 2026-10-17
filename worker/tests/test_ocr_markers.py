# SPDX-License-Identifier: AGPL-3.0-or-later
"""The sidecar marker ocrmypdf writes for a page it did not OCR must be replaced by the page's own text layer."""
import fixtures as fx
import ocr


def test_pages_ocrmypdf_skips_come_back_with_their_text_layer(tmp_path):
    pdf = fx.pdf_text(tmp_path / "a.pdf", pages=2, text="Receta hemoglobina")
    assert ocr.run(pdf) == ["Receta hemoglobina 1", "Receta hemoglobina 2"]


def test_a_sidecar_that_does_not_match_the_page_count_is_refused(tmp_path, monkeypatch):
    import pytest
    pdf = fx.pdf_text(tmp_path / "b.pdf", pages=3)
    with pytest.raises(ocr.OcrFailed):
        ocr._align(pdf, ["only one page of text"])

# SPDX-License-Identifier: AGPL-3.0-or-later
"""Tier 1-2 extractors and the hostile-file policy (A-SAFE-1, A-SAFE-3). Every hostile case ends in a typed refusal."""
import hashlib

import pytest

import extract
import fixtures as fx


def sha(p):
    return hashlib.sha256(open(p, "rb").read()).hexdigest()


def test_text_files_in_utf8_and_latin1(tmp_path):
    assert extract.extract(fx.txt(tmp_path / "a.txt", "Ñandú y acción")) == ["Ñandú y acción"]
    assert extract.extract(fx.txt(tmp_path / "b.txt", "Ñandú y acción", "latin-1")) == ["Ñandú y acción"]


def test_docx_paragraphs_and_table_rows(tmp_path):
    pages = extract.extract(fx.docx(tmp_path / "a.docx", ["Primero", "Segundo"], table=[["a", "b"], ["c", "d"]]))
    assert len(pages) == 1
    assert "Primero\nSegundo" in pages[0] and "a\tb\nc\td" in pages[0]


def test_pptx_one_page_per_slide_in_numeric_order(tmp_path):
    slides = [[f"s{i}"] for i in range(1, 12)]
    assert extract.extract(fx.pptx(tmp_path / "a.pptx", slides)) == [f"s{i}" for i in range(1, 12)]


def test_odt_text(tmp_path):
    assert extract.extract(fx.odt(tmp_path / "a.odt", ["uno", "dos"])) == ["uno\ndos"]


def test_xlsx_one_page_per_sheet_rows_tab_joined(tmp_path):
    pages = extract.extract(fx.xlsx(tmp_path / "a.xlsx", {"A": [["x", 1], ["y", 2]], "B": [["z"]]}))
    assert pages == ["x\t1\ny\t2", "z"]


def test_pdf_text_layer(tmp_path):
    assert extract.extract(fx.pdf_text(tmp_path / "a.pdf", pages=2)) == ["Hola mundo 1", "Hola mundo 2"]


def test_owner_password_only_pdf_is_read(tmp_path):
    assert extract.extract(fx.pdf_text(tmp_path / "o.pdf", encrypt=("owner", ""))) == ["Hola mundo 1"]


@pytest.mark.parametrize("make,exc", [
    (lambda p: fx.pdf_text(p, encrypt=("o", "user-secret")), extract.Encrypted),
    (lambda p: fx.truncated_zip(p), extract.Corrupt),
    (lambda p: fx.txt(p, ""), extract.Corrupt),
    (lambda p: fx.pdf_text(p, pages=extract.MAX_PAGES + 1), extract.TooLarge),
    (lambda p: fx.big_xlsx(p), extract.TooLarge),
    (lambda p: fx.zip_bomb_docx(p), extract.Unsafe),
    (lambda p: fx.nested_xml_docx(p), extract.Unsafe),
    (lambda p: fx.entity_bomb_docx(p), extract.Unsafe),
    (lambda p: fx.pixel_bomb_png(p), extract.Unsafe),
    (lambda p: fx.pixel_bomb_pdf(p), extract.Unsafe),
], ids=["user-password-pdf", "truncated-zip", "empty-file", "too-many-pages", "big-xlsx", "zip-bomb", "nested-xml",
        "entity-bomb", "pixel-bomb-png", "pixel-bomb-pdf"])
def test_hostile_or_unusable_files_end_in_a_typed_refusal(tmp_path, make, exc):
    p = make(tmp_path / "f.bin")
    with pytest.raises(exc):
        extract.extract(p)


def test_reasons_map_to_the_database_enum():
    assert {extract.Unsafe: "unsafe", extract.Corrupt: "corrupt", extract.Encrypted: "encrypted", extract.TooLarge: "too_large"} == \
        {c: c.reason for c in (extract.Unsafe, extract.Corrupt, extract.Encrypted, extract.TooLarge)}


def test_extraction_never_modifies_the_input(tmp_path):
    paths = [fx.txt(tmp_path / "a.txt", "x"), fx.docx(tmp_path / "a.docx", ["x"]), fx.pdf_text(tmp_path / "a.pdf"),
             fx.zip_bomb_docx(tmp_path / "bomb.docx"), fx.xlsx(tmp_path / "a.xlsx", {"s": [[1]]})]
    before = {p: sha(p) for p in paths}
    for p in paths:
        try:
            extract.extract(p)
        except extract.Refusal:
            pass
    assert {p: sha(p) for p in paths} == before

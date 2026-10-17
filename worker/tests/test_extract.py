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


# ---------- S2 review fixes ----------
def test_utf16_text_is_read(tmp_path):
    assert extract.extract(fx.utf16_txt(tmp_path / "u.txt", "Hola mundo ñandú acción")) == ["Hola mundo ñandú acción"]


def test_a_truncated_utf16_file_is_corrupt_not_a_crash(tmp_path):
    p = tmp_path / "t.txt"
    p.write_bytes("Hola".encode("utf-16")[:-1])
    with pytest.raises(extract.Corrupt):
        extract.extract(str(p))


def test_nested_tables_and_text_boxes_keep_all_their_text(tmp_path):
    inner = "<w:tbl><w:tr><w:tc><w:p><w:r><w:t>inner</w:t></w:r></w:p></w:tc></w:tr></w:tbl>"
    nested = (f"<w:tbl><w:tr><w:tc><w:p><w:r><w:t>outer1</w:t></w:r></w:p>{inner}</w:tc>"
              "<w:tc><w:p><w:r><w:t>outer2</w:t></w:r></w:p></w:tc></w:tr></w:tbl>")
    box = ("<w:p><w:r><w:t>before</w:t></w:r><w:r><w:txbxContent><w:p><w:r><w:t>boxed</w:t></w:r></w:p></w:txbxContent></w:r>"
           "<w:r><w:t>after</w:t></w:r></w:p>")
    text = extract.extract(fx.docx_raw(tmp_path / "n.docx", nested + box))[0]
    assert {"outer1", "inner", "outer2", "boxed"} <= set(text.split())
    assert "before" in text and "after" in text and "beforeafter" not in text


@pytest.mark.parametrize("make,expected", [
    (lambda p: fx.docx_raw(p, '<w:p><w:r><w:t>Nombre</w:t></w:r><w:r><w:tab/></w:r><w:r><w:t>Rut</w:t></w:r></w:p>'), ["Nombre", "Rut"]),
    (lambda p: fx.docx_raw(p, '<w:p><w:r><w:t>Calle</w:t></w:r><w:r><w:br/></w:r><w:r><w:t>Número</w:t></w:r></w:p>'), ["Calle", "Número"]),
    (lambda p: fx.pptx_raw(p, '<a:p><a:r><a:t>Uno</a:t></a:r><a:br/><a:r><a:t>Dos</a:t></a:r></a:p>'), ["Uno", "Dos"]),
    (lambda p: fx.odt_raw(p, '<text:p>Nombre<text:tab/>Rut</text:p>'), ["Nombre", "Rut"]),
    (lambda p: fx.odt_raw(p, '<text:p>Calle<text:line-break/>Número</text:p>'), ["Calle", "Número"]),
    (lambda p: fx.odt_raw(p, '<text:p>Uno<text:s/>Dos</text:p>'), ["Uno", "Dos"]),
], ids=["docx-tab", "docx-br", "pptx-br", "odt-tab", "odt-linebreak", "odt-space"])
def test_tabs_and_line_breaks_separate_words(tmp_path, make, expected):
    text = " ".join(extract.extract(make(tmp_path / "f.bin")))
    assert text.split() == expected


def test_a_password_protected_office_file_is_encrypted_not_corrupt(tmp_path):
    with pytest.raises(extract.Encrypted):
        extract.extract(fx.ole2(tmp_path / "locked.docx"))


@pytest.mark.parametrize("make,exc", [
    (lambda p: fx.pdf_with_image(p, 20000, 20000, in_form=True), extract.Unsafe),
    (lambda p: fx.pdf_with_image(p, 100, 100, width_value=pikepdf_name()), extract.Corrupt),
    (lambda p: fx.pdf_huge_page(p), extract.TooLarge),
    (lambda p: fx.tiff_frames(p, 3), extract.Unsupported),
    (lambda p: fx.pptx(p, [["s"]] * (extract.MAX_PAGES + 1)), extract.TooLarge),
    (lambda p: fx.many_sheets_xlsx(p, extract.MAX_PAGES + 1), extract.TooLarge),
], ids=["pixel-bomb-in-form", "non-numeric-width", "huge-mediabox", "multiframe-tiff", "301-slides", "301-sheets"])
def test_more_hostile_shapes_end_in_a_typed_refusal(tmp_path, make, exc):
    with pytest.raises(exc):
        extract.extract(make(tmp_path / "f.bin"))


def pikepdf_name():
    import pikepdf
    return pikepdf.Name.NotANumber


def test_a_normal_a4_page_and_a_single_frame_tiff_still_pass(tmp_path):
    assert extract.extract(fx.pdf_text(tmp_path / "ok.pdf"))
    assert extract.extract(fx.tiff_frames(tmp_path / "one.tiff", 1)) == [""]

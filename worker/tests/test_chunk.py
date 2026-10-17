# SPDX-License-Identifier: AGPL-3.0-or-later
"""Structure-aware chunker: example tests plus Hypothesis properties (S2 plan, phase 4)."""
import os
import re

import pytest
from hypothesis import HealthCheck, given, settings, strategies as st

import chunk

SETTINGS = settings(max_examples=60, deadline=None, suppress_health_check=[HealthCheck.too_slow, HealthCheck.data_too_large])
WORDS = ["paciente", "derivada", "nutrición", "control", "ley", "21.719", "6,8", "12-345", "salud", "datos", "personales",
         "responsable", "tratamiento", "Hemoglobina", "glicosilada", "artículo", "mes", "centro", "familiar", "RUT", "12.345.678-9"]


def sentence(draw):
    n = draw(st.integers(3, 25))
    ws = draw(st.lists(st.sampled_from(WORDS), min_size=n, max_size=n))
    return " ".join(ws).capitalize() + "."


@st.composite
def paragraph(draw):
    return " ".join(sentence(draw) for _ in range(draw(st.integers(1, 12))))


@st.composite
def legal_doc(draw):
    """Pages of articulated text: optional Título/Capítulo lines, 'Artículo N.' headers, paragraphs, a table row now and then."""
    lines = []
    for n in range(1, draw(st.integers(1, 10)) + 1):
        if draw(st.booleans()):
            lines.append(draw(st.sampled_from(["TÍTULO I", "Capítulo II", "Párrafo 3º", "DISPOSICIONES TRANSITORIAS"])))
        lines.append(f"Artículo {n}º.- " + sentence(draw))
        for _ in range(draw(st.integers(0, 4))):
            lines += ["", draw(paragraph())]
        if draw(st.booleans()):
            lines.append("\t".join(draw(st.lists(st.sampled_from(WORDS), min_size=2, max_size=5))))
    text = "\n".join(lines)
    cut = sorted(draw(st.lists(st.integers(0, max(0, len(text))), max_size=3)))
    parts, prev = [], 0
    for c in cut + [len(text)]:
        parts.append(text[prev:c])
        prev = c
    return parts


@st.composite
def plain_doc(draw):
    return draw(st.lists(st.lists(paragraph(), min_size=0, max_size=6).map("\n\n".join), min_size=1, max_size=6))


@st.composite
def unique_doc(draw):
    """Every sentence carries a unique marker, so equal text in two chunks can only be deliberate overlap."""
    n = 0
    pages = []
    for _ in range(draw(st.integers(1, 4))):
        paras = []
        for _ in range(draw(st.integers(1, 5))):
            sents = []
            for _ in range(draw(st.integers(1, 40))):
                n += 1
                sents.append(f"Marca{n} " + draw(st.lists(st.sampled_from(WORDS), min_size=3, max_size=20).map(" ".join)) + ".")
            paras.append(" ".join(sents))
        pages.append("\n\n".join(paras))
    return pages


def words_of(pages):
    return set(" ".join(pages).split())


def body(c):
    return c["text"]


# ---------- examples ----------
def test_articles_start_new_chunks_and_carry_their_heading():
    out = chunk.split(["Artículo 1º.- Primera regla del servicio.\nArtículo 2º.- Segunda regla distinta."])
    assert [c["text"].splitlines()[0] for c in out] == ["Artículo 1º.-", "Artículo 2º.-"]
    assert "Primera regla" in out[0]["text"] and "Segunda" not in out[0]["text"]
    assert out[0]["heading"].endswith("Artículo 1º") and out[1]["heading"].endswith("Artículo 2º")


def test_a_law_number_after_the_word_articulo_is_not_a_header():
    assert not chunk.RE_ARTICULO.match("Artículo 21.719 del cuerpo legal")
    assert chunk.RE_ARTICULO.match("Artículo 21.- Texto")


def test_a_cross_reference_inside_a_sentence_does_not_open_an_article():
    out = chunk.split(["Artículo 1º.- Se aplica lo dispuesto en el artículo 173 del Código y en el Artículo 93 de la Constitución."])
    assert len(out) == 1


def test_section_lines_become_the_heading_context():
    out = chunk.split(["TÍTULO I\nCapítulo II\nArtículo 5º.- Texto de la regla."])
    assert out[0]["heading"].startswith("TÍTULO I") and "Capítulo II" in out[0]["heading"]
    assert "TÍTULO I" in out[0]["text"], "section words stay searchable"


def test_blank_pages_produce_no_chunks():
    assert chunk.split(["", "  \n\n "]) == []


def test_long_articles_split_and_repeat_the_header():
    text = "Artículo 7º.- " + " ".join(f"Oración número {i} con varias palabras de relleno." for i in range(200))
    out = chunk.split([text])
    assert len(out) > 1
    assert all(c["text"].startswith("Artículo 7º.-") for c in out)


def test_table_rows_stay_whole():
    rows = ["\t".join([f"celda{r}a", f"celda{r}b", f"celda{r}c"]) for r in range(400)]
    out = chunk.split(["\n".join(rows)])
    for r in rows:
        assert any(r in c["text"] for c in out)


def test_too_big_a_document_is_refused_not_truncated():
    pages = [f"Artículo {i}.- texto" for i in range(1, chunk.MAX_CHUNKS + 5)]
    with pytest.raises(chunk.TooManyChunks):
        chunk.split(pages)


def test_a_single_endless_token_is_hard_split():
    out = chunk.split(["x" * 50000])
    assert all(len(c["text"]) <= chunk.MAX_CHARS for c in out) and len(out) >= 3


def test_nul_characters_are_removed():
    assert chunk.clean("a\x00b\x00") == "ab"


def test_overlap_is_shared_only_inside_an_article():
    a = " ".join(f"Alfa{i} es una oración con contenido propio." for i in range(120))
    b = " ".join(f"Beta{i} es otra oración con contenido propio." for i in range(120))
    out = chunk.split([f"Artículo 1º.- {a}\nArtículo 2º.- {b}"])
    art1 = [c for c in out if c["text"].startswith("Artículo 1º")]
    art2 = [c for c in out if c["text"].startswith("Artículo 2º")]
    assert len(art1) >= 2 and len(art2) >= 2
    assert not any("Alfa" in c["text"] for c in art2) and not any("Beta" in c["text"] for c in art1)
    first_tail = art1[0]["text"].split()[-3:]
    assert " ".join(first_tail) in art1[1]["text"], "consecutive chunks of one article overlap"


# ---------- properties ----------
@SETTINGS
@given(st.one_of(legal_doc(), plain_doc()))
def test_coverage_every_word_survives_intact(pages):
    try:
        out = chunk.split(pages)
    except chunk.TooManyChunks:
        return
    got = words_of([c["text"] for c in out])
    assert words_of(pages) - got == set(), "numbers and words are never split"


@SETTINGS
@given(st.one_of(legal_doc(), plain_doc()))
def test_every_chunk_is_within_the_token_and_character_bounds(pages):
    try:
        out = chunk.split(pages)
    except chunk.TooManyChunks:
        return
    for c in out:
        assert chunk.tokens(c["text"]) <= chunk.MAX_TOKENS
        assert len(c["text"]) <= chunk.MAX_CHARS


@SETTINGS
@given(legal_doc())
def test_at_most_one_article_header_per_chunk(pages):
    try:
        out = chunk.split(pages)
    except chunk.TooManyChunks:
        return
    for c in out:
        assert sum(1 for line in c["text"].splitlines() if chunk.RE_ARTICULO.match(line)) <= 1


@SETTINGS
@given(st.one_of(legal_doc(), plain_doc()))
def test_ordinals_and_pages_are_consistent(pages):
    try:
        out = chunk.split(pages)
    except chunk.TooManyChunks:
        return
    assert [c["ord"] for c in out] == list(range(len(out)))
    assert all(1 <= c["page_no"] <= len(pages) for c in out)
    assert [c["page_no"] for c in out] == sorted(c["page_no"] for c in out)
    assert len(out) <= chunk.MAX_CHUNKS


@SETTINGS
@given(st.one_of(legal_doc(), plain_doc()))
def test_deterministic(pages):
    try:
        a = chunk.split(pages)
    except chunk.TooManyChunks:
        return
    assert a == chunk.split(list(pages))


@SETTINGS
@given(unique_doc())
def test_overlap_never_exceeds_its_budget(pages):
    try:
        out = chunk.split(pages)
    except chunk.TooManyChunks:
        return
    for prev, nxt in zip(out, out[1:]):
        pw, nw = prev["text"].split(), nxt["text"].split()
        k = 0
        for n in range(1, min(len(pw), len(nw)) + 1):
            if pw[-n:] == nw[:n]:
                k = n
        assert chunk.tokens(" ".join(pw[-k:]) if k else "") <= 54, "15 % of the 350-token target, in the spec's own numbers"


@SETTINGS
@given(legal_doc())
def test_table_rows_are_not_split(pages):
    rows = [ln for ln in "\n".join(pages).split("\n") if "\t" in ln]
    try:
        out = chunk.split(pages)
    except chunk.TooManyChunks:
        return
    for r in rows:
        assert any(r in c["text"] for c in out), r


# ---------- real laws (local only: needs /root/aps-conecta/Databases/Legal mounted at BD_LEGAL_DIR) ----------
LEGAL = os.environ.get("BD_LEGAL_DIR")


@pytest.mark.skipif(not LEGAL or not os.path.isdir(LEGAL), reason="law PDFs are a local fixture, not part of the repository")
def test_real_laws_chunk_within_bounds_and_cover_their_articles():
    import extract
    n = 0
    for name in sorted(os.listdir(LEGAL)):
        if not name.endswith(".pdf"):
            continue
        pages = extract.extract(os.path.join(LEGAL, name))
        out = chunk.split(pages)
        assert out and all(chunk.tokens(c["text"]) <= chunk.MAX_TOKENS for c in out), name
        headers = [c["text"].splitlines()[0] for c in out if chunk.RE_ARTICULO.match(c["text"].splitlines()[0])]
        assert len(set(headers)) >= 10, f"{name}: only {len(set(headers))} distinct article headers"
        n += 1
    assert n >= 1


# ---------- S2 review fixes ----------
def test_page_numbers_follow_the_page_even_without_blank_lines():
    pages = [f"Línea uno de la Pagina{n}\nLínea dos de la Pagina{n}\nLínea tres" for n in range(1, 21)]
    out = chunk.split(pages)
    for c in out:
        for n in range(1, 21):
            if re.search(rf"Pagina{n}\b", c["text"]):
                assert c["page_no"] == n, (n, c["page_no"])
    assert {c["page_no"] for c in out} == set(range(1, 21))


def test_a_label_only_article_keeps_its_own_page():
    out = chunk.split(["Texto previo sin artículo.", "Artículo 5.-"])
    assert out[-1]["text"].startswith("Artículo 5") and out[-1]["page_no"] == 2


def test_sections_replace_their_own_level_instead_of_piling_up():
    out = chunk.split(["TÍTULO I\nCapítulo 1\nArtículo 1.- uno.\nCapítulo 2\nArtículo 2.- dos."])
    heads = {c["text"].splitlines()[0][:10]: c["heading"] for c in out}
    assert heads["Artículo 1"] == "TÍTULO I › Capítulo 1 › Artículo 1"
    assert heads["Artículo 2"] == "TÍTULO I › Capítulo 2 › Artículo 2"


def test_a_new_titulo_clears_the_chapter_below_it():
    out = chunk.split(["TÍTULO I\nCapítulo 1\nArtículo 1.- uno.\nTÍTULO II\nArtículo 2.- dos."])
    assert [c["heading"] for c in out if c["text"].startswith("Artículo 2")] == ["TÍTULO II › Artículo 2"]


@pytest.mark.parametrize("line", ["Párrafo segundo del artículo 5 dice que los datos se tratan.",
                                  "Capítulo anterior al presente es aplicable en todo caso.",
                                  "Capítulo 3 del presente reglamento establece que los datos personales deben tratarse con cuidado."])
def test_wrapped_body_lines_are_not_headings(line):
    out = chunk.split([f"Artículo 1.- Texto inicial.\n{line}"])
    assert len(out) == 1 and line.split()[0] in out[0]["text"] and "›" not in (out[0]["heading"] or "").replace("Artículo 1", "")


def test_a_huge_single_table_cell_is_split_not_refused():
    row = "palabra " * 3000 + "\t" + "b"
    out = chunk.split([row])
    assert out and all(chunk.tokens(c["text"]) <= chunk.MAX_TOKENS for c in out)
    assert words_of([row]) - words_of([c["text"] for c in out]) == set()


def test_the_chunk_cap_is_enforced_while_chunking_not_after_the_whole_unit(monkeypatch):
    monkeypatch.setattr(chunk, "MAX_CHUNKS", 5)
    calls = []
    real = chunk._render
    monkeypatch.setattr(chunk, "_render", lambda *a, **k: (calls.append(1), real(*a, **k))[1])
    text = " ".join(f"Oración{i} con contenido suficiente para llenar." for i in range(4000))
    with pytest.raises(chunk.TooManyChunks):
        chunk.split([text])
    assert len(calls) <= 7, "stopped right after crossing the cap"

# SPDX-License-Identifier: AGPL-3.0-or-later
import json
import pathlib

import pytest

import build_glossary as bg

FIX = pathlib.Path(__file__).parent / "fixture.md"


@pytest.fixture(scope="module")
def built():
    return bg.build(FIX.read_text(encoding="utf-8"))


def entry(built, key):
    return [e for e in built["entries"] if e["key"] == key]


def test_fold_lowercases_and_removes_accents_like_the_index():
    assert bg.fold("Estadístico ÑANDÚ Atención") == "estadistico nandu atencion"
    assert bg.fold("  CIE-10 ") == "cie 10"


def test_an_acronym_gets_the_name_before_the_parenthesis_or_the_full_stop(built):
    assert entry(built, "rem")[0]["expansion"] == "resumen estadistico mensual"
    assert entry(built, "aps")[0]["expansion"] == "atencion primaria de salud"
    assert entry(built, "cie 10")[0]["expansion"] == "clasificacion internacional de enfermedades 10 version"


def test_latex_and_notes_never_reach_the_expansion(built):
    for e in built["entries"]:
        assert "$" not in e["expansion"] and "text" not in e["expansion"].split() and "(" not in e["expansion"]


def test_slash_keys_make_one_entry_per_alternative_and_phrases_are_not_acronyms(built):
    assert entry(built, "bcc") == []                                       # its "expansion" is a definition, not a name
    assert entry(built, "bloqueador de canales de calcio") == []         # a term with a definition, not an acronym
    assert [e["key"] for e in built["entries"] if e["key"] in ("mf", "mfc")] == ["mf", "mfc"]


def test_only_names_expand_not_definitions(built):
    assert bg.is_name("Hemoglobina Glicosilada") and bg.is_name("Infarto Agudo al Miocardio") and bg.is_name("Tuberculosis")
    assert bg.is_name("Clasificación Internacional de Enfermedades, 10ª versión")
    assert not bg.is_name("Anticoagulante oral antagonista de la vitamina K de 4 mg")
    assert not bg.is_name("Cierre administrativo y clínico de un episodio de atención")
    assert not bg.is_name("")


def test_a_note_in_the_key_is_dropped(built):
    assert {e["expansion"] for e in entry(built, "cgr")} == {"contraloria general de la republica", "consultorio general rural"}


def test_keys_that_repeat_or_are_too_short_or_denied_are_ambiguous(built):
    for k in ("ss", "cgr", "pa", "mf"):
        assert all(e["ambiguous"] for e in entry(built, k)), k
    assert all(e["ambiguous"] for e in entry(built, "alta")), "a common word on the deny list"
    for k in ("rem", "aps", "cesfam", "hba1c", "tens", "gaa", "empa"):
        assert not entry(built, k)[0]["ambiguous"], k


def test_long_definitions_and_non_acronym_headings_are_left_out(built):
    keys = {e["key"] for e in built["entries"]}
    assert "estratificacion de riesgo" not in keys and "le no ges" not in keys
    assert "nandu" in keys                                                  # accented capitals are acronym-shaped; the deny list is the safety valve


def test_the_output_is_stable_and_versioned(built):
    again = bg.build(FIX.read_text(encoding="utf-8"))
    assert json.dumps(built, sort_keys=True) == json.dumps(again, sort_keys=True)
    assert len(built["version"]) == 12
    assert built["entries"] == sorted(built["entries"], key=lambda e: (e["key"], e["expansion"]))


def test_a_missing_entry_count_fails_the_check():
    text = FIX.read_text(encoding="utf-8")
    assert bg.check(bg.build(text), minimum=5) is None
    with pytest.raises(SystemExit):
        bg.check(bg.build(text), minimum=500)

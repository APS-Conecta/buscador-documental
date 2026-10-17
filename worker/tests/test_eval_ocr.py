# SPDX-License-Identifier: AGPL-3.0-or-later
import pytest

import eval_ocr as ev


def test_normalisation_is_nfc_and_whitespace_insensitive():
    assert ev.norm("  Nutrición\n\n y\tcontrol ") == "Nutrición y control"
    assert ev.norm("Nutrición") == "Nutrición"


def test_identical_text_has_zero_error():
    assert ev.char_edits("hola mundo", "hola mundo") == (0, 10)
    assert ev.word_edits("hola mundo", "hola mundo") == (0, 2)


def test_edits_count_levenshtein_over_chars_and_words():
    assert ev.char_edits("hola", "hoja") == (1, 4)
    assert ev.word_edits("la paciente fue derivada", "la paciente fue derivado") == (1, 4)
    assert ev.char_edits("", "abc") == (3, 0) or ev.char_edits("", "abc")[0] == 3


def test_pooled_rates_weight_by_reference_length_not_by_page():
    rows = [{"kind": "a", "c_edits": 0, "c_ref": 1000, "w_edits": 0, "w_ref": 200},
            {"kind": "a", "c_edits": 10, "c_ref": 10, "w_edits": 2, "w_ref": 2}]
    s = ev.summarize(rows)
    assert s["all"]["cer"] == pytest.approx(10 / 1010) and s["all"]["wer"] == pytest.approx(2 / 202)


def test_catastrophic_pages_are_counted_apart():
    rows = [{"kind": "k", "c_edits": 90, "c_ref": 100, "w_edits": 9, "w_ref": 10},
            {"kind": "k", "c_edits": 1, "c_ref": 100, "w_edits": 0, "w_ref": 10}]
    assert ev.summarize(rows)["k"]["catastrophic"] == 1


def test_gate_passes_only_when_both_rates_are_within_the_criterion():
    ok = {"all": {"cer": 0.019, "wer": 0.049, "catastrophic": 0, "pages": 10}}
    assert ev.gate(ok)["pass"] is True
    assert ev.gate({"all": {"cer": 0.021, "wer": 0.01, "catastrophic": 0, "pages": 10}})["pass"] is False
    assert ev.gate({"all": {"cer": 0.01, "wer": 0.051, "catastrophic": 0, "pages": 10}})["pass"] is False


def test_gate_refuses_to_pass_on_an_empty_evaluation():
    assert ev.gate({"all": {"cer": 0.0, "wer": 0.0, "catastrophic": 0, "pages": 0}})["pass"] is False

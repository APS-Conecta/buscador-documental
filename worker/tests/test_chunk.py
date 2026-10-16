# SPDX-License-Identifier: AGPL-3.0-or-later
import chunk


def test_splits_on_blank_lines_and_numbers_chunks_over_the_document():
    pages = ["uno\n\ndos\n\ntres", "cuatro"]
    out = chunk.split(pages, target=5)
    assert [c["text"] for c in out] == ["uno", "dos", "tres", "cuatro"]
    assert [c["ord"] for c in out] == [0, 1, 2, 3]
    assert [c["page_no"] for c in out] == [1, 1, 1, 2]
    assert all(c["heading"] is None for c in out)


def test_packs_small_paragraphs_up_to_target():
    out = chunk.split(["a\n\nb\n\nc"], target=1000)
    assert len(out) == 1 and out[0]["text"] == "a\n\nb\n\nc"


def test_blank_pages_produce_no_chunks():
    assert chunk.split(["", "  \n\n "]) == []


def test_never_exceeds_the_app_caps():
    pages = ["\n\n".join(f"p{i}-{j}" for j in range(30)) for i in range(100)]   # 3000 tiny paragraphs
    out = chunk.split(pages, target=1)
    assert len(out) <= chunk.MAX_CHUNKS
    huge = chunk.split(["x" * 50000])
    assert all(len(c["text"]) <= chunk.MAX_CHARS for c in huge) and len(huge) == 3

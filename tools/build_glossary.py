#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Builds app/lib/Search/glossary.json from the supplied APS dictionary (buscador/data/aps-glosario-chile.md).

Only acronym-shaped keys become entries (a heading such as "ESTRATIFICACIÓN DE RIESGO" is a term with a definition, not
something to expand). Keys that repeat, that are shorter than three characters or that are common words are marked
ambiguous: the search never expands them. Usage: build_glossary.py SRC.md OUT.json [--min N]"""
import hashlib
import json
import re
import sys
import unicodedata

LINE = re.compile(r"^\s*\d+\.\s+\*\*(.+?)\*\*:\s*(.+?)\s*$")
ACRONYM = re.compile(r"^[A-Za-zÁÉÍÓÚÑÜáéíóúñü0-9.\-]{2,12}$")
LATEX = re.compile(r"\$[^$]*\$")
# Words that are acronym-shaped in the dictionary's capitals but are ordinary Spanish: never expanded.
DENY = {"alta", "fuga", "despacho", "some", "box", "sos", "peso", "talla", "rescate", "reposo", "duelo", "agonia", "ventana",
        "ecomapa", "cirugia", "ficha", "alergia", "asma", "hanta", "chagas", "tanner", "adams", "snellen", "signos",
        "alerg", "habitos", "neuro", "abd", "mama", "bien", "regular", "normal",
        # short keys that are also everyday Spanish or English words once the case is folded away
        "ave", "cat", "act", "caps", "comp", "top", "audit", "sur", "par", "med", "mat", "nut", "obs", "ord", "aux", "cup",
        "dots", "tag", "reg", "peg", "mapa", "fem", "tec", "tar", "sic", "nus", "ape", "mac", "run", "tea", "pas", "cad"}
MAX_WORDS = 10
STOP = {"de", "del", "la", "las", "el", "los", "y", "e", "en", "a", "al", "para", "por", "con", "o", "u", "sin", "un", "una"}
NAME_RATIO = 0.75


def is_name(text):
    """An expansion is a proper name (Title Case: "Infarto Agudo al Miocardio"), not a definition ("Antihipertensivo vasodilatador")."""
    words = [w for w in re.findall(r"[A-Za-zÁÉÍÓÚÑÜáéíóúñü]+", text) if w.lower() not in STOP]
    if not words:
        return False
    return sum(w[0].isupper() for w in words) / len(words) >= NAME_RATIO


def fold(text):
    text = unicodedata.normalize("NFD", text.lower())
    text = "".join(c for c in text if unicodedata.category(c) != "Mn")
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9]+", " ", text)).strip()


def _expansion(value):
    value = LATEX.sub(" ", value)
    cut = re.split(r"\s\(|:\s|\.\s|\.$|\sEj\.", value, maxsplit=1)[0]
    cut = cut.strip(" ,;.")
    return cut


def build(markdown):
    raw = []
    for line in markdown.splitlines():
        m = LINE.match(line)
        if not m:
            continue
        keys = [re.sub(r"\s*\(.*?\)\s*$", "", k).strip() for k in m.group(1).split(" / ")]
        values = [v.strip() for v in _expansion(m.group(2)).split(" / ")]
        for i, key in enumerate(keys):
            if not ACRONYM.match(key) or sum(c.isupper() for c in key) < 2:
                continue
            value = values[i] if len(values) == len(keys) else values[0]
            if not is_name(value):
                continue
            exp = fold(value)
            k = fold(key)
            if not k or not exp or len(exp.split()) > MAX_WORDS or k == exp:
                continue
            raw.append((k, exp))
    counts = {}
    for k, _ in raw:
        counts[k] = counts.get(k, 0) + 1
    entries = sorted({(k, e) for k, e in raw})
    out = [{"key": k, "expansion": e, "ambiguous": counts[k] > 1 or len(k) < 3 or k in DENY} for k, e in entries]
    version = hashlib.sha256(json.dumps(out, sort_keys=True, ensure_ascii=False).encode()).hexdigest()[:12]
    return {"version": version, "entries": out}


def check(built, minimum):
    if len(built["entries"]) < minimum:
        sys.exit(f"glossary has {len(built['entries'])} entries, expected at least {minimum}")


def main(argv):
    if len(argv) < 3:
        sys.exit(__doc__)
    minimum = int(argv[argv.index("--min") + 1]) if "--min" in argv else 1
    built = build(open(argv[1], encoding="utf-8").read())
    check(built, minimum)
    with open(argv[2], "w", encoding="utf-8") as fh:
        json.dump(built, fh, ensure_ascii=False, indent=1, sort_keys=True)
        fh.write("\n")
    usable = sum(1 for e in built["entries"] if not e["ambiguous"])
    print(f"glossary {built['version']}: {len(built['entries'])} entries, {usable} usable")


if __name__ == "__main__":
    main(sys.argv)

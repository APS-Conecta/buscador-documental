# SPDX-License-Identifier: AGPL-3.0-or-later
"""Builds the S4 evaluation corpus and gold set from topics.py: docs.json (one chunk per document) and gold.json.
Deterministic. 27 topics x 4 documents + filler documents about nothing the queries ask for."""
import json
import random
import sys

from topics import TOPICS

FILLER = [
    "Se recuerda al personal que el estacionamiento interno está reservado para vehículos autorizados y que las bicicletas deben dejarse en el lugar señalado junto a la entrada lateral.",
    "El casino de funcionarios atenderá este mes en horario reducido por mantención de la cocina; se ruega retirar las bandejas antes de las dos de la tarde.",
    "Acta de la reunión de coordinación: se acordó cambiar el color de las señaléticas del segundo piso y solicitar cotizaciones a tres proveedores locales.",
    "Se informa que la sala de reuniones número dos estará ocupada durante la semana por trabajos de pintura y no podrá reservarse hasta nuevo aviso.",
    "Circular interna sobre el uso de las impresoras compartidas: se debe imprimir por ambos lados y retirar las hojas de inmediato para cuidar la confidencialidad.",
    "Recordatorio del cierre de las instalaciones por feriado: la portería quedará a cargo de un solo guardia y se suspenden las visitas de proveedores.",
    "El comité de aseo solicita no dejar objetos personales en los pasillos y mantener despejadas las salidas de emergencia durante todo el año.",
    "Instrucciones para solicitar permiso administrativo: se debe completar el formulario con tres días de anticipación y contar con la firma de la jefatura directa.",
    "Se invita a todo el personal a la celebración de aniversario institucional que se realizará en el patio central con una once compartida.",
    "Reposición de mobiliario: las sillas dañadas serán retiradas por el equipo de mantención y reemplazadas por modelos ergonómicos durante el trimestre.",
    "Aviso sobre el corte programado de agua potable en el sector de bodega para reparar una matriz; se dispondrá de bidones en cada piso.",
    "La actualización del sistema de climatización se hará por etapas, comenzando por el ala norte; se solicita colaboración para el traslado temporal de escritorios.",
]


def build(seed=7, fillers=60):
    rnd = random.Random(seed)
    docs = []
    for ti, t in enumerate(TOPICS):
        for vi, text in enumerate(t["docs"]):
            docs.append({"id": f"{t['id']}-{vi}", "topic": t["id"], "group": ti, "kind": ["full", "acronym", "para1", "para2"][vi], "text": text})
    for i in range(fillers):
        a, b = rnd.sample(FILLER, 2)
        docs.append({"id": f"filler-{i}", "topic": None, "group": -1, "kind": "filler", "text": a + " " + b})
    gold = []
    for ti, t in enumerate(TOPICS):
        relevant = [f"{t['id']}-{v}" for v in range(len(t["docs"]))]
        for kind, q in t["queries"].items():
            gold.append({"id": f"{t['id']}:{kind}", "topic": t["id"], "group": ti, "kind": kind, "query": q, "relevant": relevant})
    return docs, gold


if __name__ == "__main__":
    out = sys.argv[1]
    docs, gold = build()
    json.dump(docs, open(f"{out}/docs.json", "w"), ensure_ascii=False)
    json.dump(gold, open(f"{out}/gold.json", "w"), ensure_ascii=False)
    print(f"{len(docs)} documents, {len(gold)} queries")

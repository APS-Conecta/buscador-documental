"""200 text queries (1-3 content words, drawn over the Zipf rank range: head, middle and tail terms) and 200 query vectors
from the same clusters as the data. Writes /s7/queries.json."""
import json
import sys

import numpy as np

sys.path.insert(0, "/s0/corpus")
sys.path.insert(0, "/s7")
from vec import centres, query_vectors  # noqa: E402
from zipf import TextModel  # noqa: E402

SEED = 1
m = TextModel(SEED)
rng = np.random.default_rng([SEED, 31])
queries = []
for i in range(200):
    n_words = 1 + i % 3
    # a third of the words come from the 100 most common content words (the hard case), the rest from the middle and the tail
    ranks = [int(rng.integers(0, 100)) if rng.random() < 0.34 else int(rng.integers(100, len(m.vocab))) for _ in range(n_words)]
    queries.append(" ".join(m.vocab[r] for r in ranks))
vecs = query_vectors(SEED, 200, centres(SEED))
json.dump({"queries": [{"q": q, "vec": [round(float(x), 5) for x in v]} for q, v in zip(queries, vecs)]}, open("/s7/queries.json", "w"))
print(len(queries), "queries;", sum(len(q.split()) == 1 for q in queries), "single-word")

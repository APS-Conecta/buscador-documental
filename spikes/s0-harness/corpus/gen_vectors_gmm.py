"""Clustered synthetic vectors for the S0 filtered-recall check at 3M (random vectors have no neighbour structure, so recall on
them means nothing). 2000 unit-vector cluster centres; every file draws 3 clusters and each of its chunks is
normalize(centre + noise), noise norm ~0.6 of the centre (chunk-to-centre cosine ~0.86, same-cluster pairs ~0.73, other clusters
~0). Files are assigned to folders independently of clusters, so a narrow user's readable chunks are a thin slice of every topic.
Queries are fresh draws from the same mixture. Real e5 embeddings are anisotropic and messier: indicative only."""
import numpy as np

from gen_vectors import BATCH, CHUNKS_PER_FILE, DIM

K_CLUSTERS, PER_FILE, NOISE = 2000, 3, 0.6


def centres(seed: int) -> np.ndarray:
    c = np.random.default_rng([seed, 11]).standard_normal((K_CLUSTERS, DIM)).astype(np.float32)
    return c / np.linalg.norm(c, axis=1, keepdims=True)


def file_clusters(seed: int, n_files: int) -> np.ndarray:
    return np.random.default_rng([seed, 12]).integers(0, K_CLUSTERS, (n_files, PER_FILE))


def draw(rng, ctr: np.ndarray, cl: np.ndarray) -> np.ndarray:
    v = ctr[cl] + (NOISE / np.sqrt(DIM)) * rng.standard_normal((len(cl), DIM)).astype(np.float32)
    return v / np.linalg.norm(v, axis=1, keepdims=True)


def batch_vectors(seed: int, batch_idx: int, n_chunks: int, ctr, fcl):
    first = batch_idx * BATCH
    n = min(BATCH, n_chunks - first)
    rng = np.random.default_rng([seed, 13, batch_idx])
    ids = np.arange(first + 1, first + n + 1, dtype=np.int64)
    fid = (ids - 1) // CHUNKS_PER_FILE
    cl = fcl[fid, rng.integers(0, PER_FILE, n)]
    return ids, fid + 1, draw(rng, ctr, cl)


def query_vectors(seed: int, n: int, ctr) -> np.ndarray:
    rng = np.random.default_rng([seed, 14])
    return draw(rng, ctr, rng.integers(0, K_CLUSTERS, n))

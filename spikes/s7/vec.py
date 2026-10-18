"""Clustered synthetic vectors (dimension of the bge-m3 profile) shared by the loader and the query generator.
Files belong to a topic cluster; a chunk's vector is its cluster centre plus noise, normalised. Query vectors are drawn the same
way, so nearest neighbours have structure and recall means something (random vectors would make every graph look bad)."""
import numpy as np

DIM = 1024
N_CENTRES = 2000
# Same folder/share model as the S0 corpus (spikes/s0-harness/corpus/gen_corpus.py): 100 team folders, two groups each,
# every 64th file in folder 1 (so the "narrow" user reads about 1.6 % of the index).
N_FOLDERS = 100
NARROW_EVERY = 64


def principals_for(folder: int) -> list[str]:
    return sorted([f"g:g{2 * folder - 1:03d}", f"g:g{2 * folder:03d}"])


def centres(seed: int) -> np.ndarray:
    return np.random.default_rng([seed, 11]).standard_normal((N_CENTRES, DIM), dtype=np.float32)


def file_cluster(seed: int, n_files: int) -> np.ndarray:
    return np.random.default_rng([seed, 12]).integers(0, N_CENTRES, n_files)


def vectors(seed: int, batch: int, centre_ids: np.ndarray, ctr: np.ndarray, noise: float = 0.9) -> np.ndarray:
    rng = np.random.default_rng([seed, 13, batch])
    v = ctr[centre_ids] + noise * rng.standard_normal((len(centre_ids), DIM), dtype=np.float32)
    v /= np.linalg.norm(v, axis=1, keepdims=True)
    return v


def query_vectors(seed: int, n: int, ctr: np.ndarray) -> np.ndarray:
    rng = np.random.default_rng([seed, 14])
    return vectors(seed, 999_999, rng.integers(0, N_CENTRES, n), ctr, 0.9)

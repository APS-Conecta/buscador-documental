"""multilingual-e5-small (384-d) ONNX embedder for the S0 spike ('small-cpu' profile class).
Model files are downloaded once into /work/models (git-ignored) and their sha256 is recorded in the result files."""
import hashlib
import os
import urllib.request

import numpy as np
import onnxruntime as ort
from tokenizers import Tokenizer

MODEL_DIR = "/work/models"
BASE = "https://huggingface.co/Xenova/multilingual-e5-small/resolve/main"
FILES = {"model": ("onnx/model_int8.onnx", "model_int8.onnx"), "tok": ("tokenizer.json", "tokenizer.json")}


def fetch():
    os.makedirs(MODEL_DIR, exist_ok=True)
    paths = {}
    for key, (remote, local) in FILES.items():
        p = f"{MODEL_DIR}/{local}"
        if not os.path.exists(p):
            urllib.request.urlretrieve(f"{BASE}/{remote}", p)
        paths[key] = p
    return paths


def sha256(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for b in iter(lambda: fh.read(1 << 20), b""):
            h.update(b)
    return h.hexdigest()


class Embedder:
    def __init__(self, threads: int = 8, max_len: int = 512):
        paths = fetch()
        self.paths = paths
        opts = ort.SessionOptions()
        opts.intra_op_num_threads = threads
        self.sess = ort.InferenceSession(paths["model"], opts, providers=["CPUExecutionProvider"])
        self.inputs = {i.name for i in self.sess.get_inputs()}
        self.tok = Tokenizer.from_file(paths["tok"])
        self.tok.enable_truncation(max_len)
        self.tok.enable_padding(pad_id=1, pad_token="<pad>")      # XLM-R pad id

    def embed(self, texts: list[str], prefix: str, batch: int = 32):
        """Returns (unit-normalised float32 [n,384], token_count)."""
        out, tokens = [], 0
        for i in range(0, len(texts), batch):
            enc = self.tok.encode_batch([prefix + t for t in texts[i:i + batch]])
            ids = np.array([e.ids for e in enc], dtype=np.int64)
            mask = np.array([e.attention_mask for e in enc], dtype=np.int64)
            tokens += int(mask.sum())
            feed = {"input_ids": ids, "attention_mask": mask}
            if "token_type_ids" in self.inputs:
                feed["token_type_ids"] = np.zeros_like(ids)
            h = self.sess.run(None, feed)[0]
            pooled = (h * mask[..., None]).sum(1) / mask.sum(1, keepdims=True)
            out.append((pooled / np.linalg.norm(pooled, axis=1, keepdims=True)).astype(np.float32))
        return np.vstack(out), tokens

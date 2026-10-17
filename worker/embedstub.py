# SPDX-License-Identifier: AGPL-3.0-or-later
"""A deterministic stand-in for the Ollama endpoint (CI and tests only; never a model).

Embeddings are hashed bags of words, so texts that share words are close: enough to exercise the plumbing, useless for
judging quality. It speaks the three calls the product uses: POST /api/embed, GET /api/tags, POST /api/show. It keeps
counters (never text) at GET /_stats, and can be told to fail with POST /_fail {"status": 503, "times": 2}."""
import hashlib
import json
import math
import os
import re
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

MODEL = os.environ.get("BD_STUB_MODEL", "bge-m3:567m")
DIGEST = os.environ.get("BD_STUB_DIGEST", "sha256:stub0000000000000000000000000000000000000000000000000000000000")
DIM = int(os.environ.get("BD_STUB_DIM", "64"))
WORD = re.compile(r"\w+", re.UNICODE)


def embed_text(text, dim=DIM):
    v = [0.0] * dim
    for w in WORD.findall(text.lower()):
        h = hashlib.sha256(w.encode()).digest()
        v[int.from_bytes(h[:4], "big") % dim] += 1.0 if h[4] & 1 else -1.0
    n = math.sqrt(sum(x * x for x in v))
    if n == 0:                      # words that cancel out (or no words): still a unit vector
        v[0], n = 1.0, 1.0
    return [x / n for x in v]


class Stub:
    def __init__(self, dim=DIM, model=MODEL, digest=DIGEST):
        self.dim, self.model, self.digest = dim, model, digest
        self.stats = {"embed_calls": 0, "inputs": 0, "tags_calls": 0}
        self.fail = {"status": 0, "times": 0}
        self.max_words = 100000
        self.lock = threading.Lock()

    def handler(self):
        stub = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):          # no access log: request bodies are document text
                pass

            def _send(self, status, body):
                data = json.dumps(body).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)

            def _body(self):
                n = int(self.headers.get("Content-Length") or 0)
                return json.loads(self.rfile.read(n) or b"{}")

            def do_GET(self):
                if self.path == "/api/tags":
                    with stub.lock:
                        stub.stats["tags_calls"] += 1
                    return self._send(200, {"models": [{"name": stub.model, "model": stub.model, "digest": stub.digest, "size": 1,
                                                        "details": {"quantization_level": "F16"}}]})
                if self.path == "/_stats":
                    return self._send(200, stub.stats)
                self._send(404, {"error": "not found"})

            def do_POST(self):
                body = self._body()
                if self.path == "/_fail":
                    stub.fail = {"status": int(body.get("status", 503)), "times": int(body.get("times", 1))}
                    return self._send(200, {})
                if self.path == "/api/show":
                    return self._send(200, {"model_info": {"general.architecture": "bert"}, "details": {"quantization_level": "F16"}})
                if self.path != "/api/embed":
                    return self._send(404, {"error": "not found"})
                with stub.lock:
                    if stub.fail["times"] > 0:
                        stub.fail["times"] -= 1
                        return self._send(stub.fail["status"], {"error": "stub failure"})
                if body.get("model") != stub.model:
                    return self._send(404, {"error": f"model \"{body.get('model')}\" not found, try pulling it first"})
                inputs = body.get("input")
                inputs = [inputs] if isinstance(inputs, str) else inputs
                if not isinstance(inputs, list) or not all(isinstance(x, str) for x in inputs):
                    return self._send(400, {"error": "bad input"})
                if body.get("truncate") is False and any(len(WORD.findall(x)) > stub.max_words for x in inputs):
                    return self._send(400, {"error": "the input length exceeds the context length"})
                with stub.lock:
                    stub.stats["embed_calls"] += 1
                    stub.stats["inputs"] += len(inputs)
                self._send(200, {"model": stub.model, "embeddings": [embed_text(x, stub.dim) for x in inputs]})

        return H

    def serve(self, host="127.0.0.1", port=0):
        srv = ThreadingHTTPServer((host, port), self.handler())
        threading.Thread(target=srv.serve_forever, daemon=True).start()
        return srv


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 11434
    srv = Stub().serve("0.0.0.0", port)
    threading.Event().wait()

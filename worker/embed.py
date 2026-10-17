# SPDX-License-Identifier: AGPL-3.0-or-later
"""Client for the admin-configured Ollama endpoint (ADR-0014). Errors carry a class and a retryable flag, never the
endpoint address, the token or any text that was sent: those can be a secret or document content (A-SEC-5)."""
import math
import time

import requests


class EmbedError(Exception):
    def __init__(self, message, retryable):
        super().__init__(message)
        self.retryable = retryable


class Embedder:
    def __init__(self, base_url, token=None, timeout=60, batch=16, retries=3, backoff=1.0):
        self.base = base_url.rstrip("/")
        self.timeout, self.batch, self.retries, self.backoff = timeout, batch, max(1, retries), backoff
        self.session = requests.Session()
        self.session.trust_env = False                 # an HTTP_PROXY in the environment must not redirect document text
        if token:
            self.session.headers["Authorization"] = f"Bearer {token}"

    def digest(self, model):
        """The digest the endpoint reports for the model tag, or None when it does not list it."""
        for m in self._get("/api/tags").get("models", []):
            if isinstance(m, dict) and model in (m.get("name"), m.get("model")):
                return m.get("digest")
        return None

    def embed(self, model, texts, num_ctx):
        """One unit vector per text, in order. Never truncates: an input that does not fit is an error."""
        out = []
        for i in range(0, len(texts), self.batch):
            part = texts[i:i + self.batch]
            body = {"model": model, "input": part, "truncate": False, "keep_alive": -1, "options": {"num_ctx": num_ctx}}
            vectors = self._post("/api/embed", body).get("embeddings")
            if not isinstance(vectors, list) or len(vectors) != len(part):
                raise EmbedError("answer shape", False)
            out.extend(self._unit(v) for v in vectors)
        return out

    @staticmethod
    def _unit(v):
        if not isinstance(v, list) or not v or not all(isinstance(x, (int, float)) and not isinstance(x, bool) and math.isfinite(x) for x in v):
            raise EmbedError("bad vector", False)
        n = math.sqrt(sum(x * x for x in v))
        if n < 1e-9:
            raise EmbedError("zero vector", False)
        return [x / n for x in v]

    def _get(self, path):
        return self._request(lambda: self.session.get(self.base + path, timeout=self.timeout))

    def _post(self, path, body):
        return self._request(lambda: self.session.post(self.base + path, json=body, timeout=self.timeout))

    def _request(self, send):
        last = None
        for attempt in range(self.retries):
            if attempt:
                time.sleep(self.backoff * 2 ** (attempt - 1))
            try:
                r = send()
            except requests.RequestException:
                last = EmbedError("endpoint unreachable", True)
                continue
            if r.status_code == 200:
                try:
                    data = r.json()
                except ValueError:
                    raise EmbedError("answer is not json", False) from None
                if not isinstance(data, dict):
                    raise EmbedError("answer shape", False)
                return data
            if r.status_code in (429, 500, 502, 503, 504):
                last = EmbedError(f"endpoint busy ({r.status_code})", True)
                continue
            raise EmbedError(f"endpoint refused ({r.status_code})", False)          # 400 overflow, 404 model not pulled, 401 ...
        raise last

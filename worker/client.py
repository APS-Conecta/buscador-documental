# SPDX-License-Identifier: AGPL-3.0-or-later
"""HTTP client for the app's worker channel v1 (openapi/worker-v1.yaml)."""
import hashlib
import hmac
import secrets
import time
from urllib.parse import urlparse

import requests

PREFIX = "/apps/buscador_documental/worker/v1"


class AuthError(Exception):
    """401: bad secret, clock skew or replay."""


class ClaimLost(Exception):
    """403/404: the claim is gone (lease expired, file excluded, file removed)."""


class EtagChanged(Exception):
    """412: the file changed after the claim."""


class Retryable(Exception):
    """429/503/5xx: try again later; the job stays claimed until the lease runs out."""


def signed_string(method, path, query, rng, if_match, worker_id, body, ts, nonce, job_id, epoch) -> str:
    return "\n".join([method.upper(), path, query, rng, if_match, worker_id, hashlib.sha256(body).hexdigest(),
                      str(ts), nonce, str(job_id), str(epoch)])


def sign(secret: str, s: str) -> str:
    return hmac.new(secret.encode(), s.encode(), hashlib.sha256).hexdigest()


class WorkerClient:
    def __init__(self, base_url, worker_id, secret, clock=time.time, nonce_fn=None, session=None, timeout=60):
        self.base = base_url.rstrip("/")
        self.worker_id = worker_id
        self.secret = secret
        self.clock = clock
        self.nonce_fn = nonce_fn or (lambda: secrets.token_urlsafe(24))
        self.session = session or requests.Session()
        self.timeout = timeout

    def headers(self, method, path, job_id, epoch, query="", rng="", if_match="") -> dict:
        ts = int(self.clock())
        nonce = self.nonce_fn()
        mac = sign(self.secret, signed_string(method, path, query, rng, if_match, self.worker_id, b"", ts, nonce, job_id, epoch))
        h = {"Authorization": f"Bearer {self.worker_id}.{mac}", "X-BD-Timestamp": str(ts), "X-BD-Nonce": nonce,
             "X-BD-Job-Id": str(job_id), "X-BD-Lease-Epoch": str(epoch)}
        if rng:
            h["Range"] = rng
        if if_match:
            h["If-Match"] = if_match
        return h

    def _check(self, r):
        if 300 <= r.status_code < 400:
            raise ValueError(f"unexpected redirect {r.status_code}")      # never followed: the headers carry the signature
        if r.status_code == 401:
            raise AuthError()
        if r.status_code in (403, 404):
            raise ClaimLost()
        if r.status_code == 412:
            raise EtagChanged()
        if r.status_code in (428, 416):
            raise ValueError(f"request rejected with {r.status_code}")
        if r.status_code in (429, 503) or r.status_code >= 500:
            raise Retryable(str(r.status_code))
        if r.status_code >= 400:
            raise ValueError(f"request rejected with {r.status_code}")

    def meta(self, file_id, job_id, epoch) -> dict:
        path = f"{PREFIX}/files/{file_id}/meta"
        r = self.session.get(self.base + path, headers=self.headers("GET", path, job_id, epoch), timeout=self.timeout, allow_redirects=False)
        self._check(r)
        return r.json()

    def download(self, file_id, job_id, epoch, etag, fh, rng="") -> tuple[str, int]:
        """Streams the content into fh; returns (sha256 hex, bytes)."""
        path = f"{PREFIX}/files/{file_id}/content"
        h = self.headers("GET", path, job_id, epoch, rng=rng, if_match=etag)
        with self.session.get(self.base + path, headers=h, stream=True, timeout=self.timeout, allow_redirects=False) as r:
            self._check(r)
            digest = hashlib.sha256()
            n = 0
            for block in r.iter_content(1024 * 1024):
                digest.update(block)
                fh.write(block)
                n += len(block)
        return digest.hexdigest(), n

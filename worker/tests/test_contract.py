# SPDX-License-Identifier: AGPL-3.0-or-later
"""Client side of the contract: the same x-examples (and golden signatures) the PHP ContractTest replays."""
import io
import json
import os
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest
import yaml

import client as bdclient

SPEC = yaml.safe_load(open(os.environ.get("BD_SPEC", "/spec/worker-v1.yaml")))
SECRET = SPEC["x-example-secret"]
EXAMPLES = [ex for ops in SPEC["paths"].values() for ex in ops["get"]["x-examples"]]


def serve(ex):
    seen = {}

    class H(BaseHTTPRequestHandler):
        def do_GET(self):
            seen["path"] = self.path
            seen["headers"] = {k: v for k, v in self.headers.items()}
            res = ex["response"]
            body = json.dumps(res["json"]).encode() if "json" in res else b"%PDF-fake"
            self.send_response(res["status"])
            for k, v in res.get("headers", {}).items():
                self.send_header(k, v)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def log_message(self, *a):
            pass

    srv = HTTPServer(("127.0.0.1", 0), H)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv, seen


@pytest.mark.parametrize("ex", EXAMPLES, ids=[e["name"] for e in EXAMPLES])
def test_example_signature_and_request_shape(ex):
    req = ex["request"]
    # 1. the golden signature, computed independently of the client's request code
    s = bdclient.signed_string(req["method"], req["path"], req["query"], req["range"], req["if_match"], req["worker_id"], b"",
                               req["timestamp"], req["nonce"], req["job_id"], req["lease_epoch"])
    assert bdclient.sign(SECRET, s) == req["signature"], "golden signature drifted"

    # 2. the client sends exactly that request
    srv, seen = serve(ex)
    c = bdclient.WorkerClient(f"http://127.0.0.1:{srv.server_port}", req["worker_id"], SECRET,
                              clock=lambda: req["timestamp"], nonce_fn=lambda: req["nonce"])
    if req["path"].endswith("/meta"):
        out = c.meta(42, req["job_id"], req["lease_epoch"])
        assert out == ex["response"]["json"]
    else:
        c.download(42, req["job_id"], req["lease_epoch"], req["if_match"], io.BytesIO(), rng=req["range"])
    srv.shutdown()
    h = seen["headers"]
    assert seen["path"] == req["path"]
    assert h["Authorization"] == f"Bearer {req['worker_id']}.{req['signature']}"
    assert h["X-BD-Timestamp"] == str(req["timestamp"]) and h["X-BD-Nonce"] == req["nonce"]
    assert h["X-BD-Job-Id"] == str(req["job_id"]) and h["X-BD-Lease-Epoch"] == str(req["lease_epoch"])
    assert h.get("Range", "") == req["range"] and h.get("If-Match", "") == req["if_match"]


@pytest.mark.parametrize("status,exc", [(401, bdclient.AuthError), (403, bdclient.ClaimLost), (404, bdclient.ClaimLost),
                                        (412, bdclient.EtagChanged), (429, bdclient.Retryable), (503, bdclient.Retryable)])
def test_error_mapping(status, exc):
    ex = {"response": {"status": status, "json": {}}}
    srv, _ = serve(ex)
    c = bdclient.WorkerClient(f"http://127.0.0.1:{srv.server_port}", "w1", SECRET)
    with pytest.raises(exc):
        c.meta(1, 1, 1)
    srv.shutdown()


def test_every_request_has_a_fresh_nonce():
    c = bdclient.WorkerClient("http://x", "w1", SECRET)
    a = c.headers("GET", "/p", 1, 1)["X-BD-Nonce"]
    b = c.headers("GET", "/p", 1, 1)["X-BD-Nonce"]
    assert a != b and len(a) >= 16

# SPDX-License-Identifier: AGPL-3.0-or-later
"""The Ollama client against a real HTTP stub: request shape, batching, truncation policy, digest, failures."""
import json
import math
import threading

import pytest
import requests

import embed
import embedstub


@pytest.fixture()
def stub():
    s = embedstub.Stub(dim=8)
    srv = s.serve()
    s.url = f"http://127.0.0.1:{srv.server_address[1]}"
    yield s
    srv.shutdown()


def client(stub, **kw):
    return embed.Embedder(stub.url, token=kw.pop("token", None), timeout=kw.pop("timeout", 5), **kw)


def test_embeds_in_input_order_with_unit_vectors(stub):
    out = client(stub).embed("bge-m3:567m", ["hemoglobina glicosilada", "examen de laboratorio"], num_ctx=8192)
    assert len(out) == 2 and all(len(v) == 8 for v in out)
    assert all(abs(math.sqrt(sum(x * x for x in v)) - 1) < 1e-6 for v in out)
    assert out[0] != out[1]


def test_request_never_truncates_and_sets_the_context(stub, monkeypatch):
    seen = {}
    real = requests.Session.post

    def spy(self, url, **kw):
        seen.update(kw["json"])
        return real(self, url, **kw)
    monkeypatch.setattr(requests.Session, "post", spy)
    client(stub).embed("bge-m3:567m", ["a"], num_ctx=4096)
    assert seen["truncate"] is False and seen["options"] == {"num_ctx": 4096} and seen["keep_alive"] == -1


def test_batches_are_capped(stub):
    texts = [f"texto {i}" for i in range(40)]
    out = client(stub, batch=16).embed("bge-m3:567m", texts, num_ctx=512)
    assert len(out) == 40
    assert stub.stats["embed_calls"] == 3 and stub.stats["inputs"] == 40


def test_the_same_text_gets_the_same_vector_and_overlap_is_closer(stub):
    c = client(stub)
    a, b, far = c.embed("bge-m3:567m", ["resumen estadistico mensual", "resumen estadistico mensual de junio", "cadena de frio vacunas"], num_ctx=512)
    assert c.embed("bge-m3:567m", ["resumen estadistico mensual"], num_ctx=512)[0] == a
    cos = lambda x, y: sum(p * q for p, q in zip(x, y))
    assert cos(a, b) > cos(a, far)


def test_digest_comes_from_tags_and_is_none_for_an_unknown_model(stub):
    c = client(stub)
    assert c.digest("bge-m3:567m") == stub.digest
    assert c.digest("otro:1b") is None


def test_context_overflow_is_a_permanent_error(stub):
    stub.max_words = 3
    with pytest.raises(embed.EmbedError) as e:
        client(stub).embed("bge-m3:567m", ["una dos tres cuatro cinco"], num_ctx=512)
    assert not e.value.retryable


def test_unknown_model_is_permanent(stub):
    with pytest.raises(embed.EmbedError) as e:
        client(stub).embed("nada:1b", ["x"], num_ctx=512)
    assert not e.value.retryable


def test_busy_server_is_retried_inside_the_call_then_reported_as_retryable(stub):
    stub.fail = {"status": 503, "times": 1}
    assert len(client(stub, retries=2, backoff=0).embed("bge-m3:567m", ["x"], num_ctx=512)) == 1
    stub.fail = {"status": 503, "times": 9}
    with pytest.raises(embed.EmbedError) as e:
        client(stub, retries=2, backoff=0).embed("bge-m3:567m", ["x"], num_ctx=512)
    assert e.value.retryable


def test_connection_refused_is_retryable():
    with pytest.raises(embed.EmbedError) as e:
        embed.Embedder("http://127.0.0.1:9", timeout=1, retries=1, backoff=0).embed("m", ["x"], num_ctx=512)
    assert e.value.retryable


def test_bad_answers_are_rejected(stub, monkeypatch):
    c = client(stub)
    for bad in ({"embeddings": "no"}, {"embeddings": [[1.0] * 8, [1.0] * 8]}, {"embeddings": [[float("nan")] * 8]}, {"embeddings": [[0.0] * 8]}, {}):
        monkeypatch.setattr(c, "_post", lambda path, body, bad=bad: bad)
        with pytest.raises(embed.EmbedError):
            c.embed("bge-m3:567m", ["x"], num_ctx=512)


def test_the_token_is_sent_as_a_bearer_header_and_never_in_errors(stub, monkeypatch):
    seen = {}
    real = requests.Session.post

    def spy(self, url, **kw):
        seen.update(self.headers)
        return real(self, url, **kw)
    monkeypatch.setattr(requests.Session, "post", spy)
    client(stub, token="s3cr3t-token").embed("bge-m3:567m", ["x"], num_ctx=512)
    assert seen["Authorization"] == "Bearer s3cr3t-token"
    with pytest.raises(embed.EmbedError) as e:
        embed.Embedder("http://127.0.0.1:9", token="s3cr3t-token", timeout=1, retries=1, backoff=0).embed("m", ["x"], num_ctx=1)
    assert "s3cr3t-token" not in str(e.value) and "127.0.0.1" not in str(e.value)


def test_error_text_never_carries_the_document(stub):
    stub.max_words = 1
    with pytest.raises(embed.EmbedError) as e:
        client(stub).embed("bge-m3:567m", ["DATO-CENTINELA-77 paciente confidencial"], num_ctx=512)
    assert "CENTINELA" not in str(e.value) and "CENTINELA" not in repr(e.value)


def test_a_redirect_never_carries_the_text_to_another_host():
    import http.server

    received = []

    class Target(http.server.BaseHTTPRequestHandler):
        def log_message(self, *a): pass

        def do_POST(self):
            received.append(self.rfile.read(int(self.headers.get("content-length", 0))))
            self.send_response(200); self.end_headers(); self.wfile.write(b"{}")

    target = http.server.HTTPServer(("127.0.0.1", 0), Target)
    threading.Thread(target=target.serve_forever, daemon=True).start()

    class Redirector(http.server.BaseHTTPRequestHandler):
        def log_message(self, *a): pass

        def _go(self):
            self.send_response(307); self.send_header("Location", f"http://127.0.0.1:{target.server_address[1]}/steal"); self.end_headers()
        do_POST = do_GET = _go

    red = http.server.HTTPServer(("127.0.0.1", 0), Redirector)
    threading.Thread(target=red.serve_forever, daemon=True).start()
    c = embed.Embedder(f"http://127.0.0.1:{red.server_address[1]}", timeout=3, retries=1, backoff=0)
    with pytest.raises(embed.EmbedError) as e:
        c.embed("m", ["texto confidencial"], num_ctx=512)
    assert not e.value.retryable
    with pytest.raises(embed.EmbedError):
        c.digest("m")
    assert received == [], "the redirect target must never see a request"
    target.shutdown(); red.shutdown()

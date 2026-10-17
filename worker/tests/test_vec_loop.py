# SPDX-License-Identifier: AGPL-3.0-or-later
"""The vector lane: every way a vec job can end is reported or abandoned, no document text reaches a log or an error."""
import logging

import psycopg
import pytest

import embed
import embedstub
import main

PROFILE = (1, "bge-m3:567m", "sha256:abc", 8, "query: ", "passage: ", 8192)


class Result:
    def __init__(self, rows):
        self.rows = rows

    def fetchall(self):
        return self.rows

    def fetchone(self):
        return self.rows[0] if self.rows else None


class FakeDb:
    def __init__(self, profile=PROFILE, chunks=(), raise_on=None):
        self.profile, self.chunks, self.raise_on = profile, list(chunks), raise_on
        self.written, self.failed, self.calls = [], [], []

    def execute(self, sql, params=()):
        self.calls.append(sql.split("(")[0].strip())
        if self.raise_on and self.raise_on[0] in sql:
            raise self.raise_on[1]
        if "vec_profile" in sql:
            return Result([self.profile])
        if "vec_chunks" in sql:
            return Result(self.chunks)
        if "write_vectors" in sql:
            ids, vecs = params[3], params[4]
            self.written.append((list(ids), list(vecs)))
            done = sum(len(w[0]) for w in self.written) >= len(self.chunks)
            return Result([(done,)])
        if "fail_job" in sql:
            self.failed.append(params[2:])
            return Result([])
        raise AssertionError(sql)


class FakeEmbedder:
    def __init__(self, digest="sha256:abc", dim=8, error=None):
        self._digest, self.dim, self.error, self.calls = digest, dim, error, []

    def digest(self, model):
        if isinstance(self._digest, Exception):
            raise self._digest
        return self._digest

    def embed(self, model, texts, num_ctx):
        self.calls.append((model, list(texts), num_ctx))
        if self.error:
            raise self.error
        return [embedstub.embed_text(t, self.dim) for t in texts]


@pytest.fixture(autouse=True)
def quiet_heartbeat(monkeypatch):
    class H:
        lost = False

        def __init__(self, *a): self.stop = type("E", (), {"set": lambda s: None})()
        def start(self): pass
    monkeypatch.setattr(main, "Heartbeat", H)


def go(db, emb, batch=16):
    return main.vec_process(db, emb, "dsn", 5, 2, "e1", batch=batch)


def test_embeds_every_chunk_with_the_passage_prefix_and_heading_in_batches():
    db = FakeDb(chunks=[(1, "Titulo", "uno"), (2, None, "dos"), (3, None, "tres")])
    emb = FakeEmbedder()
    assert go(db, emb, batch=2) == "embedded"
    assert [c[1] for c in emb.calls] == [["passage: Titulo\nuno", "passage: dos"], ["passage: tres"]]
    assert emb.calls[0][2] == 8192
    assert [w[0] for w in db.written] == [[1, 2], [3]]
    assert all(v.startswith("[") and v.endswith("]") and len(v[1:-1].split(",")) == 8 for w in db.written for v in w[1])


def test_a_job_with_nothing_left_to_embed_still_completes():
    db = FakeDb(chunks=[])
    assert go(db, FakeEmbedder()) == "embedded"
    assert db.written == [([], [])]


def test_a_changed_model_digest_fails_the_job_and_embeds_nothing():
    db = FakeDb(chunks=[(1, None, "x")])
    emb = FakeEmbedder(digest="sha256:other")
    assert go(db, emb) == "failed:model_mismatch"
    assert emb.calls == [] and db.failed == [("model_mismatch", False)] and db.written == []


def test_an_unknown_model_at_the_endpoint_is_a_mismatch_too():
    db = FakeDb(chunks=[(1, None, "x")])
    assert go(db, FakeEmbedder(digest=None)) == "failed:model_mismatch"


def test_a_model_answering_with_another_dimension_is_a_mismatch():
    db = FakeDb(chunks=[(1, None, "x")])
    assert go(db, FakeEmbedder(dim=4)) == "failed:model_mismatch"
    assert db.written == []


def test_transient_endpoint_trouble_is_retried_with_backoff_by_the_database():
    db = FakeDb(chunks=[(1, None, "x")])
    assert go(db, FakeEmbedder(error=embed.EmbedError("busy", retryable=True))) == "failed:embed_failed"
    assert db.failed == [("embed_failed", True)]
    db2 = FakeDb(chunks=[(1, None, "x")])
    assert go(db2, FakeEmbedder(digest=embed.EmbedError("down", retryable=True))) == "failed:embed_failed"
    assert db2.failed == [("embed_failed", True)]


def test_a_permanent_error_fails_the_job_for_good():
    db = FakeDb(chunks=[(1, None, "x")])
    assert go(db, FakeEmbedder(error=embed.EmbedError("too long", retryable=False))) == "failed:embed_failed"
    assert db.failed == [("embed_failed", False)]


def test_losing_the_lease_or_changing_the_etag_abandons_quietly():
    for tag in ("stale_lease", "etag_mismatch", "file_skipped", "retired"):
        db = FakeDb(chunks=[(1, None, "x")], raise_on=("write_vectors", psycopg.errors.RaiseException(tag)))
        assert go(db, FakeEmbedder()) == "abandoned", tag
        assert db.failed == []


def test_the_database_refusing_a_vector_fails_the_job_without_retry():
    db = FakeDb(chunks=[(1, None, "x")], raise_on=("write_vectors", psycopg.errors.RaiseException("bad_vector")))
    assert go(db, FakeEmbedder()) == "failed:internal"
    assert db.failed == [("internal", False)]


def test_a_lost_heartbeat_abandons_before_writing(monkeypatch):
    class H:
        lost = True

        def __init__(self, *a): self.stop = type("E", (), {"set": lambda s: None})()
        def start(self): pass
    monkeypatch.setattr(main, "Heartbeat", H)
    db = FakeDb(chunks=[(1, None, "x")])
    assert go(db, FakeEmbedder()) == "abandoned"
    assert db.written == []


def test_nothing_about_the_document_reaches_the_log(caplog):
    caplog.set_level(logging.DEBUG)
    secret = "DATO-CENTINELA-77"
    db = FakeDb(chunks=[(1, secret, secret)])
    go(db, FakeEmbedder(error=embed.EmbedError(f"boom {secret}", retryable=True)))
    go(FakeDb(chunks=[(1, secret, secret)], raise_on=("write_vectors", RuntimeError(secret))), FakeEmbedder())
    assert secret not in caplog.text


def test_the_loop_only_runs_the_vec_lane_when_an_endpoint_is_configured(monkeypatch):
    claimed = []

    class Db:
        def execute(self, sql, params=()):
            claimed.append(params[0])
            return Result([])
    assert main.run_once(Db(), None, "dsn", "w") == 0
    assert claimed == ["text"]
    claimed.clear()
    assert main.run_once(Db(), None, "dsn", "w", embedder=FakeEmbedder()) == 0
    assert claimed == ["text", "vec"]

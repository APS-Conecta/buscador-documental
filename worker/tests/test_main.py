# SPDX-License-Identifier: AGPL-3.0-or-later
"""process(): every failure the loop can meet ends in a reported or abandoned job, never an exception that kills the worker."""
import psycopg
import pytest
import requests

import client as bdclient
import main


class FakeConn:
    def __init__(self, raise_on=None):
        self.calls, self.raise_on = [], raise_on

    def execute(self, sql, params=()):
        self.calls.append((sql.split("(")[0].strip(), params))
        if self.raise_on and self.raise_on[0] in sql:
            raise self.raise_on[1]


class FakeApi:
    def __init__(self, meta=None, meta_exc=None, size=4):
        self._meta = meta or {"mime": "application/pdf", "etag": "e", "size": size}
        self.meta_exc = meta_exc
        self.downloaded = False

    def meta(self, *a):
        if self.meta_exc:
            raise self.meta_exc
        return self._meta

    def download(self, file_id, job_id, epoch, etag, fh):
        self.downloaded = True
        fh.write(b"%PDF")
        return "h", 4


@pytest.fixture(autouse=True)
def quiet_heartbeat(monkeypatch):
    class H:
        lost = False

        def __init__(self, *a): self.stop = type("E", (), {"set": lambda s: None})()
        def start(self): pass
    monkeypatch.setattr(main, "Heartbeat", H)


def run(conn, api):
    return main.process(conn, api, "dsn", 1, 1, 7)


def test_connection_errors_abandon_the_job(monkeypatch):
    assert run(FakeConn(), FakeApi(meta_exc=requests.ConnectionError("down"))) == "abandoned"
    assert run(FakeConn(), FakeApi(meta_exc=requests.Timeout())) == "abandoned"


def test_rejected_request_and_bad_body_abandon_the_job():
    assert run(FakeConn(), FakeApi(meta_exc=ValueError("428"))) == "abandoned"
    assert run(FakeConn(), FakeApi(meta_exc=requests.exceptions.JSONDecodeError("x", "", 0))) == "abandoned"


def test_oversized_file_is_failed_before_download():
    conn, api = FakeConn(), FakeApi(size=main.MAX_BYTES + 1)
    assert run(conn, api) == "failed:too_large"
    assert not api.downloaded


def test_too_many_pages_is_failed_not_crashed(monkeypatch):
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "ok", "pages": ["x"] * (main.MAX_PAGES + 1)})
    conn = FakeConn()
    assert run(conn, FakeApi()) == "failed:too_large"
    assert any("fail_job" in c[0] for c in conn.calls)


def test_payload_too_large_from_the_database_is_reported(monkeypatch):
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "ok", "pages": ["texto"]})
    err = psycopg.errors.RaiseException("payload_too_large")
    conn = FakeConn(raise_on=("write_result", err))
    assert run(conn, FakeApi()) == "failed:too_large"


def test_unknown_database_errors_still_surface(monkeypatch):
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "ok", "pages": ["texto"]})
    conn = FakeConn(raise_on=("write_result", psycopg.errors.RaiseException("boom")))
    with pytest.raises(psycopg.errors.Error):
        run(conn, FakeApi())


def test_client_rejects_unexpected_statuses_and_short_bodies():
    class R:
        status_code = 400
    with pytest.raises(ValueError):
        bdclient.WorkerClient("http://x", "w", "s")._check(R())


@pytest.mark.parametrize("reason,expected", [
    ("timeout", "failed:timeout"), ("ocr_failed", "failed:ocr_failed"), ("unsafe", "failed:unsafe"),
    ("corrupt", "failed:corrupt"), ("encrypted", "failed:encrypted"), ("too_large", "failed:too_large")])
def test_parser_errors_become_the_matching_failure(monkeypatch, reason, expected):
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "error", "reason": reason})
    conn = FakeConn()
    assert run(conn, FakeApi()) == expected
    retry = [c for c in conn.calls if "fail_job" in c[0]][0][1][3]
    assert retry is (reason in ("timeout", "ocr_failed"))


def test_unknown_parser_reason_is_failed_as_ocr_failed(monkeypatch):
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "error", "reason": "weird"})
    assert run(FakeConn(), FakeApi()) == "failed:ocr_failed"


def test_parser_down_abandons_the_job(monkeypatch):
    def down(*a):
        raise main.jobbox.ParserUnavailable("j")
    monkeypatch.setattr(main, "parse", down)
    assert run(FakeConn(), FakeApi()) == "abandoned"

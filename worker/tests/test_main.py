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


@pytest.mark.parametrize("mime", [
    "application/pdf", "text/plain", "text/markdown", "image/png", "image/jpeg", "image/tiff",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.oasis.opendocument.text"])
def test_supported_mime_types_reach_the_parser(monkeypatch, mime):
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "ok", "pages": ["texto"]})
    api = FakeApi(meta={"mime": mime, "etag": "e", "size": 4})
    assert run(FakeConn(), api) == "indexed"


def test_unsupported_mime_type_is_skipped_not_parsed(monkeypatch):
    called = []
    monkeypatch.setattr(main, "parse", lambda *a: called.append(1))
    assert run(FakeConn(), FakeApi(meta={"mime": "application/x-msdownload", "etag": "e", "size": 4})) == "failed:unsupported"
    assert not called


def test_confidence_and_source_reach_write_result(monkeypatch):
    import json
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "ok", "pages": ["uno", "dos"], "conf": [None, 91.5],
                                                    "source": ["layer", "ocr"], "ocr_ver": "ver-x"})
    conn = FakeConn()
    assert run(conn, FakeApi()) == "indexed"
    call = [c for c in conn.calls if "write_result" in c[0]][0][1]
    rows = json.loads(call[4])
    assert [(r["source"], r["ocr_conf"]) for r in rows] == [("layer", None), ("ocr", 91.5)]
    assert call[6] == "ver-x"


def test_answers_without_confidence_still_index(monkeypatch):
    import json
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "ok", "pages": ["uno"]})
    conn = FakeConn()
    assert run(conn, FakeApi()) == "indexed"
    rows = json.loads([c for c in conn.calls if "write_result" in c[0]][0][1][4])
    assert rows == [{"page_no": 1, "text": "uno", "ocr_conf": None, "source": "ocr"}]


# ---------- S2 review fixes: a bad answer never kills the worker ----------
BAD_ANSWERS = [
    [], "x", None, {"status": "ok"}, {"status": "ok", "pages": "text"}, {"status": "ok", "pages": [1, 2]},
    {"status": "ok", "pages": ["a", "b"], "conf": [90.0]}, {"status": "ok", "pages": ["a"], "conf": [float("nan")]},
    {"status": "ok", "pages": ["a"], "conf": [250.0]}, {"status": "ok", "pages": ["a"], "source": ["weird"]},
    {"status": "ok", "pages": ["a"], "ocr_ver": 5}, {"status": "maybe"},
]


@pytest.mark.parametrize("answer", BAD_ANSWERS, ids=[str(i) for i in range(len(BAD_ANSWERS))])
def test_a_malformed_parser_answer_is_a_terminal_internal_failure(monkeypatch, answer):
    monkeypatch.setattr(main, "parse", lambda *a: answer)
    assert run(FakeConn(), FakeApi()) == "failed:internal"


def test_an_unexpected_error_abandons_the_job_instead_of_killing_the_worker(monkeypatch):
    def boom(*a):
        raise RuntimeError("bug")
    monkeypatch.setattr(main, "parse", boom)
    assert run(FakeConn(), FakeApi()) == "abandoned"


def test_disk_errors_on_the_shared_volume_abandon_the_job(monkeypatch):
    def full(*a):
        raise OSError(28, "No space left on device")
    monkeypatch.setattr(main, "parse", full)
    assert run(FakeConn(), FakeApi()) == "abandoned"


def test_text_beyond_the_payload_cap_is_too_large_before_chunking(monkeypatch):
    monkeypatch.setattr(main, "MAX_TEXT_CHARS", 100)
    monkeypatch.setattr(main.chunk, "split", lambda p: pytest.fail("must not chunk"))
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "ok", "pages": ["x" * 101]})
    assert run(FakeConn(), FakeApi()) == "failed:too_large"


def test_the_parser_timeout_scales_with_the_file_size():
    assert main.timeout_for(1000) == 120
    assert main.timeout_for(10 * 1024 * 1024) == 120 + 200
    assert main.timeout_for(250 * 1024 * 1024) == 3600


def test_parser_detail_is_logged_without_control_characters(monkeypatch, caplog):
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "error", "reason": "ocr_failed", "detail": "a\rb\x1b[31mc\x0bd e"})
    with caplog.at_level("WARNING", logger="bd-worker"):
        run(FakeConn(), FakeApi())
    msg = [r.getMessage() for r in caplog.records if "parser said" in r.getMessage()][0]
    assert not any(ch in msg for ch in "\r\x1b\x0b ")


# ---------- S3: the worker refuses a schema it does not know ----------
class VersionConn:
    def __init__(self, version=None, error=None):
        self.version, self.error = version, error

    def execute(self, sql, params=()):
        if self.error:
            raise self.error
        assert "schema_version" in sql
        return type("R", (), {"fetchone": lambda s: (self.version,)})()


def test_the_supported_schema_passes():
    main.check_schema(VersionConn(main.SUPPORTED_SCHEMA))


@pytest.mark.parametrize("version", [1, 3, 99])
def test_an_unknown_schema_stops_the_worker_with_a_clear_message(version):
    with pytest.raises(SystemExit) as e:
        main.check_schema(VersionConn(version))
    assert str(main.SUPPORTED_SCHEMA) in str(e.value) and str(version) in str(e.value)


def test_a_missing_schema_function_stops_the_worker_too():
    with pytest.raises(SystemExit):
        main.check_schema(VersionConn(error=psycopg.errors.UndefinedFunction("no such function")))

# SPDX-License-Identifier: AGPL-3.0-or-later
"""A-SEC-5: no document or query text in logs, errors or history. A sentinel is pushed down every path that can write a
message; it must never come out. Each test also checks that the control still logs (so the assertion cannot pass by silence)."""
import json
import logging
import sys

import pytest

import client as bdclient
import fixtures as fx
import jobbox
import main
import parse_job
import pdfocr

SENT = "SENTINEL-Juan-Perez-contrato-7f3a"


class Conn:
    def execute(self, *a, **k):
        return type("R", (), {"fetchone": lambda s: (1,)})()


class Api:
    def meta(self, *a):
        return {"mime": "application/pdf", "etag": "e", "size": 4}

    def download(self, file_id, job_id, epoch, etag, fh):
        fh.write(b"%PDF")
        return "h", 4


@pytest.fixture(autouse=True)
def quiet_heartbeat(monkeypatch):
    class H:
        lost = False

        def __init__(self, *a):
            self.stop = type("E", (), {"set": lambda s: None})()

        def start(self):
            pass
    monkeypatch.setattr(main, "Heartbeat", H)


def process():
    return main.process(Conn(), Api(), "dsn", 1, 1, 7)


def test_an_unexpected_error_logs_its_class_and_place_never_its_message(monkeypatch, caplog):
    def boom(*a):
        raise RuntimeError(f"bad value in {SENT}")           # not a ValueError: that is caught by an earlier, class-only handler
    monkeypatch.setattr(main, "parse", boom)
    with caplog.at_level(logging.DEBUG, logger="bd-worker"):
        process()
    text = caplog.text
    assert "RuntimeError" in text, "the control: something was logged"
    assert SENT not in text
    assert "Traceback" not in text, "log.exception would print the message inside the traceback"


def test_parser_detail_is_never_logged_by_default(monkeypatch, caplog):
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "error", "reason": "ocr_failed", "detail": f"stderr: {SENT}"})
    with caplog.at_level(logging.DEBUG, logger="bd-worker"):
        assert process() == "failed:ocr_failed"
    assert "ocr_failed" in caplog.text or "parser" in caplog.text, "the control: the refusal is logged by its reason"
    assert SENT not in caplog.text


def test_parser_detail_can_be_logged_for_debugging_only_when_asked(monkeypatch, caplog):
    monkeypatch.setenv("BD_DEBUG_PARSER", "1")
    monkeypatch.setattr(main, "parse", lambda *a: {"status": "error", "reason": "ocr_failed", "detail": "child exited 3"})
    with caplog.at_level(logging.DEBUG, logger="bd-worker"):
        process()
    assert "child exited 3" in caplog.text


def test_the_child_reports_an_unexpected_error_by_class_only(tmp_path, monkeypatch):
    monkeypatch.setattr(pdfocr, "run", lambda *a, **k: (_ for _ in ()).throw(ValueError(f"cannot decode {SENT}")))
    pdf = fx.pdf_text(tmp_path / "a.pdf")
    req, res = tmp_path / "r.json", tmp_path / "o.json"
    req.write_text(json.dumps({"kind": "file", "input": pdf}))
    parse_job.main(str(req), str(res))
    out = json.loads(res.read_text())
    assert out["reason"] == "internal" and out["detail"] == "ValueError"
    assert SENT not in json.dumps(out)


def test_a_crashed_childs_stderr_is_not_returned_unless_debugging(tmp_path, monkeypatch):
    code = f"import sys; sys.stderr.write('{SENT}'); sys.exit(3)"
    req, res = tmp_path / "r.json", tmp_path / "o.json"
    req.write_text("{}")
    out = jobbox.run_child([sys.executable, "-c", code], str(req), str(res), 10)
    assert out["reason"] == "ocr_failed" and out["detail"] == "child exited 3"
    assert SENT not in json.dumps(out)
    monkeypatch.setenv("BD_DEBUG_PARSER", "1")
    out = jobbox.run_child([sys.executable, "-c", code], str(req), str(res), 10)
    assert SENT in out["detail"], "debug mode is the one place the tail is kept"


def test_the_parser_loop_prints_the_class_of_a_failure_not_its_message(monkeypatch, capsys):
    import parserd
    calls = []

    def serve(*a, **k):
        calls.append(1)
        if len(calls) == 1:
            raise OSError(f"disk full writing {SENT}")
        raise KeyboardInterrupt
    monkeypatch.setattr(parserd.jobbox, "serve_once", serve)
    monkeypatch.setattr(parserd.jobbox, "purge", lambda *a: None)
    monkeypatch.setattr(parserd.time, "sleep", lambda s: None)
    monkeypatch.setattr(parserd, "READY", type("P", (), {"touch": lambda s: None})())
    with pytest.raises(KeyboardInterrupt):
        parserd.main()
    err = capsys.readouterr().err
    assert "OSError" in err and SENT not in err


def test_an_ocr_failure_message_carries_the_exit_status_only(tmp_path, monkeypatch):
    class R:
        returncode = 4
        stderr = f"tesseract said: {SENT}"
        stdout = ""
    monkeypatch.setattr(pdfocr.subprocess, "run", lambda *a, **k: R())
    import ocr
    with pytest.raises(ocr.OcrFailed) as e:
        pdfocr._ocr_page_once(str(tmp_path / "x.pdf"), False, str(tmp_path))
    assert "4" in str(e.value) and SENT not in str(e.value)


def test_the_top_level_guard_exits_with_a_message_free_of_the_error_text(monkeypatch, capsys):
    monkeypatch.setattr(main, "main", lambda: (_ for _ in ()).throw(RuntimeError(f"Failing row contains ({SENT})")))
    with pytest.raises(SystemExit) as e:
        main.run_guarded()
    assert e.value.code == 1
    err = capsys.readouterr().err
    assert "RuntimeError" in err and SENT not in err

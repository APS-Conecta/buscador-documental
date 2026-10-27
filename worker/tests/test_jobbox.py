# SPDX-License-Identifier: AGPL-3.0-or-later
"""The supervisor/parser protocol: files in a shared directory, one child per job, process group killed on timeout."""
import json
import os
import sys
import threading
import time

import pytest

import jobbox


def child(code):
    """A child command; the request path arrives as argv[1], the result path as argv[2]."""
    return [sys.executable, "-c", code]


ECHO = "import json,sys; r=json.load(open(sys.argv[1])); json.dump({'status':'ok','pages':['x']*r['n']}, open(sys.argv[2],'w'))"


@pytest.fixture
def work(tmp_path):
    jobbox.prepare(str(tmp_path))
    return str(tmp_path)


def serve_in_thread(work, cmd):
    t = threading.Thread(target=lambda: jobbox.serve_once(work, cmd, wait=5), daemon=True)
    t.start()
    return t


def finishes_within(fn, seconds):
    """Runs fn in a daemon thread and returns its result; fails when it is still running after `seconds`."""
    box = {}

    def run():
        try:
            box["value"] = fn()
        except BaseException as e:
            box["error"] = e
    t = threading.Thread(target=run, daemon=True)
    t.start()
    t.join(seconds)
    assert not t.is_alive(), f"still running after {seconds} s"
    if "error" in box:
        raise box["error"]
    return box.get("value")


def test_roundtrip_returns_the_childs_result(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, child(ECHO))
    out = jobbox.submit(work, "j1", str(src), {"kind": "pdf", "n": 3}, timeout=10)
    assert out == {"status": "ok", "pages": ["x", "x", "x"]}
    assert not os.listdir(os.path.join(work, "in")), "input and request are removed after the job"


def test_timeout_kills_the_whole_process_group(work, tmp_path):
    marker = tmp_path / "grandchild.alive"
    code = ("import subprocess,sys,time;"
            f"subprocess.Popen([sys.executable,'-c','import time,pathlib;\\nwhile True:\\n pathlib.Path(r\"{marker}\").write_text(str(time.time()));time.sleep(0.1)']);"
            "time.sleep(60)")
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, child(code))
    out = jobbox.submit(work, "j2", str(src), {"kind": "pdf"}, timeout=1)
    assert out == {"status": "error", "reason": "timeout"}
    time.sleep(0.5)
    before = marker.read_text() if marker.exists() else None
    time.sleep(0.5)
    assert (marker.read_text() if marker.exists() else None) == before, "the grandchild stopped writing: group was killed"


def test_child_crash_is_reported_not_raised(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, child("import os; os._exit(9)"))
    out = jobbox.submit(work, "j3", str(src), {"kind": "pdf"}, timeout=10)
    assert out["status"] == "error" and out["reason"] == "ocr_failed" and "exited 9" in out["detail"]


def test_child_stderr_tail_is_returned_for_the_log(work, tmp_path, monkeypatch):
    monkeypatch.setenv("BD_DEBUG_PARSER", "1")                  # the tail is a debugging aid only (A-SEC-5)
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, child("import sys; sys.stderr.write('boom: bad thing'); sys.exit(3)"))
    out = jobbox.submit(work, "j5", str(src), {"kind": "pdf"}, timeout=10)
    assert "boom: bad thing" in out["detail"]


def test_no_server_means_unavailable(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    with pytest.raises(jobbox.ParserUnavailable):
        jobbox.submit(work, "j4", str(src), {"kind": "pdf"}, timeout=1, grace=1)


def test_job_ids_cannot_escape_the_work_directory(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    with pytest.raises(ValueError):
        jobbox.submit(work, "../evil", str(src), {"kind": "pdf"}, timeout=1)


def test_a_handled_request_is_not_run_a_second_time(work, tmp_path):
    runs = tmp_path / "runs"
    code = f"import json,sys; open(r'{runs}','a').write('x'); json.dump({{'status':'ok','pages':[]}}, open(sys.argv[2],'w'))"
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    jobbox.prepare(work)
    # hand-place a request the way submit does, then let the parser loop run twice before the supervisor cleans up
    import shutil
    shutil.copyfile(src, os.path.join(work, "in", "j9.bin"))
    json.dump({"kind": "pdf", "input": "x", "timeout": 5}, open(os.path.join(work, "in", "j9.req.json"), "w"))
    assert jobbox.serve_once(work, child(code), wait=1) is True
    assert jobbox.serve_once(work, child(code), wait=0.3) is False
    assert runs.read_text() == "x"


def test_a_stale_result_with_the_same_id_is_not_mistaken_for_the_answer(work, tmp_path):
    jobbox.prepare(work)
    json.dump({"status": "error", "reason": "ocr_failed"}, open(os.path.join(work, "out", "j10.json"), "w"))
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, child(ECHO))
    out = jobbox.submit(work, "j10", str(src), {"kind": "pdf", "n": 1}, timeout=10)
    assert out == {"status": "ok", "pages": ["x"]}


# ---------- S2 review fixes: the parser must outlive any request ----------
def write_req(work, job_id, body):
    jobbox.prepare(work)
    open(os.path.join(work, "in", f"{job_id}.req.json"), "w").write(body)


@pytest.mark.parametrize("body", ["not json", "[]", '{"timeout": "soon"}', '{"timeout": -5}', '{"timeout": 99999}', ""])
def test_a_malformed_request_gets_an_error_answer_and_does_not_raise(work, body):
    write_req(work, "bad1", body)
    assert jobbox.serve_once(work, child(ECHO), wait=1) is True
    ans = json.load(open(os.path.join(work, "out", "bad1.json")))
    assert ans["status"] == "error" and ans["reason"] == "internal"
    assert not os.listdir(os.path.join(work, "in")), "the bad request is removed, so a restart does not crash again"


def test_a_request_that_vanishes_before_it_is_read_is_skipped(work, monkeypatch):
    write_req(work, "gone", '{"timeout": 5}')
    real = os.open

    def flaky(path, *a, **k):
        if str(path).endswith("gone.req.json"):
            os.unlink(path)
        return real(path, *a, **k)
    monkeypatch.setattr(os, "open", flaky)
    assert jobbox.serve_once(work, child(ECHO), wait=0.5) is False      # must not raise
    assert not os.listdir(os.path.join(work, "out"))


def test_the_result_is_published_after_the_request_files_are_gone(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    seen = {}

    def watcher():
        res = os.path.join(work, "out", "j20.json")
        while not os.path.exists(res):
            time.sleep(0.005)
        seen["in_dir"] = os.listdir(os.path.join(work, "in"))

    threading.Thread(target=watcher, daemon=True).start()
    serve_in_thread(work, child(ECHO))
    jobbox.submit(work, "j20", str(src), {"kind": "pdf", "n": 1}, timeout=10)
    assert seen["in_dir"] == []


def test_purge_removes_leftovers_from_a_previous_life(work):
    open(os.path.join(work, "in", "old.req.json"), "w").write("{}")
    open(os.path.join(work, "out", "old.json"), "w").write("{}")
    jobbox.purge(work)
    assert os.listdir(os.path.join(work, "in")) == [] and os.listdir(os.path.join(work, "out")) == []


@pytest.mark.parametrize("cmd,data", [
    (child("import json,sys; json.dump({'status':'ok','pages':['x'*1000]}, open(sys.argv[2],'w'))"), b"%PDF"),
    (jobbox.DEFAULT_CHILD, ("información " * 20).encode()),     # 260 bytes of text: over the cap before any escaping
], ids=["synthetic", "text-file"])
def test_an_oversized_result_is_refused_not_loaded(work, tmp_path, monkeypatch, cmd, data):
    monkeypatch.setattr(jobbox, "RESULT_MAX", 100)
    src = tmp_path / "src.bin"
    src.write_bytes(data)
    serve_in_thread(work, cmd)
    out = jobbox.submit(work, "j21", str(src), {"kind": "file"}, timeout=10)
    assert out == {"status": "error", "reason": "too_large", "detail": "answer too large"}


def test_a_symlinked_result_is_not_followed(work, tmp_path):
    secret = tmp_path / "secret.json"
    secret.write_text('{"status":"ok","pages":["LEAK"]}')
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    jobbox.prepare(work)
    os.symlink(secret, os.path.join(work, "out", "j22.json.final"))   # planted by a compromised child
    serve_in_thread(work, child("import os,sys; os.symlink(%r, sys.argv[2])" % str(secret)))
    out = jobbox.submit(work, "j22", str(src), {"kind": "pdf"}, timeout=10)
    assert "LEAK" not in json.dumps(out)


def test_only_the_tail_of_a_flooded_stderr_is_read(work, tmp_path, monkeypatch):
    monkeypatch.setenv("BD_DEBUG_PARSER", "1")
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, child("import sys; sys.stderr.write('A'*5_000_000 + 'THE-END'); sys.exit(2)"))
    out = jobbox.submit(work, "j23", str(src), {"kind": "pdf"}, timeout=20)
    assert out["detail"].endswith("THE-END") and len(out["detail"]) <= 400


def test_job_ids_with_a_trailing_newline_are_refused(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    with pytest.raises(ValueError):
        jobbox.submit(work, "ok\n", str(src), {"kind": "pdf"}, timeout=1)


# ---------- work-volume entries are opened only as regular files ----------
def test_a_fifo_at_the_answer_path_is_refused(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, child("import os,sys,time; os.mkfifo(sys.argv[2][:-4]); time.sleep(1)"))
    out = finishes_within(lambda: jobbox.submit(work, "j24", str(src), {"kind": "pdf"}, timeout=2, grace=1), 5)
    assert out == {"status": "error", "reason": "ocr_failed", "detail": "answer is not a regular file"}


def test_a_fifo_at_the_childs_answer_path_gives_an_error_result(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    t = serve_in_thread(work, child("import os,sys; os.mkfifo(sys.argv[2])"))
    out = finishes_within(lambda: jobbox.submit(work, "j25", str(src), {"kind": "pdf"}, timeout=2, grace=1), 5)
    assert out["status"] == "error" and out["reason"] == "ocr_failed"
    t.join(3)
    assert not t.is_alive(), "the parser finished the request and can serve the next one"


def test_an_entry_at_the_answer_staging_name_is_replaced(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    t = serve_in_thread(work, child("import json,os,sys; os.mkfifo(sys.argv[2][:-4] + '.final');"
                                    "json.dump({'status':'ok','pages':['x']}, open(sys.argv[2],'w'))"))
    out = finishes_within(lambda: jobbox.submit(work, "j26", str(src), {"kind": "pdf"}, timeout=2, grace=1), 5)
    assert out == {"status": "ok", "pages": ["x"]}
    t.join(3)
    assert not t.is_alive(), "the parser finished the request and can serve the next one"


def test_an_entry_at_the_stderr_name_is_replaced(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    os.mkfifo(os.path.join(work, "out", "j27.json.tmp.err"))
    t = serve_in_thread(work, child(ECHO))
    out = finishes_within(lambda: jobbox.submit(work, "j27", str(src), {"kind": "pdf", "n": 1}, timeout=2, grace=1), 5)
    assert out == {"status": "ok", "pages": ["x"]}
    t.join(3)
    assert not t.is_alive(), "the parser finished the request and can serve the next one"


def test_the_debug_stderr_tail_reads_only_a_regular_file(work, tmp_path, monkeypatch):
    monkeypatch.setenv("BD_DEBUG_PARSER", "1")
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    t = serve_in_thread(work, child("import os,sys; os.unlink(sys.argv[2] + '.err'); os.mkfifo(sys.argv[2] + '.err'); sys.exit(3)"))
    out = finishes_within(lambda: jobbox.submit(work, "j28", str(src), {"kind": "pdf"}, timeout=2, grace=1), 5)
    assert out == {"status": "error", "reason": "ocr_failed", "detail": "child exited 3"}
    t.join(3)
    assert not t.is_alive(), "the parser finished the request and can serve the next one"


def test_a_fifo_in_the_request_directory_gets_an_error_answer(work):
    os.mkfifo(os.path.join(work, "in", "a0.req.json"))
    assert finishes_within(lambda: jobbox.serve_once(work, child(ECHO), wait=1), 5) is True
    assert not os.listdir(os.path.join(work, "in"))
    ans = json.load(open(os.path.join(work, "out", "a0.json")))
    assert ans["status"] == "error" and ans["reason"] == "internal"

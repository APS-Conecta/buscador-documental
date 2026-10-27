# SPDX-License-Identifier: AGPL-3.0-or-later
"""The supervisor/parser protocol: files in a shared directory, one child per job, process group killed on timeout."""
import json
import os
import sys
import tempfile
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


def hanging_child(marker, first=""):
    """A child that runs `first`, leaves a grandchild rewriting `marker` every 0.1 s, then hangs."""
    return child("import subprocess,sys,time;" + first +
                 f"subprocess.Popen([sys.executable,'-c','import time,pathlib;\\nwhile True:\\n pathlib.Path(r\"{marker}\").write_text(str(time.time()));time.sleep(0.1)']);"
                 "time.sleep(60)")


def assert_group_killed(marker):
    time.sleep(0.5)
    before = marker.read_text() if marker.exists() else None
    time.sleep(0.5)
    assert (marker.read_text() if marker.exists() else None) == before, "the grandchild stopped writing: group was killed"


def test_timeout_kills_the_whole_process_group(work, tmp_path):
    marker = tmp_path / "grandchild.alive"
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, hanging_child(marker))
    out = jobbox.submit(work, "j2", str(src), {"kind": "pdf"}, timeout=1)
    assert out == {"status": "error", "reason": "timeout"}
    assert_group_killed(marker)


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


# ---------- issue 46: the child extends its own budget through a sidecar next to its result ----------
@pytest.mark.parametrize("sidecar,budget", [
    (None, 5), ("", 5), ("abc", 5), ("-5", 5), ("9" * 16, jobbox.MAX_TIMEOUT),
    ("symlink", 5), ("directory", 5), ("fifo", 5),
], ids=["missing", "empty", "not-a-number", "negative", "huge", "symlink", "directory", "fifo"])
def test_a_bad_extension_never_shrinks_blocks_or_overflows_the_budget(tmp_path, sidecar, budget):
    res = str(tmp_path / "j.json.tmp")
    side = res + ".extra"
    if sidecar == "symlink":
        (tmp_path / "elsewhere").write_text("300")
        os.symlink(tmp_path / "elsewhere", side)
    elif sidecar == "directory":
        os.mkdir(side)
    elif sidecar == "fifo":
        os.mkfifo(side)                     # no writer: a blocking open would hang the daemon or the supervisor
    elif sidecar is not None:
        open(side, "w").write(sidecar)
    got = []
    t = threading.Thread(target=lambda: got.append(jobbox.granted(5, res)), daemon=True)
    t.start()
    t.join(2)
    assert got == [budget]


def test_a_hung_child_is_killed_when_its_extended_budget_runs_out(work, tmp_path):
    marker = tmp_path / "grandchild.alive"
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")
    serve_in_thread(work, hanging_child(marker, first="open(sys.argv[2] + '.extra', 'w').write('2');"))
    start = time.monotonic()
    out = jobbox.submit(work, "j30", str(src), {"kind": "pdf"}, timeout=1)
    took = time.monotonic() - start
    assert out == {"status": "error", "reason": "timeout"}
    assert 2.5 <= took < 5, took                 # killed at base 1 s + the 2 s it announced, not at the base alone
    assert_group_killed(marker)


def test_a_dropped_request_is_given_up_on_within_its_extended_budget(work, tmp_path):
    src = tmp_path / "a.pdf"
    src.write_bytes(b"%PDF")

    def parser_that_restarts():
        time.sleep(0.3)
        jobbox.announce(os.path.join(work, "out", "j31.json.tmp"), 2)      # the child counted its OCR'd pages ...
        time.sleep(0.3)
        jobbox.purge(work)                                                  # ... then the parser restarted
    threading.Thread(target=parser_that_restarts, daemon=True).start()
    start = time.monotonic()
    with pytest.raises(jobbox.ParserUnavailable):
        jobbox.submit(work, "j31", str(src), {"kind": "pdf"}, timeout=1, grace=1)
    took = time.monotonic() - start
    assert 3 <= took < 5, took                   # base 1 s + 2 s announced + 1 s grace, kept after the purge


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


# ---------- a child's temp files never outlive it ----------
@pytest.fixture
def temp_root(tmp_path, monkeypatch):
    """Stands in for the parser's /tmp tmpfs: the daemon's and the children's default temp directory."""
    root = tmp_path / "root"
    root.mkdir()
    monkeypatch.setattr(tempfile, "tempdir", str(root))
    monkeypatch.setenv("TMPDIR", str(root))
    return root


def test_a_timed_out_child_leaves_no_temp_files(temp_root, tmp_path):
    witness = tmp_path / "witness.json"
    code = ("import json,os,subprocess,sys,tempfile,time;"
            "d=tempfile.mkdtemp(); open(os.path.join(d,'page.png'),'w').write('x');"
            "f=tempfile.mkstemp()[1];"
            "g=subprocess.run([sys.executable,'-c','import tempfile; print(tempfile.mkdtemp())'],"
            "capture_output=True,text=True).stdout.strip();"
            f"open(r'{witness}','w').write(json.dumps([d,f,g]));"
            "time.sleep(60)")
    out = jobbox.run_child(child(code), str(tmp_path / "req.json"), str(tmp_path / "res.json"), timeout=2)
    assert out == {"status": "error", "reason": "timeout"}
    left = json.loads(witness.read_text())
    assert len(left) == 3, "the child created its temp files before it was killed"
    assert [p for p in left if os.path.lexists(p)] == []
    assert list(temp_root.iterdir()) == []


def test_a_child_that_exits_leaves_no_temp_files_and_its_answer_is_delivered(temp_root, tmp_path):
    code = ("import json,os,sys,tempfile;"
            "tempfile.mkstemp();"
            "d=tempfile.mkdtemp(); open(os.path.join(d,'page.pdf'),'w').write('x');"
            "os.chmod(d,0o500);"   # a read-only directory defeats a plain rmtree under the image's non-root uid 10001
            "json.dump({'status':'ok','pages':['x']}, open(sys.argv[2],'w'))")
    out = jobbox.run_child(child(code), str(tmp_path / "req.json"), str(tmp_path / "res.json"), timeout=10)
    assert out == {"status": "ok", "pages": ["x"]}
    assert list(temp_root.iterdir()) == []


def test_cleanup_spares_other_requests_and_the_heartbeat(work, temp_root):
    ready = temp_root / "ready"
    ready.touch()
    inbox = os.path.join(work, "in")
    for job_id in ("a1", "b2"):                          # b2 sorts after a1: it stays pending
        open(os.path.join(inbox, f"{job_id}.bin"), "wb").write(b"%PDF")
        write_req(work, job_id, '{"timeout": 10}')
    code = ("import json,os,sys,tempfile; t=tempfile.gettempdir();"
            f"os.symlink(r'{ready}', os.path.join(t,'ready-link')); os.symlink(r'{inbox}', os.path.join(t,'in-link'));"
            "json.dump({'status':'ok','pages':['x']}, open(sys.argv[2],'w'))")
    assert jobbox.serve_once(work, child(code), wait=0) is True
    assert ready.exists()
    assert os.path.exists(os.path.join(inbox, "b2.bin")) and os.path.exists(os.path.join(inbox, "b2.req.json"))
    assert json.load(open(os.path.join(work, "out", "a1.json"))) == {"status": "ok", "pages": ["x"]}
    assert sorted(os.listdir(temp_root)) == ["ready"]

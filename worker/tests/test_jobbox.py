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


def test_child_stderr_tail_is_returned_for_the_log(work, tmp_path):
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

# SPDX-License-Identifier: AGPL-3.0-or-later
"""Supervisor <-> parser protocol over a shared directory (ADR-0011).

The supervisor copies the input to <work>/in/<id>.bin and writes <work>/in/<id>.req.json; the parser container runs
`serve_once` in a loop, starts one child per request in its own session, and writes <work>/out/<id>.json. The parser has
no network and no secrets; the files are the whole interface. The child gets (request path, result path) as argv.

Both sides treat the other as untrusted: the parser survives any malformed request (it answers with an error and removes
the file), and the supervisor reads an answer only through a non-following, size-capped open."""
import json
import os
import re
import resource
import shutil
import signal
import subprocess
import sys
import time

ID_RE = re.compile(r"[A-Za-z0-9_-]{1,64}")
DEFAULT_CHILD = [sys.executable, os.path.join(os.path.dirname(os.path.abspath(__file__)), "parse_job.py")]
RESULT_MAX = 96 * 1024 * 1024        # a 64 MB page payload plus overhead; anything bigger is not a legitimate answer
ERR_TAIL = 400
CHILD_FSIZE = 512 * 1024 * 1024      # no child writes a file bigger than this (stderr floods, runaway temp files)
MAX_TIMEOUT = 3600


class ParserUnavailable(Exception):
    """No result appeared in time: the parser container is down or stuck."""


def prepare(work: str) -> None:
    for d in ("in", "out"):
        os.makedirs(os.path.join(work, d), exist_ok=True)


def purge(work: str) -> None:
    """Parser start-up: whatever is in the work directory belongs to a previous life and its jobs were abandoned."""
    prepare(work)
    for d in ("in", "out"):
        for name in os.listdir(os.path.join(work, d)):
            try:
                os.unlink(os.path.join(work, d, name))
            except OSError:
                pass


def _check_id(job_id: str) -> str:
    if not ID_RE.fullmatch(job_id):
        raise ValueError("bad job id")
    return job_id


def _unlink(*paths: str) -> None:
    for p in paths:
        try:
            os.unlink(p)
        except FileNotFoundError:
            pass


def _read_answer(path: str) -> dict:
    """Opens without following symlinks and refuses oversized files before loading them."""
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    except OSError:
        return {"status": "error", "reason": "ocr_failed", "detail": "answer is not a regular file"}
    with os.fdopen(fd, "rb") as fh:
        if os.fstat(fh.fileno()).st_size > RESULT_MAX:
            return {"status": "error", "reason": "ocr_failed", "detail": "answer too large"}
        try:
            return json.loads(fh.read(RESULT_MAX + 1))
        except (ValueError, RecursionError):
            return {"status": "error", "reason": "ocr_failed", "detail": "answer is not JSON"}


def submit(work: str, job_id: str, src: str, request: dict, timeout: int, grace: int = 30) -> dict:
    """Copies src in, waits for the parser's answer. `timeout` is the per-file budget the parser enforces; `grace` is how
    long past it the supervisor waits before declaring the parser unavailable."""
    _check_id(job_id)
    prepare(work)
    binp = os.path.join(work, "in", f"{job_id}.bin")
    req = os.path.join(work, "in", f"{job_id}.req.json")
    res = os.path.join(work, "out", f"{job_id}.json")
    _unlink(res, res + ".final", res + ".err", binp, req, req + ".tmp")   # ids repeat after a database reset: start clean
    try:
        with open(src, "rb") as fin, os.fdopen(os.open(binp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600), "wb") as fout:
            shutil.copyfileobj(fin, fout, 1024 * 1024)
        tmp = req + ".tmp"
        with open(tmp, "w") as fh:
            json.dump({**request, "input": binp, "timeout": timeout}, fh)
        os.replace(tmp, req)                       # the parser only sees complete requests
        deadline = time.monotonic() + timeout + grace
        while time.monotonic() < deadline:
            if os.path.lexists(res):
                return _read_answer(res)
            time.sleep(0.1)
        raise ParserUnavailable(job_id)
    finally:
        _unlink(binp, req, res, res + ".final", res + ".err", req + ".tmp")


def _limits():
    resource.setrlimit(resource.RLIMIT_FSIZE, (CHILD_FSIZE, CHILD_FSIZE))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))


def run_child(cmd: list, req: str, res: str, timeout: int) -> dict:
    """Runs one child in its own session; on timeout the whole group is killed."""
    errp = res + ".err"
    with open(errp, "wb") as errf:
        p = subprocess.Popen(cmd + [req, res], start_new_session=True, stdin=subprocess.DEVNULL,
                             stdout=subprocess.DEVNULL, stderr=errf, preexec_fn=_limits)
    try:
        p.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        os.killpg(p.pid, signal.SIGKILL)
        p.wait()
        return {"status": "error", "reason": "timeout"}
    finally:
        try:
            os.killpg(p.pid, signal.SIGKILL)   # stray grandchildren of a child that exited
        except ProcessLookupError:
            pass
    if os.path.lexists(res) and not os.path.islink(res):
        try:
            with open(res) as fh:
                return json.load(fh)
        except (ValueError, RecursionError):
            pass
    tail = ""
    try:
        with open(errp, "rb") as fh:
            fh.seek(0, os.SEEK_END)
            fh.seek(max(0, fh.tell() - ERR_TAIL))
            tail = fh.read(ERR_TAIL).decode("utf-8", "replace")      # diagnostics for the supervisor's log, never for users
    except OSError:
        pass
    return {"status": "error", "reason": "ocr_failed", "detail": tail or f"child exited {p.returncode}"}


def _answer(work: str, job_id: str, answer: dict) -> None:
    final = os.path.join(work, "out", f"{job_id}.json")
    with open(final + ".final", "w") as fh:
        json.dump(answer, fh)
    os.replace(final + ".final", final)


def serve_once(work: str, child_cmd: list = None, wait: float = 1.0) -> bool:
    """Handles at most one pending request; returns whether one was handled. Never raises for a bad request."""
    prepare(work)
    end = time.monotonic() + wait
    while True:
        for name in sorted(os.listdir(os.path.join(work, "in"))):
            if not name.endswith(".req.json"):
                continue
            job_id = name[: -len(".req.json")]
            req = os.path.join(work, "in", name)
            binp = os.path.join(work, "in", f"{job_id}.bin")
            tmp_res = os.path.join(work, "out", f"{job_id}.json.tmp")
            if not ID_RE.fullmatch(job_id):
                _unlink(req, binp)
                continue
            try:
                with open(req) as fh:
                    body = json.load(fh)
                timeout = body["timeout"]
                if not isinstance(timeout, int) or isinstance(timeout, bool) or not 1 <= timeout <= MAX_TIMEOUT:
                    raise ValueError("bad timeout")
            except FileNotFoundError:
                _unlink(binp)                         # the supervisor gave up and cleaned: nothing to do
                continue
            except (OSError, ValueError, KeyError, TypeError, RecursionError) as e:
                _unlink(req, binp)
                _answer(work, job_id, {"status": "error", "reason": "internal", "detail": f"bad request: {type(e).__name__}"})
                return True
            out = run_child(child_cmd or DEFAULT_CHILD, req, tmp_res, timeout)
            _unlink(tmp_res, tmp_res + ".err", req, binp)       # request files go first: nothing left when the answer is visible
            _answer(work, job_id, out)
            return True
        if time.monotonic() >= end:
            return False
        time.sleep(0.1)

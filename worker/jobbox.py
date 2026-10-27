# SPDX-License-Identifier: AGPL-3.0-or-later
"""Supervisor <-> parser protocol over a shared directory (ADR-0011).

The supervisor copies the input to <work>/in/<id>.bin and writes <work>/in/<id>.req.json; the parser container runs
`serve_once` in a loop, starts one child per request in its own session, and writes <work>/out/<id>.json. The parser has
no network and no secrets; the files are the whole interface. The child gets (request path, result path) as argv, and
may write <result>.extra: the extra seconds it needs beyond the request's base budget (`announce`, `granted`).

Both sides treat the other as untrusted: the parser survives any malformed request (it answers with an error and removes
the file), and the supervisor reads an answer only through a non-following, regular-file-only, size-capped open."""
import json
import os
import re
import resource
import shutil
import signal
import stat
import subprocess
import sys
import time

ID_RE = re.compile(r"[A-Za-z0-9_-]{1,64}")
DEFAULT_CHILD = [sys.executable, os.path.join(os.path.dirname(os.path.abspath(__file__)), "parse_job.py")]
RESULT_MAX = 96 * 1024 * 1024        # a 64 MB page payload plus overhead; a bigger answer means the file's text is too large to index
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


def _create(path: str, mode: str = "w"):
    """Unlinks the name, then creates the file exclusively without following symlinks."""
    _unlink(path)
    return os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600), mode)


def _open_regular(path: str):
    """Opens path for reading only if it is a regular file: no symlink is followed, and the type is checked on the
    descriptor, which is opened non-blocking. Errors from the open itself propagate unchanged."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    if not stat.S_ISREG(os.fstat(fd).st_mode):
        os.close(fd)
        raise OSError("not a regular file")
    return os.fdopen(fd, "rb")


def _read_answer(path: str) -> dict:
    """Opens only a regular file, without following symlinks, and refuses oversized files before loading them."""
    try:
        fh = _open_regular(path)
    except OSError:
        return {"status": "error", "reason": "ocr_failed", "detail": "answer is not a regular file"}
    with fh:
        if os.fstat(fh.fileno()).st_size > RESULT_MAX:
            return {"status": "error", "reason": "too_large", "detail": "answer too large"}
        try:
            return json.loads(fh.read(RESULT_MAX + 1))
        except (ValueError, RecursionError):
            return {"status": "error", "reason": "ocr_failed", "detail": "answer is not JSON"}


def _tmp_result(work: str, job_id: str) -> str:
    """Where the parser's child writes its result (and its budget sidecar) before the answer is published."""
    return os.path.join(work, "out", f"{job_id}.json.tmp")


def submit(work: str, job_id: str, src: str, request: dict, timeout: int, grace: int = 30) -> dict:
    """Copies src in, waits for the parser's answer. `timeout` is the base per-file budget the parser enforces; the wait
    follows whatever extension the parser's child announced. `grace` is how long past the budget the supervisor waits
    before declaring the parser unavailable."""
    _check_id(job_id)
    prepare(work)
    binp = os.path.join(work, "in", f"{job_id}.bin")
    req = os.path.join(work, "in", f"{job_id}.req.json")
    res = os.path.join(work, "out", f"{job_id}.json")
    tmp_res = _tmp_result(work, job_id)
    _unlink(res, res + ".final", res + ".err", tmp_res + ".extra", binp, req, req + ".tmp")   # ids repeat after a database reset: start clean
    try:
        with open(src, "rb") as fin, _create(binp, "wb") as fout:
            shutil.copyfileobj(fin, fout, 1024 * 1024)
        tmp = req + ".tmp"
        with _create(tmp) as fh:
            json.dump({**request, "input": binp, "timeout": timeout}, fh)
        os.replace(tmp, req)                       # the parser only sees complete requests
        start, budget = time.monotonic(), timeout
        while time.monotonic() < start + budget + grace:
            if os.path.lexists(res):
                return _read_answer(res)
            budget = max(budget, granted(timeout, tmp_res))      # only grows: a restart's purge does not shorten it
            time.sleep(0.1)
        raise ParserUnavailable(job_id)
    finally:
        _unlink(binp, req, res, res + ".final", res + ".err", req + ".tmp", tmp_res + ".extra")


def announce(res: str, seconds: int) -> None:
    """Child side: asks for `seconds` beyond the base budget, in a sidecar next to its result. Best effort: if the write
    fails (a full tmpfs) the file keeps its base budget and a timeout stays retryable."""
    try:
        with _create(res + ".extra") as fh:
            fh.write(str(int(seconds)))
    except OSError:
        pass


def granted(timeout: int, res: str) -> int:
    """The one budget rule: the base `timeout` plus the extra seconds the child announced next to `res`, at most
    MAX_TIMEOUT and never less than `timeout`. The sidecar is untrusted: anything but a regular file holding an integer
    grants nothing."""
    try:
        with _open_regular(res + ".extra") as fh:
            extra = int(fh.read(16))
    except (OSError, ValueError):
        return timeout
    return max(timeout, min(MAX_TIMEOUT, timeout + extra))


def _limits():
    resource.setrlimit(resource.RLIMIT_FSIZE, (CHILD_FSIZE, CHILD_FSIZE))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))


def run_child(cmd: list, req: str, res: str, timeout: int) -> dict:
    """Runs one child in its own session; when its budget runs out the whole group is killed. `timeout` is the base
    budget; the child may extend it through the sidecar (`announce`), and the extension is read every second."""
    errp = res + ".err"
    with _create(errp, "wb") as errf:
        p = subprocess.Popen(cmd + [req, res], start_new_session=True, stdin=subprocess.DEVNULL,
                             stdout=subprocess.DEVNULL, stderr=errf, preexec_fn=_limits)
    start, budget = time.monotonic(), timeout
    try:
        while True:
            budget = max(budget, granted(timeout, res))
            left = start + budget - time.monotonic()
            if left <= 0:
                os.killpg(p.pid, signal.SIGKILL)
                p.wait()
                return {"status": "error", "reason": "timeout"}
            try:
                p.wait(timeout=min(left, 1))
                break
            except subprocess.TimeoutExpired:
                pass
    finally:
        try:
            os.killpg(p.pid, signal.SIGKILL)   # stray grandchildren of a child that exited
        except ProcessLookupError:
            pass
    try:
        with _open_regular(res) as fh:
            return json.load(fh)
    except (OSError, ValueError, RecursionError):
        pass
    tail = ""
    if os.environ.get("BD_DEBUG_PARSER") == "1":
        try:
            with _open_regular(errp) as fh:
                fh.seek(0, os.SEEK_END)
                fh.seek(max(0, fh.tell() - ERR_TAIL))
                tail = fh.read(ERR_TAIL).decode("utf-8", "replace")      # may hold document text: debugging only (A-SEC-5)
        except OSError:
            pass
    return {"status": "error", "reason": "ocr_failed", "detail": tail or f"child exited {p.returncode}"}


def _answer(work: str, job_id: str, answer: dict) -> None:
    final = os.path.join(work, "out", f"{job_id}.json")
    with _create(final + ".final") as fh:
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
            tmp_res = _tmp_result(work, job_id)
            if not ID_RE.fullmatch(job_id):
                _unlink(req, binp)
                continue
            try:
                with _open_regular(req) as fh:
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
            _unlink(tmp_res, tmp_res + ".err", tmp_res + ".extra", req, binp)       # request files go first: nothing left when the answer is visible
            _answer(work, job_id, out)
            return True
        if time.monotonic() >= end:
            return False
        time.sleep(0.1)

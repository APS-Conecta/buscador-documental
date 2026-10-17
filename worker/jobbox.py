# SPDX-License-Identifier: AGPL-3.0-or-later
"""Supervisor <-> parser protocol over a shared directory (ADR-0011).

The supervisor copies the input to <work>/in/<id>.bin and writes <work>/in/<id>.req.json; the parser container runs
`serve_once` in a loop, starts one child per request in its own session, and writes <work>/out/<id>.json. The parser has
no network and no secrets; the files are the whole interface. The child gets (request path, result path) as argv."""
import json
import os
import re
import shutil
import signal
import subprocess
import sys
import time

ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,64}$")
DEFAULT_CHILD = [sys.executable, os.path.join(os.path.dirname(os.path.abspath(__file__)), "parse_job.py")]


class ParserUnavailable(Exception):
    """No result appeared in time: the parser container is down or stuck."""


def prepare(work: str) -> None:
    for d in ("in", "out"):
        os.makedirs(os.path.join(work, d), exist_ok=True)


def _check_id(job_id: str) -> str:
    if not ID_RE.match(job_id):
        raise ValueError("bad job id")
    return job_id


def submit(work: str, job_id: str, src: str, request: dict, timeout: int, grace: int = 30) -> dict:
    """Copies src in, waits for the parser's answer. `timeout` is the per-file budget the parser enforces; `grace` is how
    long past it the supervisor waits before declaring the parser unavailable."""
    _check_id(job_id)
    prepare(work)
    binp = os.path.join(work, "in", f"{job_id}.bin")
    req = os.path.join(work, "in", f"{job_id}.req.json")
    res = os.path.join(work, "out", f"{job_id}.json")
    for stale in (res, res + ".final", res + ".err"):
        try:
            os.unlink(stale)                   # ids repeat after a database reset: never read an old answer
        except FileNotFoundError:
            pass
    shutil.copyfile(src, binp)
    tmp = req + ".tmp"
    with open(tmp, "w") as fh:
        json.dump({**request, "input": binp, "timeout": timeout}, fh)
    os.replace(tmp, req)                       # the parser only sees complete requests
    deadline = time.monotonic() + timeout + grace
    try:
        while time.monotonic() < deadline:
            if os.path.exists(res):
                with open(res) as fh:
                    return json.load(fh)
            time.sleep(0.1)
        raise ParserUnavailable(job_id)
    finally:
        for p in (binp, req, res, req + ".tmp"):
            try:
                os.unlink(p)
            except FileNotFoundError:
                pass


def run_child(cmd: list, req: str, res: str, timeout: int) -> dict:
    """Runs one child in its own session; on timeout the whole group is killed."""
    errp = res + ".err"
    with open(errp, "wb") as errf:
        p = subprocess.Popen(cmd + [req, res], start_new_session=True, stdin=subprocess.DEVNULL,
                             stdout=subprocess.DEVNULL, stderr=errf)
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
    if os.path.exists(res):
        try:
            with open(res) as fh:
                return json.load(fh)
        except ValueError:
            pass
    with open(errp, "rb") as fh:
        tail = fh.read()[-400:].decode("utf-8", "replace")      # diagnostics for the supervisor's log, never for users
    return {"status": "error", "reason": "ocr_failed", "detail": tail or f"child exited {p.returncode}"}


def serve_once(work: str, child_cmd: list = None, wait: float = 1.0) -> bool:
    """Handles at most one pending request; returns whether one was handled."""
    prepare(work)
    end = time.monotonic() + wait
    while True:
        for name in sorted(os.listdir(os.path.join(work, "in"))):
            if name.endswith(".req.json"):
                job_id = name[: -len(".req.json")]
                req = os.path.join(work, "in", name)
                final = os.path.join(work, "out", f"{job_id}.json")
                with open(req) as fh:
                    timeout = int(json.load(fh).get("timeout", 60))
                tmp_res = final + ".tmp"
                out = run_child(child_cmd or DEFAULT_CHILD, req, tmp_res, timeout)
                with open(tmp_res + ".final", "w") as fh:
                    json.dump(out, fh)
                os.replace(tmp_res + ".final", final)
                for leftover in (tmp_res, tmp_res + ".err", req, req[: -len(".req.json")] + ".bin"):   # the parser cleans what it consumed
                    try:
                        os.unlink(leftover)
                    except FileNotFoundError:
                        pass
                return True
        if time.monotonic() >= end:
            return False
        time.sleep(0.1)

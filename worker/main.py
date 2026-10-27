# SPDX-License-Identifier: AGPL-3.0-or-later
"""Pull loop: claim_job -> meta -> content -> OCR -> placeholder chunker -> write_result.

The worker connects to Postgres as the bd_worker role, which can only execute claim_job, heartbeat, write_result and
fail_job. File bytes come from the app's worker channel, never from a path."""
import json
import logging
import math
import os
import re
import sys
import tempfile
import threading
import time
import traceback

import psycopg
import requests

import chunk
import client as bdclient
import embed
import jobbox

log = logging.getLogger("bd-worker")
WORK = os.environ.get("BD_WORK", "/work")
RETRYABLE = ("timeout", "ocr_failed")
KNOWN_REASONS = ("timeout", "ocr_failed", "unsafe", "corrupt", "encrypted", "too_large", "unsupported", "internal")   # parser reasons only
MAX_TEXT_CHARS = 60_000_000          # the write_result payload cap is 64 MB of page JSON
LEASE_SECONDS = int(os.environ.get("BD_LEASE_SECONDS", "900"))
MAX_BYTES = 250 * 1024 * 1024        # buscador/02-performance.md: proposed limits, confirmed in S2
MAX_PAGES = 300
SUPPORTED_MIMES = {
    "application/pdf", "text/plain", "text/markdown", "image/png", "image/jpeg", "image/tiff",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/vnd.oasis.opendocument.text"}
SILENT_DB_ERRORS = ("stale_lease", "etag_mismatch", "hash_mismatch", "file_skipped")


SUPPORTED_SCHEMA = 8           # aps_search.schema_meta.schema_ver this worker was written for


def check_schema(conn):
    """A worker on an unknown schema could write rows the app does not expect (or miss ones it must write): refuse to run."""
    try:
        found = conn.execute("SELECT aps_search.schema_version()").fetchone()[0]
    except psycopg.Error as e:
        sys.exit(f"cannot read the index schema version ({type(e).__name__}); expected {SUPPORTED_SCHEMA}")
    if found != SUPPORTED_SCHEMA:
        sys.exit(f"index schema version {found} is not supported by this worker (expected {SUPPORTED_SCHEMA})")


def env_or_file(name):
    """NAME, or the contents of the file named by NAME_FILE (a secret mounted as a file stays out of `docker inspect`)."""
    path = os.environ.get(name + "_FILE")
    if path:
        return open(path, encoding="utf-8").read().strip()
    return env(name)


def env(name, default=None):
    v = os.environ.get(name, default)
    if v is None:
        sys.exit(f"missing environment variable {name}")
    return v


class Heartbeat(threading.Thread):
    def __init__(self, dsn, job_id, epoch):
        super().__init__(daemon=True)
        self.dsn, self.job_id, self.epoch = dsn, job_id, epoch
        self.stop = threading.Event()
        self.lost = False

    def run(self):
        try:
            with psycopg.connect(self.dsn, autocommit=True) as c:
                while not self.stop.wait(LEASE_SECONDS / 3):
                    ok = c.execute("SELECT aps_search.heartbeat(%s, %s, %s)", (self.job_id, self.epoch, LEASE_SECONDS)).fetchone()[0]
                    if not ok:
                        self.lost = True
                        return
        except psycopg.Error:
            self.lost = True               # cannot extend the lease: stop trusting it


def timeout_for(size):
    """Base parse allowance: 120 s plus 20 s per whole MiB, at most MAX_TIMEOUT. It covers extraction, and it is the whole
    budget until the parser has counted its OCR'd pages, which extend it by pdfocr.OCR_PAGE_SECONDS each, capped at
    MAX_TIMEOUT (jobbox.granted)."""
    return min(jobbox.MAX_TIMEOUT, 120 + 20 * (int(size) // (1024 * 1024)))


def parse(job_id, epoch, path, size):
    """Hands the file to the parser container (ADR-0011) and returns its answer."""
    return jobbox.submit(WORK, f"{job_id}-{epoch}", path, {"kind": "file"}, timeout_for(size))


class BadAnswer(Exception):
    pass


def check_answer(a):
    """The parser is untrusted: the answer is checked for shape and range before anything is derived from it."""
    if not isinstance(a, dict) or a.get("status") not in ("ok", "error"):
        raise BadAnswer("shape")
    if a["status"] == "error":
        return a
    pages = a.get("pages")
    if not isinstance(pages, list) or not all(isinstance(p, str) for p in pages):
        raise BadAnswer("pages")
    conf = a.get("conf")
    if conf is not None and (not isinstance(conf, list) or len(conf) != len(pages) or
                             not all(c is None or (isinstance(c, (int, float)) and not isinstance(c, bool) and math.isfinite(c) and 0 <= c <= 100) for c in conf)):
        raise BadAnswer("conf")
    source = a.get("source")
    if source is not None and (not isinstance(source, list) or len(source) != len(pages) or not all(x in ("layer", "ocr") for x in source)):
        raise BadAnswer("source")
    if "ocr_ver" in a and (not isinstance(a["ocr_ver"], str) or len(a["ocr_ver"]) > 300):
        raise BadAnswer("ocr_ver")
    return a


def clean_detail(text):
    return re.sub(r"[^\x20-\x7e\u00a1-\u2027\u202a-\uffff]+", " ", str(text))[:300]


def process(conn, api, dsn, job_id, epoch, file_id):
    """Returns 'indexed', 'abandoned' or 'failed:<reason>'."""
    hb = Heartbeat(dsn, job_id, epoch)
    hb.start()
    try:
        meta = api.meta(file_id, job_id, epoch)
        if meta.get("encrypted"):
            return fail(conn, job_id, epoch, "encrypted", False)
        if meta.get("mime") not in SUPPORTED_MIMES:
            return fail(conn, job_id, epoch, "unsupported", False)
        if meta.get("size", 0) > MAX_BYTES:
            return fail(conn, job_id, epoch, "too_large", False)
        with tempfile.NamedTemporaryFile(suffix=".pdf") as tmp:
            content_hash, n = api.download(file_id, job_id, epoch, meta["etag"], tmp)
            tmp.flush()
            if n != meta.get("size"):
                log.warning("job %s: short or long body (%s of %s bytes)", job_id, n, meta.get("size"))
                return "abandoned"
            try:
                answer = parse(job_id, epoch, tmp.name, meta.get("size", 0))
            except jobbox.ParserUnavailable:
                log.warning("job %s: parser container did not answer, lease will expire", job_id)
                return "abandoned"
            try:
                answer = check_answer(answer)
            except BadAnswer as e:
                log.error("job %s: the parser returned a malformed answer (%s)", job_id, e)
                return fail(conn, job_id, epoch, "internal", False)
            if answer.get("status") != "ok":
                reason = answer.get("reason") if answer.get("reason") in KNOWN_REASONS else "ocr_failed"
                log.warning("job %s: parser refused the file (%s)", job_id, reason)      # the reason code, never its detail (A-SEC-5)
                if answer.get("detail") and os.environ.get("BD_DEBUG_PARSER") == "1":
                    log.warning("job %s: parser detail (debug only, may hold document text): %s", job_id, clean_detail(answer["detail"]))
                return fail(conn, job_id, epoch, reason, reason in RETRYABLE)
            pages = answer["pages"]
            if sum(len(p) for p in pages) > MAX_TEXT_CHARS:
                return fail(conn, job_id, epoch, "too_large", False)
        if hb.lost:
            return "abandoned"
        if len(pages) > MAX_PAGES:
            return fail(conn, job_id, epoch, "too_large", False)
        pages = [chunk.clean(t) for t in pages]
        try:
            chunks = chunk.split(pages)
        except chunk.TooManyChunks:
            return fail(conn, job_id, epoch, "too_large", False)
        confs = answer.get("conf") or [None] * len(pages)
        sources = answer.get("source") or ["ocr"] * len(pages)
        page_rows = [{"page_no": i, "text": t, "ocr_conf": confs[i - 1], "source": sources[i - 1]}
                     for i, t in enumerate(pages, start=1)]
        conn.execute("SELECT aps_search.write_result(%s, %s, %s, %s, %s::jsonb, %s::jsonb, %s, %s)",
                     (job_id, epoch, meta["etag"], content_hash, json.dumps(page_rows), json.dumps(chunks),
                      answer.get("ocr_ver", "unknown"), chunk.CHUNKER_VER))
        return "indexed"
    except OSError as e:
        log.warning("job %s: shared work volume error (%s), lease will expire", job_id, type(e).__name__)
        return "abandoned"
    except (requests.RequestException, ValueError) as e:
        log.warning("job %s: worker channel error (%s), lease will expire", job_id, type(e).__name__)
        return "abandoned"
    except (bdclient.ClaimLost, bdclient.EtagChanged):
        return "abandoned"                 # nothing to report: the app side already knows
    except bdclient.Retryable as e:
        log.warning("job %s: app busy (%s), lease will expire", job_id, e)
        return "abandoned"
    except bdclient.AuthError:
        # A wrong or rotated-out secret, or a clock off by more than the window: every further claim would burn an attempt of
        # a file for nothing (five of them skip it for good). Stop the worker; leases expire and the files are read later.
        log.error("job %s: worker channel rejected our credentials", job_id)
        raise
    except psycopg.errors.DataError as e:
        if "payload_too_large" in str(e):
            return fail(conn, job_id, epoch, "too_large", False)
        log.error("job %s: the database refused the extracted text (%s)", job_id, type(e).__name__)       # never its message
        return fail(conn, job_id, epoch, "corrupt", False)
    except psycopg.errors.Error as e:
        if any(tag in str(e) for tag in SILENT_DB_ERRORS):
            return "abandoned"
        if "payload_too_large" in str(e):
            return fail(conn, job_id, epoch, "too_large", False)
        raise
    except Exception:
        log.error("job %s: unexpected error (%s), lease will expire", job_id, where(sys.exc_info()))
        return "abandoned"                 # the attempts cap in claim_job bounds a deterministic bug
    finally:
        hb.stop.set()


def where(exc_info):
    """Exception class and the place it was raised: enough to debug, and no message (messages can hold document text)."""
    kind, _value, tb = exc_info
    frames = traceback.extract_tb(tb) if tb else []
    place = f"{os.path.basename(frames[-1].filename)}:{frames[-1].lineno}" if frames else "?"
    return f"{kind.__name__} at {place}"


def run_guarded():
    """Entry point: an uncaught error ends the worker with a one-line, message-free note instead of a traceback (a
    database error's message can carry the offending row, i.e. document text)."""
    try:
        main()
    except SystemExit:
        raise
    except bdclient.AuthError:
        print("bd-worker stopped: the app rejected the worker credentials (secret rotated out, or the clocks differ by more than 2 minutes); "
              "no more work is claimed until it is fixed", file=sys.stderr, flush=True)
        sys.exit(3)
    except Exception:
        print(f"bd-worker stopped: {where(sys.exc_info())}", file=sys.stderr, flush=True)
        sys.exit(1)


def fail(conn, job_id, epoch, reason, retryable):
    try:
        conn.execute("SELECT aps_search.fail_job(%s, %s, %s, %s)", (job_id, epoch, reason, retryable))
    except psycopg.errors.Error as e:
        if "stale_lease" not in str(e):
            raise
        return "abandoned"
    return f"failed:{reason}"


EMBED_BATCH = int(os.environ.get("BD_EMBED_BATCH", "16"))


def vec_process(conn, embedder, dsn, job_id, epoch, etag, batch=EMBED_BATCH):
    """Embeds the chunks of one claimed file into the job's generation. Returns 'embedded', 'abandoned' or 'failed:<reason>'.
    The vector lane never touches Nextcloud or the document bytes: the chunk text comes through vec_chunks."""
    hb = Heartbeat(dsn, job_id, epoch)
    hb.start()
    try:
        gen, model, digest, dim, _qprefix, passage_prefix, max_tokens = conn.execute(
            "SELECT gen, model, digest, dim, query_prefix, passage_prefix, max_tokens FROM aps_search.vec_profile(%s, %s)",
            (job_id, epoch)).fetchone()
        if embedder.digest(model) != digest:
            return fail(conn, job_id, epoch, "model_mismatch", False)     # the model behind the tag changed: vectors would mix
        rows = conn.execute("SELECT chunk_id, heading, text FROM aps_search.vec_chunks(%s, %s, %s)", (job_id, epoch, etag)).fetchall()
        if not rows:
            conn.execute("SELECT aps_search.write_vectors(%s, %s, %s, %s::bigint[], %s::text[])", (job_id, epoch, etag, [], []))
            return "embedded"
        complete = False
        for i in range(0, len(rows), batch):
            part = rows[i:i + batch]
            texts = [passage_prefix + (f"{h}\n" if h else "") + t for _id, h, t in part]
            vectors = embedder.embed(model, texts, max_tokens)
            if any(len(v) != dim for v in vectors):
                return fail(conn, job_id, epoch, "model_mismatch", False)
            if hb.lost:
                return "abandoned"
            complete = conn.execute(
                "SELECT aps_search.write_vectors(%s, %s, %s, %s::bigint[], %s::text[])",
                (job_id, epoch, etag, [r[0] for r in part], ["[" + ",".join(f"{x:.6g}" for x in v) + "]" for v in vectors])).fetchone()[0]
        return "embedded" if complete else "abandoned"
    except embed.EmbedError as e:
        log.warning("job %s: embedding endpoint (%s)", job_id, "retry later" if e.retryable else "refused")
        return fail(conn, job_id, epoch, "embed_failed", e.retryable)
    except psycopg.errors.Error as e:
        if any(tag in str(e) for tag in SILENT_DB_ERRORS + ("retired",)):
            return "abandoned"
        if "bad_vector" in str(e):
            return "abandoned"          # the chunk set changed under us (a reindex); the new job covers it, repeated failures end at the attempts cap
        raise
    except Exception:
        log.error("job %s: unexpected error (%s), lease will expire", job_id, where(sys.exc_info()))
        return "abandoned"
    finally:
        hb.stop.set()


def run_once(conn, api, dsn, worker_id, embedder=None):
    rows = conn.execute("SELECT job_id, lease_epoch, file_id, etag FROM aps_search.claim_job(%s, 1, %s, %s)",
                        ("text", worker_id, LEASE_SECONDS)).fetchall()
    for job_id, epoch, file_id, _etag in rows:
        log.info("job %s file %s: %s", job_id, file_id, process(conn, api, dsn, job_id, epoch, file_id))
    n = len(rows)
    if embedder is not None:
        vrows = conn.execute("SELECT job_id, lease_epoch, file_id, etag FROM aps_search.claim_job(%s, 1, %s, %s)",
                             ("vec", worker_id, LEASE_SECONDS)).fetchall()
        for job_id, epoch, file_id, etag in vrows:
            log.info("job %s file %s: %s", job_id, file_id, vec_process(conn, embedder, dsn, job_id, epoch, etag))
        n += len(vrows)
    return n


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s")
    dsn = env_or_file("BD_DB_DSN")
    worker_id = env("BD_WORKER_ID", "worker-1")
    api = bdclient.WorkerClient(env("BD_BASE_URL"), worker_id, env_or_file("BD_WORKER_SECRET"))
    once = "--once" in sys.argv
    embedder = None
    if os.environ.get("BD_EMBED_URL"):                  # no endpoint, no vector lane: a keyword-only install still works
        token_file = os.environ.get("BD_EMBED_TOKEN_FILE")
        token = open(token_file).read().strip() if token_file else None
        embedder = embed.Embedder(os.environ["BD_EMBED_URL"], token=token, batch=EMBED_BATCH)
    with psycopg.connect(dsn, autocommit=True) as conn:
        check_schema(conn)
        while True:
            n = run_once(conn, api, dsn, worker_id, embedder)
            if once:
                return
            if n == 0:
                time.sleep(float(os.environ.get("BD_POLL_SECONDS", "2")))


if __name__ == "__main__":
    run_guarded()

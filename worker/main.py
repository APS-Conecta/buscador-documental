# SPDX-License-Identifier: AGPL-3.0-or-later
"""Pull loop: claim_job -> meta -> content -> OCR -> placeholder chunker -> write_result.

The worker connects to Postgres as the bd_worker role, which can only execute claim_job, heartbeat, write_result and
fail_job. File bytes come from the app's worker channel, never from a path."""
import json
import logging
import os
import sys
import tempfile
import threading
import time

import psycopg

import chunk
import client as bdclient
import ocr

log = logging.getLogger("bd-worker")
LEASE_SECONDS = int(os.environ.get("BD_LEASE_SECONDS", "900"))
SILENT_DB_ERRORS = ("stale_lease", "etag_mismatch", "hash_mismatch", "file_skipped")


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
        with psycopg.connect(self.dsn, autocommit=True) as c:
            while not self.stop.wait(LEASE_SECONDS / 3):
                ok = c.execute("SELECT aps_search.heartbeat(%s, %s, %s)", (self.job_id, self.epoch, LEASE_SECONDS)).fetchone()[0]
                if not ok:
                    self.lost = True
                    return


def process(conn, api, dsn, job_id, epoch, file_id):
    """Returns 'indexed', 'abandoned' or 'failed:<reason>'."""
    hb = Heartbeat(dsn, job_id, epoch)
    hb.start()
    try:
        meta = api.meta(file_id, job_id, epoch)
        if meta.get("encrypted"):
            return fail(conn, job_id, epoch, "encrypted", False)
        if meta.get("mime") != "application/pdf":
            return fail(conn, job_id, epoch, "unsupported", False)   # STUB(S2): other formats
        with tempfile.NamedTemporaryFile(suffix=".pdf") as tmp:
            content_hash, _ = api.download(file_id, job_id, epoch, meta["etag"], tmp)
            tmp.flush()
            try:
                pages = ocr.run(tmp.name)
            except ocr.OcrTimeout:
                return fail(conn, job_id, epoch, "timeout", True)
            except ocr.OcrFailed:
                return fail(conn, job_id, epoch, "ocr_failed", True)
        if hb.lost:
            return "abandoned"
        chunks = chunk.split(pages)
        page_rows = [{"page_no": i, "text": t, "ocr_conf": None, "source": "ocr"} for i, t in enumerate(pages, start=1)]
        conn.execute("SELECT aps_search.write_result(%s, %s, %s, %s, %s::jsonb, %s::jsonb, %s, %s)",
                     (job_id, epoch, meta["etag"], content_hash, json.dumps(page_rows), json.dumps(chunks),
                      ocr.version(), chunk.CHUNKER_VER))
        return "indexed"
    except (bdclient.ClaimLost, bdclient.EtagChanged):
        return "abandoned"                 # nothing to report: the app side already knows
    except bdclient.Retryable as e:
        log.warning("job %s: app busy (%s), lease will expire", job_id, e)
        return "abandoned"
    except bdclient.AuthError:
        log.error("job %s: worker channel rejected our credentials", job_id)
        return "abandoned"
    except psycopg.errors.Error as e:
        if any(tag in str(e) for tag in SILENT_DB_ERRORS):
            return "abandoned"
        raise
    finally:
        hb.stop.set()


def fail(conn, job_id, epoch, reason, retryable):
    try:
        conn.execute("SELECT aps_search.fail_job(%s, %s, %s, %s)", (job_id, epoch, reason, retryable))
    except psycopg.errors.Error as e:
        if "stale_lease" not in str(e):
            raise
        return "abandoned"
    return f"failed:{reason}"


def run_once(conn, api, dsn, worker_id):
    rows = conn.execute("SELECT job_id, lease_epoch, file_id, etag FROM aps_search.claim_job(%s, 1, %s, %s)",
                        ("text", worker_id, LEASE_SECONDS)).fetchall()
    for job_id, epoch, file_id, _etag in rows:
        log.info("job %s file %s: %s", job_id, file_id, process(conn, api, dsn, job_id, epoch, file_id))
    return len(rows)


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s")
    dsn = env("BD_DB_DSN")
    worker_id = env("BD_WORKER_ID", "worker-1")
    api = bdclient.WorkerClient(env("BD_BASE_URL"), worker_id, env("BD_WORKER_SECRET"))
    once = "--once" in sys.argv
    with psycopg.connect(dsn, autocommit=True) as conn:
        while True:
            n = run_once(conn, api, dsn, worker_id)
            if once:
                return
            if n == 0:
                time.sleep(float(os.environ.get("BD_POLL_SECONDS", "2")))


if __name__ == "__main__":
    main()

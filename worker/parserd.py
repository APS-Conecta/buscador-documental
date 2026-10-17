# SPDX-License-Identifier: AGPL-3.0-or-later
"""Entry point of the `parser` container: no network, no secrets, serves requests from the shared work directory.
The loop never exits for a bad request or a transient error; the health file is refreshed on every pass."""
import os
import pathlib
import sys
import time

import jobbox

WORK = os.environ.get("BD_WORK", "/work")
READY = pathlib.Path("/tmp/ready")


def main():
    jobbox.purge(WORK)
    while True:
        READY.touch()                              # health check: fresh within the last minute
        try:
            jobbox.serve_once(WORK, wait=1.0)
        except Exception as e:                     # a full disk or a vanished directory must not end the parser
            print(f"parserd: {type(e).__name__}: {e}", file=sys.stderr, flush=True)
            time.sleep(1)


if __name__ == "__main__":
    main()

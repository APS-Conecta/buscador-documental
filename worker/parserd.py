# SPDX-License-Identifier: AGPL-3.0-or-later
"""Entry point of the `parser` container: no network, no secrets, serves requests from the shared work directory."""
import os
import pathlib

import jobbox

WORK = os.environ.get("BD_WORK", "/work")


def main():
    jobbox.prepare(WORK)
    pathlib.Path("/tmp/ready").touch()          # health check
    while True:
        jobbox.serve_once(WORK, wait=1.0)


if __name__ == "__main__":
    main()

"""Shared helpers for the S0 bench scripts: connection, host-load snapshot, JSON result writer."""
import json
import os
import subprocess
import time

import psycopg

DSN = os.environ.get("BD_DSN", "host=db dbname=nextcloud user=nextcloud password=dev")
RESULTS = os.environ.get("BD_RESULTS", "/work/results")


def connect(autocommit: bool = True) -> psycopg.Connection:
    return psycopg.connect(DSN, autocommit=autocommit)


def host_load() -> dict:
    """Load average and CPU pressure of the shared VPS at the moment the result is written (not sampled during the run)."""
    out = {"loadavg": list(os.getloadavg())}
    try:
        stats = subprocess.run(["cat", "/proc/pressure/cpu"], capture_output=True, text=True, timeout=5).stdout.strip()
        out["cpu_pressure"] = stats
    except Exception:
        pass
    return out


def write_result(name: str, data: dict) -> str:
    os.makedirs(RESULTS, exist_ok=True)
    data = {"measured_at": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "host": host_load(), **data}
    path = f"{RESULTS}/{name}.json"
    with open(path, "w") as fh:
        json.dump(data, fh, indent=1, sort_keys=True)
    return path

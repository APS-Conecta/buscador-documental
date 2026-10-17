#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Prints the headline figures of the reconciliation scale benchmark (spikes/s3/results-s3/reconcile_scale_*.json)."""
import glob
import json
import re
import sys


def execution_ms(lines):
    for line in lines:
        m = re.search(r"Execution Time: ([0-9.]+) ms", line)
        if m:
            return float(m.group(1))
    return None


def main(directory):
    for path in sorted(glob.glob(f"{directory}/reconcile_scale_*.json")):
        d = json.load(open(path))
        ex = {k: execution_ms(v) for k, v in d["explain"].items() if isinstance(v, list)}
        print(f"rows={d['loaded_files']} seconds={d['seconds_total']} steps={d['steps']} phases={d['seconds_by_phase']}")
        print(f"  counts: forward={d['counts']['forward']} reverse={d['counts']['reverse']} acl_marked={d['counts']['acl_marked']} eligible={d['counts']['eligible']}")
        print("  explain execution ms: " + ", ".join(f"{k}={v}" for k, v in ex.items() if v is not None))
        print(f"  load average before/after: {d['load_average_before']} / {d['load_average_after']}; postgres {d['postgres']}, shared_buffers {d['shared_buffers']}")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "spikes/s3/results-s3")

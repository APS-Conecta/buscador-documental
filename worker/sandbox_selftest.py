# SPDX-License-Identifier: AGPL-3.0-or-later
"""Run INSIDE the parser container (A-SAFE-2). Exits 0 only if every isolation property holds; prints one line each."""
import os
import socket
import subprocess
import sys

results = []


def check(name, ok):
    results.append(ok)
    print(("ok   " if ok else "FAIL ") + name)


check("no BD_* secrets in the environment except BD_WORK", not [k for k in os.environ if k.startswith("BD_") and k != "BD_WORK"])
try:
    socket.create_connection(("db", 5432), 2)
    check("cannot reach db:5432", False)
except OSError:
    check("cannot reach db:5432", True)
try:
    socket.create_connection(("1.1.1.1", 443), 2)
    check("no route to the internet", False)
except OSError:
    check("no route to the internet", True)
try:
    open("/usr/local/evil", "w")
    check("rootfs is read-only", False)
except OSError:
    check("rootfs is read-only", True)
check("not root", os.getuid() != 0)
check("no capabilities", "CapEff:\t0000000000000000" in open("/proc/self/status").read())
check("no_new_privs set", "NoNewPrivs:\t1" in open("/proc/self/status").read())
try:
    os.makedirs("/work/selftest", exist_ok=True)
    open("/work/selftest/x", "w").write("ok")
    check("work volume is writable", True)
except OSError:
    check("work volume is writable", False)
# bounded processes: a fork loop must hit the pids limit instead of running away
r = subprocess.run([sys.executable, "-c",
                    "import os,sys\nn=0\ntry:\n while n<5000:\n  pid=os.fork()\n  if pid==0:\n   import time; time.sleep(5); os._exit(0)\n  n+=1\nexcept OSError:\n print('limited',n); sys.exit(0)\nprint('unlimited',n)"],
                   capture_output=True, text=True, timeout=60)
check("pids limit stops a fork loop", "limited" in r.stdout and "unlimited" not in r.stdout)
sys.exit(0 if all(results) else 1)

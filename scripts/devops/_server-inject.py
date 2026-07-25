#!/usr/bin/env python3
"""Server-side secret injection — all values stay on server, never printed."""
import subprocess, sys, os

APP = sys.argv[1]
ENV_FILE = sys.argv[2]
KEYS = sys.argv[3:]

# Read env
env = {}
with open(ENV_FILE) as f:
    for line in f:
        line = line.strip()
        if line and not line.startswith('#') and '=' in line:
            k, v = line.split('=', 1)
            env[k.strip()] = v.strip().strip('"').strip("'")

ok = 0
fail = 0
for key in KEYS:
    val = env.get(key, '')
    if not val:
        print(f"FAIL {key}: not_found")
        fail += 1
        continue

    r = subprocess.run(
        ['dokku', 'config:set', '--no-restart', APP, f'{key}={val}'],
        capture_output=True, text=True, timeout=10
    )

    if r.returncode == 0:
        print(f"OK {key}")
        ok += 1
    else:
        # Print only error type, not the value
        err = r.stderr.strip()[:80]
        print(f"FAIL {key}: {err}")
        fail += 1

print(f"DONE ok={ok} fail={fail}")

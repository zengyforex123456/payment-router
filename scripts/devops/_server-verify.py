#!/usr/bin/env python3
"""Verify secrets are set on server — only prints OK/FAIL, never values."""
import subprocess, sys

APP = sys.argv[1]
KEYS = sys.argv[2:]

for key in KEYS:
    r = subprocess.run(
        ['dokku', 'config:get', APP, key],
        capture_output=True, text=True, timeout=5
    )
    val = r.stdout.strip()
    if val and r.returncode == 0:
        print(f"OK {key}")
    else:
        print(f"FAIL {key}")

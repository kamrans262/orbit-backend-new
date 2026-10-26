#!/usr/bin/env python3
"""Offline M9 release evidence validator. No secrets, network or deploy actions."""
import argparse
import json
from pathlib import Path
import sys

SCENARIOS = {
  "P01": "Unauthenticated heartbeat and Circle read rejected",
  "P02": "Disabled M9 or Circle Presence consumer returns no status",
  "P03": "Explicit opt-in is necessary and is revocable",
  "P04": "Global Ghost and Nobody always veto Circle permissions",
  "P05": "Two-way block immediately revokes private status",
  "P06": "Removed, restricted and expired Circle members denied",
  "P07": "Temporary consent expiry prevents future sharing",
  "P08": "90 second lease expiry never leaves a false online label",
  "P09": "Multiple devices active/idle/logout aggregate correctly",
  "P10": "Session and device revocation removes protected Presence",
  "P11": "Typing permission separate from Presence and expires in five seconds",
  "P12": "Typing is throttled and emits no user message contents",
  "P13": "Gateway ticket binds user, device, session and expires",
  "P14": "Every realtime event gets current viewer/subject authorization",
  "P15": "Reconnect/airplane mode/background/OS kill fail closed",
  "P16": "M8 Dashboard and M9 status do not leak cross-Circle presence",
  "P17": "Live Redis pubsub to WSS delivery and revocation verification",
  "P18": "Android physical phone lifecycle and background behavior",
  "P19": "iOS physical phone lifecycle and background behavior",
  "P20": "Hostinger/managed gateway staging with p95 and battery evidence",
}
GATES = (
    "backend_ci", "flutter_ci", "m8_integration", "redis_ws_gateway",
    "live_realtime_security", "manual_privacy", "android_native", "ios_native",
    "android_physical", "ios_physical", "hostinger_staging",
    "latency_battery", "product_approvals", "rollback_rehearsal"
)
ALLOWED = {"pending", "partial", "passed"}

def validate(data, release=False):
    errors = []
    if data.get("module") != "M9" or data.get("schema_version") != 1:
        errors.append("Invalid M9 evidence schema")
    scenarios = data.get("checks")
    if not isinstance(scenarios, list):
        return ["checks must be an array"]
    found = {}
    for x in scenarios:
        if not isinstance(x, dict):
            errors.append("Invalid scenario object")
            continue
        k = x.get("id")
        if k in found:
            errors.append(f"Duplicate scenario {k}")
        found[k] = x
        if x.get("description") != SCENARIOS.get(k):
            errors.append(f"Unexpected scenario description {k}")
        if x.get("status") not in ALLOWED or not isinstance(x.get("evidence"), list):
            errors.append(f"Invalid status/evidence for {k}")
    if set(found) != set(SCENARIOS):
        errors.append("Scenario inventory does not exactly match M9 release gates")
    gates = data.get("gates")
    if not isinstance(gates, dict):
        return errors + ["gates must be an object"]
    for k in GATES:
        g = gates.get(k)
        if not isinstance(g, dict) or g.get("status") not in ALLOWED or not isinstance(g.get("evidence"), list):
            errors.append(f"Invalid or missing gate {k}")
    if release:
        commit = data.get("release_commit_sha")
        if not isinstance(commit, str) or len(commit) != 40 or any(c not in "0123456789abcdef" for c in commit.lower()):
            errors.append("Exact 40-character release commit SHA required")
        for k in SCENARIOS:
            x = found.get(k) or {}
            if x.get("status") != "passed" or not x.get("evidence"):
                errors.append(f"Scenario {k} has no verified PASS evidence")
        for k in GATES:
            g = gates.get(k) or {}
            if g.get("status") != "passed" or not g.get("evidence"):
                errors.append(f"Release gate {k} is not verified")
        if data.get("status") != "RELEASE_APPROVED":
            errors.append("RELEASE_APPROVED status required")
    return errors

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("file", type=Path)
    mode = ap.add_mutually_exclusive_group(required=True)
    mode.add_argument("--lint", action="store_true")
    mode.add_argument("--release", action="store_true")
    opts = ap.parse_args()
    try:
        data = json.loads(opts.file.read_text(encoding="utf-8"))
    except (OSError, ValueError) as e:
        print(f"Invalid M9 evidence file: {e}", file=sys.stderr)
        return 2
    errs = validate(data, release=opts.release)
    if errs:
        for e in errs:
            print("BLOCKED: " + e, file=sys.stderr)
        return 1
    print("M9 evidence structurally valid." if opts.lint else "M9 release gates approved.")
    return 0

if __name__ == "__main__":
    sys.exit(main())

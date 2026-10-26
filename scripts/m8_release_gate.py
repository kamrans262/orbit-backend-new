#!/usr/bin/env python3
"""M8 release evidence validator. No deployment, credentials, or DB access.

--lint validates handoff structure. --release refuses release until
independently evidenced acceptance, decisions, and device/staging gates exist.
"""
from __future__ import annotations
import argparse
import json
import pathlib
import sys

SCENARIOS = {f"ACC-{i:02d}" for i in range(1, 27)}
REQUIRED_GATES = (
    "m8_laravel_ci", "m8_flutter_ci", "m4_m7_regressions",
    "android_physical", "ios_physical", "hostinger_staging",
    "privacy_revocation", "warm_start_performance", "api_p95_performance",
    "rollback_rehearsal", "security_review", "product_decisions",
)
DEPENDENT = {"ACC-14", "ACC-19", "ACC-20"}
VALID_STATUS = {"pending", "partial", "passed", "deferred"}

def check(doc: dict, require_release: bool) -> list[str]:
    errors = []
    checks = doc.get("checks")
    if not isinstance(checks, list):
        return ["checks must be an array"]
    indexed = {}
    for item in checks:
        if not isinstance(item, dict) or not isinstance(item.get("id"), str):
            errors.append("Each check needs an id")
            continue
        key = item["id"]
        if key in indexed:
            errors.append(f"Duplicate check {key}")
        indexed[key] = item
        if item.get("status") not in VALID_STATUS:
            errors.append(f"Unsupported status for {key}")
        if not isinstance(item.get("evidence"), list):
            errors.append(f"Evidence for {key} must be an array")
        if item.get("status") == "deferred" and key not in DEPENDENT:
            errors.append(f"{key}: deferral is not permitted for this scenario")
    errors.extend(f"Missing {key}" for key in sorted(SCENARIOS - indexed.keys()))
    errors.extend(f"Unexpected {key}" for key in sorted(indexed.keys() - SCENARIOS))
    gates = doc.get("gates")
    if not isinstance(gates, dict):
        errors.append("gates must be an object")
        gates = {}
    for key in REQUIRED_GATES:
        if key not in gates or not isinstance(gates[key], dict):
            errors.append(f"Missing gate {key}")
            continue
        if gates[key].get("status") not in VALID_STATUS:
            errors.append(f"Invalid gate status {key}")
    required = doc.get("p0_required")
    completed = doc.get("p0_evidenced")
    if required != 90 or not isinstance(completed, int) or not (0 <= completed <= 90):
        errors.append("P0 evidence counters must be 90 required and 0..90 evidenced")
    if require_release:
        if not doc.get("release_commit_sha") or len(doc["release_commit_sha"]) != 40:
            errors.append("Exact release commit SHA is required")
        if completed != 90:
            errors.append(f"Only {completed}/90 P0 requirements have evidence")
        for key in sorted(SCENARIOS):
            item = indexed.get(key)
            if not item:
                continue
            st = item.get("status")
            evidence = item.get("evidence")
            if st == "passed" and evidence:
                continue
            if st == "deferred" and key in DEPENDENT and evidence \
                    and item.get("approved_by") and item.get("approval_ref"):
                continue
            errors.append(f"{key} not release-approved (status: {st})")
        for key in REQUIRED_GATES:
            gate = gates.get(key) or {}
            if gate.get("status") != "passed" or not gate.get("evidence"):
                errors.append(f"Release gate not passed: {key}")
    return errors

def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("evidence", type=pathlib.Path)
    group = p.add_mutually_exclusive_group(required=True)
    group.add_argument("--lint", action="store_true")
    group.add_argument("--release", action="store_true")
    args = p.parse_args()
    try:
        doc = json.loads(args.evidence.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        print(f"Invalid release evidence: {exc}", file=sys.stderr)
        return 2
    if not isinstance(doc, dict):
        print("Evidence must be a JSON object", file=sys.stderr)
        return 2
    errors = check(doc, require_release=args.release)
    if errors:
        for error in errors:
            print("BLOCKED: " + error, file=sys.stderr)
        return 1
    print("M8 evidence schema valid." if args.lint else "M8 release evidence gates passed.")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())

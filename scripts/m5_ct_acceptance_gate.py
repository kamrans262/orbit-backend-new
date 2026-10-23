#!/usr/bin/env python3
"""M5 CT-01–CT-40 manual evidence gate. Deny release by default."""
import csv
import re
import sys
from pathlib import Path

REQUIRED = {f"CT-{i:02d}" for i in range(1, 41)}
FIELDS = {"id", "result", "evidence", "tester", "tested_at"}


def validate(path: Path) -> tuple[bool, list[str]]:
    if not path.exists() or not path.is_file():
        return False, [f"Evidence CSV not found: {path}"]
    with path.open(newline="", encoding="utf-8-sig") as file:
        reader = csv.DictReader(file)
        if reader.fieldnames is None or not FIELDS.issubset(reader.fieldnames):
            return False, [f"Missing required CSV columns: {', '.join(sorted(FIELDS))}"]
        rows = list(reader)
    seen = set()
    failures = []
    for row in rows:
        key = (row.get("id") or "").strip()
        if key not in REQUIRED or key in seen:
            failures.append(f"Unexpected or duplicate CT ID: {key}")
        seen.add(key)
        if row.get("result", "").strip().upper() != "PASS":
            failures.append(f"{key}: not verified PASS")
        if len(row.get("evidence", "").strip()) < 12:
            failures.append(f"{key}: missing usable evidence reference")
        if len(row.get("tester", "").strip()) < 3:
            failures.append(f"{key}: reviewer missing")
        when = row.get("tested_at", "").strip()
        if not re.fullmatch(r"\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?([+-]\d{2}:\d{2}|Z)?)?", when):
            failures.append(f"{key}: tested_at must include an actual test date")
    failures.extend(f"Missing case: {key}" for key in sorted(REQUIRED - seen))
    return not failures, failures


if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("usage: python3 scripts/m5_ct_acceptance_gate.py <evidence.csv>", file=sys.stderr)
        sys.exit(2)
    passed, errors = validate(Path(sys.argv[1]))
    if not passed:
        print("M5 RELEASE BLOCKED — incomplete CT-01…CT-40 evidence:", file=sys.stderr)
        for issue in errors[:60]:
            print(f"  - {issue}", file=sys.stderr)
        sys.exit(1)
    print("CT-01…CT-40 evidence format complete. Human verification and signed builds still required.")

#!/usr/bin/env python3
"""Fail-closed M7 release gate: all original Stage 1 acceptance IDs require evidence."""
import argparse
import json
import re
import sys
from pathlib import Path

AREAS = [("Audience",14),("Camera and composer",10),("Media security",12),
         ("Feed/viewer",12),("Interactions and moderation",10),
         ("Admin",8),("Performance/Release",8)]
EXTERNAL = ["android_physical_device","ios_physical_device","hostinger_private_storage",
            "retention_and_physical_purge","m3_to_m6_full_regression",
            "approved_orbit_ui_accessibility","encryption_and_privacy_review",
            "release_owner_approval"]
STATUS = {"NOT_RUN","BLOCKED","FAIL","PASS"}
expected_areas = [area for area, count in AREAS for _ in range(count)]

def valid_url(value):
    return isinstance(value,str) and bool(re.fullmatch(r"https://[^\s/]+\S*",value))

def validate(data):
    errors=[]
    if data.get("schema_version")!=1 or data.get("milestone")!="Orbit M7":
        errors.append("Invalid M7 evidence schema")
    b=data.get("baseline",{})
    if [b.get(k) for k in ("requirements","acceptance_scenarios","decisions")]!=[201,74,26]:
        errors.append("Stage 1 baseline counts altered")
    rows=data.get("acceptance",[])
    if len(rows)!=74: errors.append("Exactly 74 acceptance rows required")
    for n in range(1,75):
        if n>len(rows): break
        row=rows[n-1]
        if (row.get("id")!=f"M7-AT-{n:03d}"
            or row.get("area")!=expected_areas[n-1]
            or row.get("status") not in STATUS):
            errors.append(f"Wrong case/area/status at {n}")
    decisions=data.get("decisions",[])
    if len(decisions)!=26:errors.append("Exactly 26 decisions required")
    for n,d in enumerate(decisions,1):
        if d.get("id")!=f"D{n:02d}" or d.get("status") not in {"OPEN","APPROVED","REJECTED"}:
            errors.append(f"Invalid decision {n}")
    external=data.get("external",[])
    if [x.get("id") for x in external]!=EXTERNAL:
        errors.append("Eight external gates missing or in wrong order")
    if any(x.get("status") not in STATUS for x in external):
        errors.append("Invalid external gate status")
    if set(data.get("automation",{}))!={"flutter","backend"}:
        errors.append("Both Flutter and Laravel automation evidence required")
    return errors

def missing_for_release(data):
    missing=[]
    if data.get("release_status")!="APPROVED": missing.append("Owner release approval")
    for row in data["acceptance"]:
        if (row.get("status")!="PASS"
            or not all(row.get(k) for k in ("environment","tester","tested_at"))
            or not valid_url(row.get("evidence_url"))):
            missing.append(row["id"])
    for d in data["decisions"]:
        if (d.get("status")!="APPROVED"
            or not d.get("approver") or not d.get("approved_at")
            or not valid_url(d.get("decision_reference"))):
            missing.append(d["id"])
    for x in data["external"]:
        if (x.get("status")!="PASS"
            or not all(x.get(k) for k in ("environment","tester","tested_at"))
            or not valid_url(x.get("evidence_url"))):
            missing.append(x["id"])
    for name in ("flutter","backend"):
        x=data["automation"].get(name,{})
        if (x.get("status")!="PASS"
            or not re.fullmatch(r"[0-9a-f]{40}",x.get("commit",""))
            or not valid_url(x.get("run_url"))
            or not valid_url(x.get("evidence"))):
            missing.append(name+" validated CI")
    return missing

def main():
    p=argparse.ArgumentParser()
    p.add_argument("manifest",type=Path)
    choice=p.add_mutually_exclusive_group(required=True)
    choice.add_argument("--lint",action="store_true")
    choice.add_argument("--release",action="store_true")
    a=p.parse_args()
    try:data=json.loads(a.manifest.read_text(encoding="utf-8"))
    except (OSError,ValueError) as e:
        print("Invalid manifest:",e,file=sys.stderr);return 2
    problems=validate(data)
    if problems:
        print("Invalid M7 checklist:", "; ".join(problems[:15]),file=sys.stderr)
        return 2
    if a.lint:
        print("PASS: M7 manifest structure covers 74 scenarios, 26 decisions, 8 external gates")
        print("This is NOT release authorization.")
        return 0
    missing=missing_for_release(data)
    if missing:
        print(f"RELEASE BLOCKED: {len(missing)} gates without verified PASS and evidence")
        print("; ".join(missing[:20]))
        return 1
    print("M7 acceptance evidence checklist complete; independent deployment approval still required.")
    return 0
if __name__=="__main__":
    raise SystemExit(main())

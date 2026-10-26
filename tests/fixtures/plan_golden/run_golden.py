#!/usr/bin/env python3
"""Golden test for single-call staffing plans (BRIEF-staffing-plans-b §2.2).

Each case in cases/ holds a stubbed Finder assessment, a get-booking.php
booking and a list-crew-experience.php reply, all invented (this repo is
public: no real names or phones). The network is stubbed; build_staffing_plan
runs for real.

    python3 tests/fixtures/plan_golden/run_golden.py --write   # record golden/
    python3 tests/fixtures/plan_golden/run_golden.py           # compare to golden/

The only differences allowed against golden/ are plan.places_breakdown, added
in stage B (§2.5), and plan.requirements, added in stage C (B1), and only in a
golden file recorded before them. Anything else is printed as a diff and exits 1.

mix80 has Notes "Forklift needed at dock", which stage C applies (C1), so its
golden was re-recorded for stage C, requirements included; mix80_no_notes is the
same case with the Notes cleared, held to the stage B golden. The Call Type read is stubbed as "no types
stored", so a case's requirements come from its Notes and plan spec alone.
"""
import difflib
import glob
import json
import os
import sys
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.abspath(os.path.join(HERE, "..", "..", "..")))
import app as A                                     # noqa: E402

ALLOWED_NEW = {"places_breakdown", "requirements"}


def run_case(path):
    with open(path) as f:
        fx = json.load(f)
    with mock.patch.object(A, "_finder_assess", lambda ss, d: (json.loads(json.dumps(fx["assessment"])), None)), \
         mock.patch.object(A, "fetch_booking_bulk", lambda ss, bid: (json.loads(json.dumps(fx["booking"])), None)), \
         mock.patch.object(A, "ss_list_crew_experience", lambda ss, ids, cid, force=False: (fx["experience"], None)), \
         mock.patch.object(A, "load_licence_catalogue", lambda ss=None: None), \
         mock.patch.object(A, "_plan_call_types", lambda bid, calls: ({}, None)):
        result, err = A.build_staffing_plan(object(), json.loads(json.dumps(fx["body"])))
    if err:
        return {"error": list(err)}
    return {"plan": result["plan"]}


def dump(o):
    return json.dumps(o, indent=1, sort_keys=True, ensure_ascii=False) + "\n"


def main():
    write = "--write" in sys.argv
    gold_dir = os.path.join(HERE, "golden")
    os.makedirs(gold_dir, exist_ok=True)
    bad = 0
    for path in sorted(glob.glob(os.path.join(HERE, "cases", "*.json"))):
        name = os.path.basename(path)
        out = run_case(path)
        gpath = os.path.join(gold_dir, name)
        if write:
            with open(gpath, "w") as f:
                f.write(dump(out))
            print("wrote", gpath)
            continue
        with open(gpath) as f:
            gold = json.load(f)
        added = sorted(set(out.get("plan", {})) - set(gold.get("plan", {})))
        cmp_out = dict(out)
        if "plan" in out:
            # An allowed key is skipped only where the golden file predates it;
            # a golden recorded with it (mix80, stage C) compares it too.
            have = set(gold.get("plan", {}))
            cmp_out["plan"] = {k: v for k, v in out["plan"].items() if k not in ALLOWED_NEW or k in have}
        if dump(cmp_out) != dump(gold):
            bad += 1
            print("FAIL", name)
            sys.stdout.writelines(difflib.unified_diff(
                dump(gold).splitlines(True), dump(cmp_out).splitlines(True), "golden", "now"))
        else:
            extra = ", ".join("%s=%s" % (k, json.dumps(out["plan"][k])) for k in added)
            print("ok  ", name, ("(new: " + extra + ")") if extra else "")
    sys.exit(1 if bad else 0)


if __name__ == "__main__":
    main()

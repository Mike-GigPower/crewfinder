#!/usr/bin/env python3
"""Tests for /api/ops/push-gaps — the Ops 'Shadowlands' lane.

Drives the REAL route through a Flask test client with the four inputs faked, so
what is under test is the part that is ours: the bucketing, the counts, the four
identities the card depends on, and both soft-fail paths. The HTTP is
SmartStaff's and is not re-tested here (smoke_endpoints.py covers the wire).

Run (no network and no login required):
    python3 test_ops_push_gaps.py

Fixtures are chosen to catch the things that actually break:
  • status emitted as the JSON string "5"      (get-shifts-bulk really does this)
  • a calendars row for an OFFERED assignment  (must NOT count as a booked call)
  • an open offer that falls EARLIER than the same person's confirmed call
    (the row's date, lead bucket and drill-down must all follow the offer)
  • an offer to someone who HAS push            (counts in the total, not the gap)
  • an offer to someone off the active roster   (counts in the total, never listed)
  • an orphan user_id 0                         (must be dropped)
  • a crew member with an empty EIN             (must not silently match push)
"""
import sys, json
from datetime import datetime, timedelta

import app as A

now = datetime.now()
def iso(d): return (now + d).strftime("%Y-%m-%dT%H:%M:%S")

ROSTER = [
    {"id":"1","manage_id":"1","ein":"1001","name":"Able, Ann",  "phone":"0400 000 001"},
    {"id":"2","manage_id":"2","ein":"1002","name":"Book, Ben",  "phone":"0400 000 002"},
    {"id":"3","manage_id":"3","ein":"1003","name":"Cope, Cara", "phone":"0400 000 003"},
    {"id":"4","manage_id":"4","ein":"1004","name":"Dunn, Dave", "phone":""},
    {"id":"5","manage_id":"5","ein":"1005","name":"Ends, Eve",  "phone":"0400 000 005"},
    {"id":"6","manage_id":"6","ein":"1006","name":"Fair, Finn", "phone":"0400 000 006"},
    {"id":"7","manage_id":"7","ein":"",    "name":"Gray, Gus",  "phone":"0400 000 007"},
]
REACHABLE = {"1001"}          # only Ann has push

OFFERS = {"counts": {"total": 4}, "offers": [
    {"user_id":1,"ein":"1001","name":"Able, Ann", "start":iso(timedelta(days=6)),  "call_id":40,"booking_id":90,"call_name":"Bump in","booking_name":"Show A","venue":"Rod Laver"},
    {"user_id":3,"ein":"1003","name":"Cope, Cara","start":iso(timedelta(hours=36)),"call_id":30,"booking_id":93,"call_name":"Prelim","booking_name":"Show C","venue":"MCEC"},
    {"user_id":6,"ein":"1006","name":"Fair, Finn","start":iso(timedelta(days=5)),  "call_id":20,"booking_id":96,"call_name":"Bump in","booking_name":"Show D","venue":"Crown"},
    {"user_id":99,"ein":"9999","name":"Gone, Greg","start":iso(timedelta(days=7)), "call_id":50,"booking_id":99,"call_name":"Bump in","booking_name":"Show F","venue":"Crown"},
]}

SHIFTS = {"shifts": [
    {"user_id":1,"status":5,  "start":iso(timedelta(hours=6)),  "call_id":11,"booking_id":91,"call_name":"Bump in","booking_name":"Show A","venue":"Rod Laver"},
    {"user_id":2,"status":"5","start":iso(timedelta(hours=12)), "call_id":12,"booking_id":92,"call_name":"Bump in","booking_name":"Show B","venue":"AAMI Park"},
    {"user_id":3,"status":5,  "start":iso(timedelta(days=9)),   "call_id":14,"booking_id":94,"call_name":"Bump out","booking_name":"Show C","venue":"MCEC"},
    {"user_id":3,"status":5,  "start":iso(timedelta(days=3)),   "call_id":13,"booking_id":93,"call_name":"Bump in","booking_name":"Show C","venue":"MCEC"},
    {"user_id":4,"status":5,  "start":iso(timedelta(days=20)),  "call_id":15,"booking_id":95,"call_name":"Day 1","booking_name":"Fest","venue":"Sidney Myer"},
    {"user_id":4,"status":5,  "start":iso(timedelta(days=21)),  "call_id":16,"booking_id":95,"call_name":"Day 2","booking_name":"Fest","venue":"Sidney Myer"},
    {"user_id":4,"status":5,  "start":iso(timedelta(days=22)),  "call_id":17,"booking_id":95,"call_name":"Day 3","booking_name":"Fest","venue":"Sidney Myer"},
    {"user_id":4,"status":5,  "start":iso(timedelta(days=23)),  "call_id":18,"booking_id":95,"call_name":"Day 4","booking_name":"Fest","venue":"Sidney Myer"},
    {"user_id":4,"status":5,  "start":iso(timedelta(days=24)),  "call_id":19,"booking_id":95,"call_name":"Day 5","booking_name":"Fest","venue":"Sidney Myer"},
    # Finn's calendars row exists but he is only OFFERED — not a booked call
    {"user_id":6,"status":1,  "start":iso(timedelta(days=5)),   "call_id":20,"booking_id":96,"call_name":"Bump in","booking_name":"Show D","venue":"Crown"},
    {"user_id":7,"status":5,  "start":iso(timedelta(days=4)),   "call_id":21,"booking_id":97,"call_name":"Bump in","booking_name":"Show E","venue":"John Cain"},
    {"user_id":0,"status":5,  "start":iso(timedelta(days=1)),   "call_id":22,"booking_id":98,"call_name":"Ghost","booking_name":"","venue":""},
], "unavails": []}

class FakeResp:
    status_code = 200
    def __init__(self, payload): self.text = json.dumps(payload)
class FakeSS:
    def get(self, url, **kw):
        assert "get-shifts-bulk.php" in url, url
        return FakeResp(SHIFTS)

A.get_ss_session          = lambda: FakeSS()
A._get_all_crew           = lambda ss: ROSTER
A.gp_fetch_push_reachable = lambda: set(REACHABLE)
A.fetch_open_offers_bulk  = lambda ss, start, end: (OFFERS, None)
A._ss_sessions["h"] = FakeSS()
A._ss_identity["h"] = {"cohort": "admin", "usergroupID": 1}

fails = []
def check(label, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + label + ((" — " + str(detail)) if detail else ""))
    if not cond: fails.append(label)

def call():
    with A.app.test_client() as c:
        with c.session_transaction() as s: s["sid"] = "h"
        r = c.get("/api/ops/push-gaps")
        return r.status_code, r.get_json()

code, d = call()
print("HTTP", code)
if code != 200: print(d); sys.exit(2)

print(json.dumps({"coverage": d["coverage"], "counts": d["counts"]}, indent=2))
cov, cnt, rows = d["coverage"], d["counts"], d["rows"]
by = {r["name"]: r for r in rows}

check("active == with_push + without_push", cov["active"] == cov["with_push"] + cov["without_push"], cov)
check("active == 7", cov["active"] == 7, cov["active"])
check("with_push == 1", cov["with_push"] == 1, cov["with_push"])
check("empty EIN is NOT treated as reachable", "Gray, Gus" in by)
check("with_work == counts.total == len(rows) == 5",
      cov["with_work"] == cnt["total"] == len(rows) == 5, f"{cov['with_work']}/{cnt['total']}/{len(rows)}")
check("without_push == with_work + no_work", cov["without_push"] == cov["with_work"] + cov["no_work"], cov)
check("no-push, nothing-on crew counted but not listed", "Ends, Eve" not in by)
check("orphan user_id 0 dropped", all(r["user_id"] != "0" for r in rows))
check('status "5" as a string still counts', by["Book, Ben"]["call_count"] == 1)

check("offers_total is the endpoint's own total (4)", cov["offers_total"] == 4, cov["offers_total"])
check("offers_no_push counts only no-push ACTIVE crew (Cara + Finn = 2)",
      cov["offers_no_push"] == 2, cov["offers_no_push"])
check("an offer to someone WITH push is not in the gap", "Able, Ann" not in by)
check("an offer to someone off the roster is not listed", "Gone, Greg" not in by)

check("sum(lead) == total", sum(cnt["lead"].values()) == cnt["total"], cnt["lead"])
check("sum(exposure) == total", sum(cnt["exposure"].values()) == cnt["total"], cnt["exposure"])

check("Ben: confirmed only -> booked", by["Book, Ben"]["exposure"] == "booked")
check("Cara: offer + confirmed -> both", by["Cope, Cara"]["exposure"] == "both")
check("Finn: offer only (his calendars row is status 1) -> offer",
      by["Fair, Finn"]["exposure"] == "offer" and by["Fair, Finn"]["call_count"] == 0,
      by["Fair, Finn"])
check("Cara's next commitment is her OFFER, not her first confirmed call",
      by["Cope, Cara"]["call_id"] == 30, by["Cope, Cara"]["call_id"])
check("...so Cara's lead bucket follows the offer (36h -> under48)",
      by["Cope, Cara"]["lead_bucket"] == "under48", by["Cope, Cara"]["lead_bucket"])
check("Cara still shows both tallies (1 offer, 2 calls)",
      by["Cope, Cara"]["offer_count"] == 1 and by["Cope, Cara"]["call_count"] == 2, by["Cope, Cara"])
check("Dave: 5 calls, 20 days out -> booked/over168",
      by["Dunn, Dave"]["exposure"] == "booked" and by["Dunn, Dave"]["lead_bucket"] == "over168"
      and by["Dunn, Dave"]["call_count"] == 5)
check("rows sorted soonest-commitment-first",
      [r["name"] for r in rows] == ["Book, Ben","Cope, Cara","Gray, Gus","Fair, Finn","Dunn, Dave"],
      [r["name"] for r in rows])
check("row carries booking_id + call_id for the drill-down",
      all(r.get("booking_id") and r.get("call_id") for r in rows))
check("blank phone survives as empty string, not None", by["Dunn, Dave"]["phone"] == "")
check("window is today -> today+28",
      (datetime.strptime(d["window"]["end"], "%Y-%m-%d") -
       datetime.strptime(d["window"]["start"], "%Y-%m-%d")).days == 28, d["window"])

# The two soft-fail paths that must never be read as "nobody is reachable"
A.gp_fetch_push_reachable = lambda: None
_, d2 = call()
check("reachability unknown soft-fails the lane", d2.get("unavailable") is True, d2)
check("...and reports nothing as a count", "counts" not in d2)

A.gp_fetch_push_reachable = lambda: set(REACHABLE)
A.fetch_open_offers_bulk  = lambda ss, start, end: (None, "HTTP 500")
_, d3 = call()
check("offers feed failing soft-fails the lane", d3.get("unavailable") is True, d3)

print()
open("/sessions/rcw-018zmz4zdtjn3xruqrelqnqc/_fixture_payload.json","w").write(json.dumps(d))
if fails: print(f"FAILED: {len(fails)} check(s): {fails}"); sys.exit(1)
print("ALL CHECKS PASSED"); sys.exit(0)

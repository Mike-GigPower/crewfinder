#!/usr/bin/env python3
"""Tests for the fed-call over-check (DESIGN-downstream-over-warning-v0_2).

Drives the REAL routes through a Flask test client with SmartStaff faked:
  POST /api/goat/add-crew, /api/goat/send-sms   -> 409 {needs_confirm, warnings}
  POST /api/call/<b>/<c>/crew/<u>/status        -> 409 on a Promote (7 -> 5)
  GET  /api/call/<b>/<c>/callout                -> over_warnings in the preview
  GET  /api/call/<b>/<c>/crew/<u>/linked-rows   -> rows for the ✕ prompt

Run (no network and no login required):
    python3 test_over_warning.py

Booking 100, all edges locked unless noted:
  1 Audio load in  5 req, 4 held  ──┐
  2 LX load in    10 req,10 held  ──┴─▶ 3 Load out 20 req, 20 held (FULL)
  4 Bump in  8 req ─locked─▶ 5 Show 8/8 (full)
                   ─recommended─▶ 6 Bump out 12/12 (full, must NOT warn)
  7 ⇄ 8   migrated symmetric pair, 8 full (cycle: all-or-nothing, no warn)
  9 full load-in ─▶ 10 full load out (peel: standby on both, no warn)
  12 ─▶ 11 load out with room (no warn)
  13 ─▶ 14 cancelled and "over" (no warn)
  15 ─▶ 16 ─▶ 17 chain, for the remove prompt
"""
import sys

import app as A


def call(cid, name, req, held, feeds=(), rec=(), crew=(), cancelled=None, confirmed=None):
    return {
        "call_id": cid, "call_name": name, "required": req,
        "committed": held, "confirmed": held if confirmed is None else confirmed,
        "feeds": list(feeds), "feeds_recommended": list(rec),
        "cancelled_at": cancelled, "start_date": 1760000000, "start_time": "23:00:00",
        "crew": [{"id": u, "status": st} for (u, st) in crew],
    }


BOOKING = {"name": "Test Show", "venue": {"name": "Rod Laver"}, "calls": [
    call(1, "Audio load in", 5, 4, feeds=[3],
         crew=[(60, "backup"), (61, "backup")]),
    call(2, "LX load in", 10, 10, feeds=[3]),
    call(3, "Load out", 20, 20,
         crew=[(9, "confirmed"), (77, "confirmed"), (60, "backup"), (88, "declined")]),
    call(4, "Bump in", 8, 2, feeds=[5, 6], rec=[6]),
    call(5, "Show", 8, 8),
    call(6, "Bump out", 12, 12),
    call(7, "Pair A", 4, 3, feeds=[8], crew=[(70, "confirmed")]),
    call(8, "Pair B", 4, 4, feeds=[7], crew=[(70, "confirmed")]),
    call(9, "Full load in", 5, 5, feeds=[10]),
    call(10, "Its load out", 10, 10),
    call(11, "Roomy load out", 20, 10),
    call(12, "Feeder", 5, 0, feeds=[11]),
    call(13, "Cancel feeder", 5, 0, feeds=[14]),
    call(14, "CANCELLED - Gone", 2, 5, cancelled=1759000000),
    call(15, "Chain A", 5, 1, feeds=[16], crew=[(50, "confirmed")]),
    call(16, "Chain B", 5, 1, feeds=[17], crew=[(50, "sent")]),
    call(17, "Chain C", 5, 1, crew=[(50, "backup"), (51, "declined")]),
]}

A.fetch_booking_bulk = lambda ss, bid: (BOOKING, None)

writes = []      # every SmartStaff write the routes attempted


class FakeResp:
    status_code = 200
    text = ""
    def json(self): return {}


class FakeSS:
    def get(self, url, **kw):
        writes.append(url)
        return FakeResp()


A.get_ss_session = lambda: FakeSS()
A.gp_notify_offer = lambda *a, **k: None
A.gp_notify_promotion = lambda *a, **k: None
A.ss_update_crew_status = lambda ss, c, u, st: (writes.append(("status", c, u, st)) or ({"ok": True}, None))
A._ss_sessions["h"] = FakeSS()
A._ss_identity["h"] = {"cohort": "admin", "usergroupID": 1}

CALLOUT = {"ok": True, "places": 2, "members": [
    {"user_id": 60, "name": "A", "calls": [1, 3]},
    {"user_id": 62, "name": "B", "calls": [1, 3]},
    {"user_id": 63, "name": "C", "calls": [1, 3]},
    {"user_id": 64, "name": "D", "calls": [1]},
], "skipped": []}
A.ss_callout = lambda ss, cid, action: (200, dict(CALLOUT), None)

fails = []
def check(label, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + label + ((" — " + str(detail)) if detail else ""))
    if not cond: fails.append(label)


def post(path, body):
    with A.app.test_client() as c:
        with c.session_transaction() as s: s["sid"] = "h"
        r = c.post(path, json=body)
        return r.status_code, r.get_json()

def get(path):
    with A.app.test_client() as c:
        with c.session_transaction() as s: s["sid"] = "h"
        r = c.get(path)
        return r.status_code, r.get_json()

def offer(calls, crew, **extra):
    body = {"crew": [{"name": f"P{u}", "crew_id": str(u)} for u in crew],
            "calls": [{"call_id": c, "booking_id": 100, "call_name": ""} for c in calls]}
    body.update(extra)
    return body

def ids(d): return [w["call_id"] for w in (d or {}).get("warnings", [])]


print("── Crew Finder add ──")
writes.clear()
code, d = post("/api/goat/add-crew", offer([1], [100, 101]))
check("two new crew onto Audio load in -> 409", code == 409, code)
check("needs_confirm set", d and d.get("needs_confirm") is True)
w = (d or {}).get("warnings", [{}])[0]
check("warns about Load out only", ids(d) == ["3"], ids(d))
check("numbers: 20 of 20, adding 2, after 22",
      (w.get("now"), w.get("required"), w.get("adding"), w.get("after")) == (20, 20, 2, 22), w)
check("via names the call added to", w.get("via") == "Audio load in", w.get("via"))
check("NOTHING written on a 409", writes == [], writes)

code, d = post("/api/goat/add-crew", offer([1], [100, 77]))
w = (d or {}).get("warnings", [{}])[0]
check("crew already on Load out isn't counted (adding 1 -> 21)",
      code == 409 and w.get("adding") == 1 and w.get("after") == 21, w)

code, d = post("/api/goat/add-crew", offer([1], [77]))
check("only crew already on Load out -> no warning, goes ahead", code == 200, (code, d))

code, d = post("/api/goat/add-crew", offer([1], [100, 88]))
w = (d or {}).get("warnings", [{}])[0]
check("a DECLINED row is re-offered, so it counts (adding 2)", w.get("adding") == 2, w)

code, d = post("/api/goat/add-crew", offer([1, 3], [100]))
check("selecting load-in AND load out still checks the load out", ids(d) == ["3"], ids(d))
check("...and 'via' names only the load-in, not the load out itself",
      d["warnings"][0]["via"] == "Audio load in", d["warnings"][0]["via"])
code, d = post("/api/goat/add-crew", offer([1, 2, 3], [100]))
check("two feeders selected: via names both, never the load out",
      d["warnings"][0]["via"] == "Audio load in, LX load in", d["warnings"][0]["via"])

code, d = post("/api/goat/add-crew", offer([3], [100]))
check("adding to the load out alone -> no warning (D6: not the call acted on)", code == 200, code)

code, d = post("/api/goat/add-crew", offer([4], [100]))
check("locked Show warned, recommended Bump out NOT", ids(d) == ["5"], ids(d))

code, d = post("/api/goat/add-crew", offer([7], [100]))
check("migrated symmetric pair (cycle) -> no warning", code == 200, (code, ids(d)))

code, d = post("/api/goat/add-crew", offer([9], [100]))
check("feeder itself full (peel -> standby on both) -> no warning", code == 200, (code, ids(d)))

code, d = post("/api/goat/add-crew", offer([12], [100]))
check("receiving call has room -> no warning", code == 200, (code, ids(d)))

code, d = post("/api/goat/add-crew", offer([13], [100]))
check("cancelled receiving call -> no warning", code == 200, (code, ids(d)))

writes.clear()
code, d = post("/api/goat/add-crew", offer([1], [100, 101], ack_over=True))
check("ack_over: goes ahead (200) and writes", code == 200 and len(writes) == 2, (code, len(writes)))

writes.clear()
code, d = post("/api/goat/send-sms", offer([1], [100]))
check("Send SMS -> 409 too, nothing written or sent", code == 409 and writes == [], (code, writes))

print("── Promote ──")
writes.clear()
code, d = post("/api/call/100/1/crew/60/status", {"status": 5})
check("backup on both -> 409, warns Load out (adding 1 -> 21)",
      code == 409 and ids(d) == ["3"] and d["warnings"][0]["after"] == 21, (code, d))
check("nothing sent to SmartStaff", writes == [], writes)

code, d = post("/api/call/100/1/crew/60/status", {"status": 5, "ack_over": True})
check("ack_over -> promotion goes through", code == 200 and writes and writes[-1][0] == "status", (code, writes))

writes.clear()
code, d = post("/api/call/100/1/crew/61/status", {"status": 5})
check("no standby row on Load out -> no warning (server refuses that case itself)",
      code == 200 and writes, (code, d))

writes.clear()
code, d = post("/api/call/100/3/crew/9/status", {"status": 6})
check("non-promote status change is never checked", code == 200 and writes, (code, d))

print("── Call-out preview ──")
code, d = get("/api/call/100/1/callout")
ow = (d or {}).get("over_warnings") or [{}]
check("preview carries over_warnings for Load out", [w.get("call_id") for w in ow] == ["3"], ow)
check("worst case = min(places 2, carried 3) -> after 22, up_to",
      ow[0].get("adding") == 2 and ow[0].get("after") == 22 and ow[0].get("up_to") is True, ow[0])
check("preview body otherwise untouched", d.get("places") == 2 and len(d.get("members")) == 4)

print("── ✕ Remove: linked rows ──")
code, d = get("/api/call/100/16/crew/50/linked-rows")
check("middle of chain: upstream Chain A, downstream Chain C",
      [r["call_id"] for r in d["upstream"]] == ["15"] and [r["call_id"] for r in d["downstream"]] == ["17"], d)
check("statuses carried through", d["upstream"][0]["status"] == "confirmed" and d["downstream"][0]["status"] == "backup")
code, d = get("/api/call/100/15/crew/50/linked-rows")
check("top of chain: downstream nearest-first [16, 17]",
      [r["call_id"] for r in d["downstream"]] == ["16", "17"] and d["upstream"] == [], d)
code, d = get("/api/call/100/17/crew/50/linked-rows")
check("bottom of chain: upstream most-upstream-first [15, 16]",
      [r["call_id"] for r in d["upstream"]] == ["15", "16"], d)
code, d = get("/api/call/100/17/crew/51/linked-rows")
check("someone with only a declined row -> nothing to ask", d["upstream"] == [] and d["downstream"] == [], d)
code, d = get("/api/call/100/7/crew/70/linked-rows")
check("symmetric pair listed once (as upstream)",
      [r["call_id"] for r in d["upstream"]] == ["8"] and d["downstream"] == [], d)
code, d = get("/api/call/100/3/crew/9/linked-rows")
check("Mia on Load out only -> nothing to ask", d["upstream"] == [] and d["downstream"] == [], d)

print()
if fails:
    print(f"{len(fails)} FAILED"); sys.exit(1)
print("ALL CHECKS PASSED")

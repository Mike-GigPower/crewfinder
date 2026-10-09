#!/usr/bin/env python3
"""Tests for /api/ops/crewlists — the Ops 'Crew lists to send' lane
(DESIGN-crewlist-sent-v0_2 §8, brief Slice C4).

Three layers, all with injected inputs (no network, no login, no holiday load):
  1. crewlist_due_bucket — every row of brief C4, holidays and now injected
  2. ops_crewlist_rows   — the lane predicate (confirmed >= 1, not cancelled by
     either test, state none/changed) and its counts
  3. the route itself    — the two window conventions (bulk end exclusive,
     status end inclusive), holiday meta, and both soft-fail paths

Run:
    python3 test_ops_crewlists.py
"""
import sys, json
from datetime import datetime, date, timedelta

import app as A
import public_holidays

fails = []
def check(label, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + label + ((" — " + str(detail)) if detail else ""))
    if not cond: fails.append(label)

def dt(s): return datetime.strptime(s, "%Y-%m-%d %H:%M")

NOHOL  = set()
CUPDAY = {date(2026, 11, 3)}         # Melbourne Cup, Tue 3 Nov 2026

# ── 1. Buckets (brief C4). Sat 10 Oct 2026; Thu 8, Fri 9, Wed 7, Tue 6. ──────
print("crewlist_due_bucket")
SAT = dt("2026-10-10 10:00")
assert SAT.weekday() == 5
CASES = [
    ("Thu 23:59 -> Sat 10:00 = due72 (24 h 1 min)",  dt("2026-10-08 23:59"), SAT, NOHOL, "due72"),
    ("Fri 00:00 -> Sat 10:00 = due24 (24 h, boundary)", dt("2026-10-09 00:00"), SAT, NOHOL, "due24"),
    ("Wed 00:00 -> Sat 10:00 = due72 (72 h, boundary)", dt("2026-10-07 00:00"), SAT, NOHOL, "due72"),
    ("Tue 23:59 -> Sat 10:00 = later",                  dt("2026-10-06 23:59"), SAT, NOHOL, "later"),
    ("Mon 2 Nov 08:00 -> Wed 4 Nov 08:00, Cup Day = due24",
        dt("2026-11-02 08:00"), dt("2026-11-04 08:00"), CUPDAY, "due24"),
    ("same, no holidays loaded = due72 (48 h)",
        dt("2026-11-02 08:00"), dt("2026-11-04 08:00"), NOHOL, "due72"),
    ("call already started = due24",  dt("2026-10-09 14:00"), dt("2026-10-09 07:00"), NOHOL, "due24"),
    ("weekend now, call later that weekend = due24 (0 working h)",
        dt("2026-10-10 09:00"), dt("2026-10-11 14:00"), NOHOL, "due24"),
]
for label, now, start, hol, want in CASES:
    got = A.crewlist_due_bucket(now, start, hol)
    check(label, got == want, f"got {got}")

# ── 2. Lane predicate + counts ───────────────────────────────────────────────
print("ops_crewlist_rows")
NOW = dt("2026-10-09 00:00")             # Friday midnight
def call(cid, bid, start, booked, name="Bump In", **kw):
    r = {"call_id": cid, "booking_id": bid, "booking_name": f"Show {bid}", "venue": "MCG",
         "call_name": name, "start_iso": start + ":00", "date_iso": start[:10],
         "time": start[11:16], "booked": booked}
    r.update(kw); return r

CALLS = [
    call(1, 100, "2026-10-10T07:00", 8),                               # never, due24
    call(2, 100, "2026-10-10T17:00", 12),                              # changed, due24
    call(3, 100, "2026-10-10T22:30", 14),                              # sent -> out
    call(4, 200, "2026-10-13T09:00", 3),                               # never, due72 (57 wh)
    call(5, 200, "2026-10-20T09:00", 0),                               # confirmed 0 -> out
    call(6, 300, "2026-10-21T09:00", 5, cancelled_at=1791500000),      # cancelled -> out
    call(7, 300, "2026-10-21T10:00", 5, name="CANCELLED - Bump Out"),  # cancelled prefix -> out
    call(8, 300, "2026-10-21T11:00", 5, name="  cancelled -bump"),     # prefix, lower/space -> out
    call(9, 300, "2026-10-22T09:00", 2, booked_note="promo"),          # never, later
    call(10, 400, "2026-10-12T09:00", 1, promo_pending=1),             # raw booked 1 counts
]
DIFF = {"added": [{"id": 9734, "name": "Smith, Sam"}], "removed": [],
        "start_date": None, "start_time": None, "est_length": None}
STATUS = {
    "2": {"booking_id": 100, "state": "changed", "sent_at": 1791400000,
          "sent_by_name": "Lack, Mike", "diff": DIFF},
    "3": {"booking_id": 100, "state": "sent", "sent_at": 1791400000, "diff": None},
}
rows, counts = A.ops_crewlist_rows(CALLS, STATUS, NOW, NOHOL)
ids = [r["call_id"] for r in rows]
check("kept exactly 1,2,4,9,10", sorted(ids) == [1, 2, 4, 9, 10], ids)
check("confirmed 0 excluded", 5 not in ids)
check("state sent excluded", 3 not in ids)
check("cancelled_at excluded", 6 not in ids)
check("'CANCELLED -' name excluded (both spellings)", 7 not in ids and 8 not in ids)
check("raw booked (status 5) used, not _filled()", 10 in ids)
by = {r["call_id"]: r for r in rows}
check("absent from status -> why never", by[1]["why"] == "never" and by[1]["diff"] is None)
check("changed -> why changed, carries diff", by[2]["why"] == "changed" and by[2]["diff"] == DIFF)
check("buckets", (by[1]["due_bucket"], by[4]["due_bucket"], by[9]["due_bucket"])
      == ("due24", "due72", "later"), [by[i]["due_bucket"] for i in (1, 4, 9)])
check("counts.total == rows", counts["total"] == len(rows))
check("counts.due sums to total", sum(counts["due"].values()) == counts["total"], counts["due"])
check("counts.why sums to total", sum(counts["why"].values()) == counts["total"], counts["why"])
check("counts.why", counts["why"] == {"never": 4, "changed": 1}, counts["why"])
check("counts.bookings", counts["bookings"] == 4, counts["bookings"])

# ── 3. The route ─────────────────────────────────────────────────────────────
print("/api/ops/crewlists")
seen = {}
def fake_bulk(ss, start, end):
    seen["bulk"] = (start, end); return list(CALLS), None
def fake_status(ss, params):
    seen["status"] = dict(params); return {"calls": dict(STATUS), "bookings": {}}, None
A.get_ss_session        = lambda: object()
A.fetch_calls_for_ops   = fake_bulk
A.fetch_crewlist_status = fake_status
public_holidays.load_holidays = lambda force=False: (set(), {"unavailable": True, "stale": False})
A._ss_sessions["h"] = object()
A._ss_identity["h"] = {"cohort": "admin", "usergroupID": 1}

def get():
    with A.app.test_client() as c:
        with c.session_transaction() as s: s["sid"] = "h"
        r = c.get("/api/ops/crewlists")
        return r.status_code, r.get_json()

code, d = get()
check("HTTP 200", code == 200, code)
today = datetime.now().replace(hour=0, minute=0, second=0, microsecond=0)
f = lambda n: (today + timedelta(days=n)).strftime("%Y-%m-%d")
check("bulk window today..today+15 (end exclusive)", seen.get("bulk") == (f(0), f(15)), seen.get("bulk"))
check("status window today..today+14 (end inclusive)",
      seen.get("status") == {"start": f(0), "end": f(14)}, seen.get("status"))
check("badge identity: counts.total == len(calls)", d["counts"]["total"] == len(d["calls"]))
check("holiday meta surfaced", d["holidays"]["unavailable"] is True and d["holidays"]["years"] == [])
check("not unavailable when holidays fail", not d.get("unavailable"))

A.fetch_crewlist_status = lambda ss, params: (None, "HTTP 500")
code, d = get()
check("status failure -> lane unavailable", code == 200 and d.get("unavailable") is True, d)

A.fetch_calls_for_ops = lambda ss, s, e: (None, "HTTP 502")
code, d = get()
check("bulk failure -> lane unavailable", code == 200 and d.get("unavailable") is True, d)

if fails: print(f"FAILED: {len(fails)} check(s): {fails}"); sys.exit(1)
print("ALL CHECKS PASSED"); sys.exit(0)

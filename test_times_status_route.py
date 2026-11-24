#!/usr/bin/env python3
"""/api/booking/<id>/times-status and fetch_booking_times_status()
(BRIEF-times-navigation §2). No network: the SmartStaff call is stubbed.

Two layers:
  1. fetch_booking_times_status — every way get-booking-times-status.php can
     disappoint it (transport, HTTP, JSON, {error}, missing ok), each of which
     must come back as an error rather than an empty calls{}: empty means
     "nothing to shade", which THE GOAT paints as an all-clear.
  2. the route — 400 on a bad id, 401 with no session, 502 on a SmartStaff
     failure, 200 with the map passed straight through.

Run:
    python3 test_times_status_route.py
"""
import json
import sys

import app as A

fails = []


def check(label, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + label + ((" — " + str(detail)) if detail else ""))
    if not cond:
        fails.append(label)


# One call, with the shape THE GOAT reads. with_times is null on a ticked call.
CALLS = {
    "39977": {"ended": True, "cancelled": False, "confirmed": 6, "promo_pending": 0,
              "with_times": 6, "times_filled": 0, "call_locked": 0, "no_show_open": 0},
    "39978": {"ended": True, "cancelled": False, "confirmed": 6, "promo_pending": 1,
              "with_times": None, "times_filled": 1, "call_locked": 0, "no_show_open": 0},
}


class FakeResp:
    def __init__(self, status=200, body=None, text=None):
        self.status_code = status
        self.text = text if text is not None else json.dumps(body)


class FakeSS:
    """A requests-ish session. `answer` is a FakeResp or an exception."""

    def __init__(self, answer):
        self.answer = answer
        self.seen = None

    def get(self, url, params=None, **kw):
        self.seen = (url, dict(params or {}))
        if isinstance(self.answer, Exception):
            raise self.answer
        return self.answer


# ── 1. fetch_booking_times_status ────────────────────────────────────────────
print("fetch_booking_times_status")

ss = FakeSS(FakeResp(body={"ok": True, "booking": 12345, "now": 1795000000, "calls": CALLS}))
calls, err = A.fetch_booking_times_status(ss, 12345)
check("happy path returns the map", calls == CALLS and err is None, (calls, err))
check("asks the right endpoint", ss.seen[0].endswith("/ajax/crew/get-booking-times-status.php"), ss.seen)
check("passes booking=<id>", ss.seen[1] == {"booking": 12345}, ss.seen)

CASES = [
    ("transport failure",      FakeSS(RuntimeError("boom"))),
    ("HTTP 500",               FakeSS(FakeResp(status=500, body={"ok": True, "calls": {}}))),
    ("HTTP 403 (wrong cohort)", FakeSS(FakeResp(status=403, body={"error": "Admin required"}))),
    ("not JSON",               FakeSS(FakeResp(text="<html>login</html>"))),
    ("{error}",                FakeSS(FakeResp(body={"error": "call query failed"}))),
    ("no ok flag",             FakeSS(FakeResp(body={"calls": CALLS}))),
    ("ok false",               FakeSS(FakeResp(body={"ok": False, "calls": CALLS}))),
]
for label, s in CASES:
    calls, err = A.fetch_booking_times_status(s, 1)
    check("%s -> (None, err), never an empty map" % label, calls is None and err, (calls, err))

# A booking with no calls really is {} -- the one empty answer that is true.
ss = FakeSS(FakeResp(body={"ok": True, "booking": 1, "now": 1, "calls": {}}))
calls, err = A.fetch_booking_times_status(ss, 1)
check("booking with no calls -> ({}, None)", calls == {} and err is None, (calls, err))

# calls missing or the wrong type is normalised, not crashed on.
ss = FakeSS(FakeResp(body={"ok": True, "booking": 1, "now": 1}))
calls, err = A.fetch_booking_times_status(ss, 1)
check("calls absent -> ({}, None)", calls == {} and err is None, (calls, err))
ss = FakeSS(FakeResp(body={"ok": True, "calls": []}))
calls, err = A.fetch_booking_times_status(ss, 1)
check("calls [] (PHP empty array) -> ({}, None)", calls == {} and err is None, (calls, err))

# ── 2. The route ─────────────────────────────────────────────────────────────
print("/api/booking/<id>/times-status")

A._ss_sessions["h"] = object()
A._ss_identity["h"] = {"cohort": "admin", "usergroupID": 1}
A.get_ss_session = lambda: object()
seen = {}


def fake_fetch(ss, booking_id):
    seen["bid"] = booking_id
    return dict(CALLS), None


A.fetch_booking_times_status = fake_fetch


def get(path="/api/booking/12345/times-status", logged_in=True):
    with A.app.test_client() as c:
        if logged_in:
            with c.session_transaction() as s:
                s["sid"] = "h"
        r = c.get(path)
        return r.status_code, r.get_json()

code, d = get()
check("200 with the map", code == 200 and d["ok"] is True and d["calls"] == CALLS, (code, d))
check("the id reaches SmartStaff as an int", seen.get("bid") == 12345, seen)

for bad in ("abc", "0", "-5", "12.5", "%20"):
    code, d = get("/api/booking/%s/times-status" % bad)
    check("bad id %r -> 400" % bad, code == 400 and d.get("error"), (code, d))

A.get_ss_session = lambda: None
code, d = get()
check("no SmartStaff session -> 401", code == 401 and d.get("error"), (code, d))
A.get_ss_session = lambda: object()

A.fetch_booking_times_status = lambda ss, b: (None, "HTTP 500")
code, d = get()
check("SmartStaff failure -> 502 unavailable, no calls",
      code == 502 and d.get("unavailable") is True and "calls" not in d, (code, d))

code, d = get(logged_in=False)
check("no login at all -> not 200", code != 200, (code, d))

if fails:
    print("FAILED: %d check(s): %s" % (len(fails), fails))
    sys.exit(1)
print("ALL CHECKS PASSED")
sys.exit(0)

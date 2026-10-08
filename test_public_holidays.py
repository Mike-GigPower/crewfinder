#!/usr/bin/env python3
"""Tests for public_holidays.py — working-hours maths and the cache fallbacks.

No network: the maths takes an injected date set, and the fetch is replaced by
a fake so the fallback chain (memory -> fetch -> disk -> unavailable) can be
driven one step at a time. The disk cache lives in a throwaway temp dir.

Run:
    python3 test_public_holidays.py
"""
import json
import os
import shutil
import sys
import tempfile
from datetime import date, datetime

import public_holidays as PH

fails = []
def check(label, cond, detail=""):
    print(("  PASS  " if cond else "  FAIL  ") + label + ((" — " + str(detail)) if detail else ""))
    if not cond: fails.append(label)

def dt(s): return datetime.strptime(s, "%Y-%m-%d %H:%M")

# Fixture: a full VIC 2026 (14 rows, including the Mon 28 Dec Boxing Day
# substitute) and a 2028 that has only Australia Day — the "not loaded" shape.
ROWS_2026 = [
    ("2026-01-01", "New Year's Day"), ("2026-01-26", "Australia Day"),
    ("2026-03-09", "Labour Day"), ("2026-04-03", "Good Friday"),
    ("2026-04-04", "Saturday before Easter Sunday"), ("2026-04-05", "Easter Sunday"),
    ("2026-04-06", "Easter Monday"), ("2026-04-25", "ANZAC Day"),
    ("2026-06-08", "King's Birthday"), ("2026-09-25", "AFL Grand Final Friday"),
    ("2026-11-03", "Melbourne Cup"), ("2026-12-25", "Christmas Day"),
    ("2026-12-26", "Boxing Day"), ("2026-12-28", "Boxing Day (substitute)"),
]
ROWS_2028 = [("2028-01-26", "Australia Day")]
HOLS = {date.fromisoformat(d) for d, _ in ROWS_2026 + ROWS_2028}

def api_rows(rows): return [{"holiday_date": d, "name": n} for d, n in rows]

# ── working_hours (brief §6) ─────────────────────────────────────────────────
print("working_hours")
wh = lambda a, b: PH.working_hours(dt(a), dt(b), holidays=HOLS)
check("Thu 23:00 -> Sat 10:00 = 25 h", wh("2026-11-05 23:00", "2026-11-07 10:00") == 25.0,
      wh("2026-11-05 23:00", "2026-11-07 10:00"))
check("Fri 00:00 -> Sat 10:00 = 24 h", wh("2026-11-06 00:00", "2026-11-07 10:00") == 24.0,
      wh("2026-11-06 00:00", "2026-11-07 10:00"))
check("Fri 09:00 -> Mon 09:00 = 24 h", wh("2026-11-06 09:00", "2026-11-09 09:00") == 24.0,
      wh("2026-11-06 09:00", "2026-11-09 09:00"))
check("Mon 2 Nov 08:00 -> Wed 4 Nov 08:00 over Cup Day = 24 h",
      wh("2026-11-02 08:00", "2026-11-04 08:00") == 24.0, wh("2026-11-02 08:00", "2026-11-04 08:00"))
check("Sat 10:00 -> Sun 14:00 = 0 h", wh("2026-11-07 10:00", "2026-11-08 14:00") == 0.0,
      wh("2026-11-07 10:00", "2026-11-08 14:00"))
check("call already started = 0 h", wh("2026-11-05 12:00", "2026-11-05 09:00") == 0.0)
check("start == now = 0 h", wh("2026-11-05 12:00", "2026-11-05 12:00") == 0.0)

# ── is_working_day ───────────────────────────────────────────────────────────
print("is_working_day")
check("Mon 28 Dec 2026 is not a working day", not PH.is_working_day(date(2026, 12, 28), HOLS))
check("Tue 29 Dec 2026 is a working day", PH.is_working_day(date(2026, 12, 29), HOLS))
check("Saturday is not a working day", not PH.is_working_day(date(2026, 11, 7), HOLS))

# ── covered_years ────────────────────────────────────────────────────────────
print("covered_years")
cy = PH.covered_years(HOLS)
check("2026 (14 rows) is covered", 2026 in cy, cy)
check("year with 1 row is NOT covered", 2028 not in cy, cy)

# ── fetch / cache fallbacks ──────────────────────────────────────────────────
print("load_holidays fallbacks")
tmp = tempfile.mkdtemp(prefix="ph-test-")
cache = os.path.join(tmp, "public_holidays_cache.json")
calls = {"n": 0}

def fetch_ok(rows):
    def f():
        calls["n"] += 1
        return PH._parse_rows(api_rows(rows)), None
    return f
def fetch_fail():
    calls["n"] += 1
    return None, "HTTP 503"

try:
    # Fetch fails, no cache: empty set, unavailable, weekends-only maths.
    PH.configure("https://example.invalid", "test-key", cache)
    PH._fetch_remote = fetch_fail
    s, m = PH.load_holidays()
    check("fail + no cache -> empty set", s == set(), s)
    check("...meta.unavailable", m.get("unavailable") is True and m.get("stale") is False, m)
    check("...weekends-only maths (Cup Day counts as working)",
          PH.working_hours(dt("2026-11-02 08:00"), dt("2026-11-04 08:00"), holidays=s) == 48.0)
    check("...is_working_day still answers", PH.is_working_day(date(2026, 11, 3), s) is True)
    check("...covered_years is empty", PH.covered_years(s) == set())
    check("no cache file was written", not os.path.exists(cache))

    # Good fetch: writes the disk copy.
    PH.configure("https://example.invalid", "test-key", cache)
    PH._fetch_remote = fetch_ok(ROWS_2026)
    s, m = PH.load_holidays()
    check("good fetch -> 14 dates, not stale", len(s) == 14 and not m["stale"] and not m["unavailable"], m)
    check("...disk copy written", os.path.exists(cache))
    with open(cache) as f:
        disk = json.load(f)
    check("...disk copy has 14 rows and saved_at", len(disk["holidays"]) == 14 and disk.get("saved_at"))

    # Memory cache: a second call does not refetch; force=True does.
    n0 = calls["n"]
    PH.load_holidays()
    check("memory cache: second call does not refetch", calls["n"] == n0, calls["n"] - n0)
    PH._fetch_remote = fetch_ok(ROWS_2026 + ROWS_2028)
    s, _ = PH.load_holidays(force=True)
    check("force=True refetches and sees the new row", calls["n"] == n0 + 1 and date(2028, 1, 26) in s)

    # Fetch fails, disk cache present: cached dates, stale.
    PH.configure("https://example.invalid", "test-key", cache)   # fresh process
    PH._fetch_remote = fetch_fail
    s, m = PH.load_holidays()
    check("fail + disk cache -> cached dates", s == HOLS, len(s))
    check("...meta.stale", m.get("stale") is True and m.get("unavailable") is False, m)
    check("...fetched_at is the disk copy's saved_at", m.get("fetched_at") is not None)

    # Backoff: a down Estimator is not re-hit on every call.
    n0 = calls["n"]
    PH.load_holidays()
    check("failure backoff: no refetch inside the retry window", calls["n"] == n0)

    # From here on, the REAL _fetch_remote runs against a stub requests module,
    # so the request shape and its guards are under test too.
    import importlib, types
    importlib.reload(PH)
    seen = {}
    class FakeResp:
        def __init__(self, code, body): self.status_code, self._body = code, body
        def json(self): return self._body
    def fake_requests(code, body):
        mod = types.ModuleType("requests")
        def get(url, params=None, headers=None, timeout=None):
            seen.update(url=url, params=params, headers=headers, timeout=timeout)
            return FakeResp(code, body)
        mod.get = get
        return mod
    real_requests = sys.modules.get("requests")

    sys.modules["requests"] = fake_requests(200, api_rows(ROWS_2026))
    PH.configure("https://example.invalid/", "test-key", cache)
    s, m = PH.load_holidays()
    check("real fetch: 14 dates, not stale", len(s) == 14 and not m["stale"], m)
    check("...key sent only as the apikey header",
          seen["headers"] == {"apikey": "test-key"} and "test-key" not in seen["url"]
          and "test-key" not in json.dumps(seen["params"]), seen)
    check("...VIC, active only, ordered, 10 s timeout",
          seen["params"].get("state_code") == "eq.VIC" and seen["params"].get("is_active") == "eq.true"
          and seen["params"].get("order") == "holiday_date" and seen["timeout"] == 10, seen)
    check("...URL trailing slash handled",
          seen["url"] == "https://example.invalid/rest/v1/public_holidays", seen["url"])

    # An empty answer (what a broken read policy looks like) must not clobber disk.
    sys.modules["requests"] = fake_requests(200, [])
    PH.configure("https://example.invalid", "test-key", cache)
    s, m = PH.load_holidays()
    check("empty fetch -> treated as failure, disk copy kept",
          m.get("stale") is True and len(s) == 14 and m.get("error") == "no rows returned", m)

    sys.modules["requests"] = fake_requests(401, {"message": "bad key"})
    PH.configure("https://example.invalid", "test-key", cache)
    s, m = PH.load_holidays()
    check("HTTP 401 -> stale disk copy, error names the status only",
          m.get("stale") is True and m.get("error") == "HTTP 401", m)

    if real_requests is not None: sys.modules["requests"] = real_requests
    else: sys.modules.pop("requests", None)

    # Not configured: no key -> straight to disk / unavailable, never raises.
    PH.configure("", "", os.path.join(tmp, "absent.json"))
    s, m = PH.load_holidays()
    check("unconfigured -> unavailable, no raise", s == set() and m.get("unavailable") is True
          and m.get("error") == "not configured", m)

    # Corrupt disk file: falls through to unavailable, never raises.
    bad = os.path.join(tmp, "bad.json")
    with open(bad, "w") as f:
        f.write("{not json")
    PH.configure("", "", bad)
    s, m = PH.load_holidays()
    check("corrupt disk cache -> unavailable, no raise", s == set() and m.get("unavailable") is True, m)
    check("...and the corrupt file is left alone", open(bad).read() == "{not json")
finally:
    shutil.rmtree(tmp, ignore_errors=True)

print()
if fails: print(f"FAILED: {len(fails)} check(s): {fails}"); sys.exit(1)
print("ALL CHECKS PASSED"); sys.exit(0)

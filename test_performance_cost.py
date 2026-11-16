#!/usr/bin/env python3
"""Tests for the Performance margin route (BRIEF-performance-cost-slice-a.md §5).

Drives the REAL /api/performance/cost through a Flask test client with
SmartStaff faked:

  - who may call it: admin cohort AND a MARGIN_USERS userID (Mike 10261,
    Joe 10260). An operations session, and an admin who is not Mike or Joe,
    are refused with 403 and never reach SmartStaff.
  - soft-fail: a failed fetch is HTTP 200 {"unavailable": true} and is never
    cached.
  - the 10-minute cache: a second call doesn't refetch; force=1 does; the key
    carries the window, so weeks=26 is not served weeks=52's answer.
  - whoami.margin follows MARGIN_USERS.
  - fetch_performance_cost: one request, weeks URL-encoded, call names
    unescaped, a bad shape refused.

Run (no network and no login required):
    python3 test_performance_cost.py
"""
import json
import unittest
from unittest import mock

import app as A


class FakeSS:
    pass


PAYLOAD = {
    "generated_at": "2026-11-16T10:00:00",
    "window": {"weeks": 1, "range_start": 0, "range_end": 0, "week_keys": ["2025-W46", "2026-W46"]},
    "weeks": [{"key": "2025-W46"}, {"key": "2026-W46"}],
    "totals": {"current": {}, "prior": {}},
    "exceptions": {"not_on_invoice": [], "truncated": False},
    "data_quality": {},
}


def get(path, sid="h"):
    with A.app.test_client() as c:
        with c.session_transaction() as s:
            s["sid"] = sid
        r = c.get(path)
        return r.status_code, r.get_json()


class Route(unittest.TestCase):

    def setUp(self):
        self.calls = []
        self.answer = (dict(PAYLOAD), None)

        def fake_fetch(ss, weeks):
            self.calls.append(weeks)
            return self.answer

        patches = [
            mock.patch.multiple(A, get_ss_session=lambda: FakeSS(),
                                fetch_performance_cost=fake_fetch),
            mock.patch.dict(A._ss_sessions, {"h": FakeSS()}),
            mock.patch.dict(A._ss_identity, {"h": {"cohort": "admin", "user_id": 10261}}),
            mock.patch.dict(A._perf_cost_cache, {}, clear=True),
        ]
        for p in patches:
            p.start()
            self.addCleanup(p.stop)

    def who(self, cohort, uid):
        A._ss_identity["h"] = {"cohort": cohort, "user_id": uid}

    def test_mike_gets_the_payload(self):
        code, body = get("/api/performance/cost")
        self.assertEqual(code, 200)
        self.assertEqual(body["generated_at"], "2026-11-16T10:00:00")
        self.assertEqual(self.calls, [None])     # weeks omitted -> PHP owns the default

    def test_joe_gets_the_payload(self):
        self.who("admin", "10260")               # string ids are coerced
        code, _ = get("/api/performance/cost")
        self.assertEqual(code, 200)

    def test_operations_is_refused_and_never_fetches(self):
        self.who("operations", 10261)
        code, _ = get("/api/performance/cost")
        self.assertEqual(code, 403)
        self.assertEqual(self.calls, [])

    def test_admin_not_in_margin_users_is_refused(self):
        self.who("admin", 2973)                  # the shared admin
        code, body = get("/api/performance/cost")
        self.assertEqual(code, 403)
        self.assertEqual(self.calls, [])
        self.assertIn("error", body)

    def test_admin_with_no_user_id_is_refused(self):
        self.who("admin", "0")
        code, _ = get("/api/performance/cost")
        self.assertEqual(code, 403)

    def test_failure_soft_fails_and_is_not_cached(self):
        self.answer = (None, "HTTP 500")
        code, body = get("/api/performance/cost")
        self.assertEqual(code, 200)
        self.assertEqual(body, {"unavailable": True, "error": "HTTP 500"})
        self.answer = (dict(PAYLOAD), None)
        code, body = get("/api/performance/cost")
        self.assertNotIn("unavailable", body)
        self.assertEqual(len(self.calls), 2)

    def test_second_call_is_cached(self):
        get("/api/performance/cost")
        get("/api/performance/cost")
        self.assertEqual(len(self.calls), 1)

    def test_force_refetches(self):
        get("/api/performance/cost")
        get("/api/performance/cost?force=1")
        self.assertEqual(len(self.calls), 2)

    def test_cache_key_carries_the_window(self):
        get("/api/performance/cost?weeks=52")
        get("/api/performance/cost?weeks=26")
        self.assertEqual(self.calls, ["52", "26"])

    def test_cache_expires_after_ten_minutes(self):
        get("/api/performance/cost")
        for v in A._perf_cost_cache.values():
            v["at"] = v["at"] - A.timedelta(seconds=A.PERF_COST_CACHE_SECONDS + 1)
        get("/api/performance/cost")
        self.assertEqual(len(self.calls), 2)


class Allowlist(unittest.TestCase):

    def test_margin_users_are_mike_and_joe(self):
        self.assertEqual(A.MARGIN_USERS, {10261, 10260})

    def test_php_holds_the_same_set(self):
        import os, re
        here = os.path.dirname(os.path.abspath(__file__))
        with open(os.path.join(here, "smartstaff", "get-performance-cost.php")) as f:
            src = f.read()
        m = re.search(r"\$GOAT_MARGIN_USERS\s*=\s*array\(([^)]*)\)", src)
        self.assertIsNotNone(m)
        self.assertEqual({int(x) for x in re.findall(r"\d+", m.group(1))}, A.MARGIN_USERS)

    def test_current_margin_user_id(self):
        cases = [({"user_id": 10261}, 10261), ({"user_id": "10260"}, 10260),
                 ({"user_id": 10258}, None), ({"user_id": "0"}, None),
                 ({"user_id": "x"}, None), ({}, None)]
        for ident, want in cases:
            with mock.patch.object(A, "current_identity", lambda i=ident: i):
                self.assertEqual(A.current_margin_user_id(), want, ident)

    def test_whoami_carries_margin(self):
        with open(A.__file__, encoding="utf-8") as f:
            src = f.read()
        self.assertIn('"margin":      current_margin_user_id() is not None,', src)


class Resp:
    def __init__(self, code, text):
        self.status_code = code
        self.text = text


class Fetch(unittest.TestCase):

    def test_one_request_weeks_encoded_names_unescaped(self):
        seen = []
        body = dict(PAYLOAD)
        body["exceptions"] = {"not_on_invoice": [{"call_name": "Stage &amp; Lights"}], "truncated": False}

        class SS:
            def get(self, url, **kw):
                seen.append(url)
                return Resp(200, json.dumps(body))

        data, err = A.fetch_performance_cost(SS(), "52&x=1")
        self.assertIsNone(err)
        self.assertEqual(len(seen), 1)
        self.assertTrue(seen[0].endswith("/ajax/crew/get-performance-cost.php?weeks=52%26x%3D1"))
        self.assertEqual(data["exceptions"]["not_on_invoice"][0]["call_name"], "Stage & Lights")

    def test_errors(self):
        class SS:
            def __init__(self, r): self.r = r
            def get(self, url, **kw): return self.r
        self.assertEqual(A.fetch_performance_cost(SS(Resp(403, "{}")), None), (None, "HTTP 403"))
        self.assertEqual(A.fetch_performance_cost(SS(Resp(200, '{"error":"Admin only"}')), None)[1], "Admin only")
        self.assertEqual(A.fetch_performance_cost(SS(Resp(200, "[]")), None)[1], "unexpected response shape")
        self.assertEqual(A.fetch_performance_cost(SS(Resp(200, '{"weeks":{}}')), None)[1], "unexpected response shape")
        self.assertTrue(A.fetch_performance_cost(SS(Resp(200, "<html>")), None)[1].startswith("bad JSON"))


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Tests for the Performance margin route (BRIEF-performance-cost-slice-a.md §5).

Drives the REAL /api/performance/cost through a Flask test client with
SmartStaff faked:

  - who may call it: admin cohort AND a MARGIN_USERS userID (admin logins:
    Mike 10261, Joe 10260, Rich 10259, Monty 10258). An operations session,
    and an admin who is not on the list (the shared admin 2973, Jenny 10494),
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
        self.inputs_flags = []
        self.answer = (dict(PAYLOAD), None)

        def fake_fetch(ss, weeks, inputs=False):
            self.calls.append(weeks)
            self.inputs_flags.append(inputs)
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

    def test_rich_and_monty_admin_logins_get_the_payload(self):
        for uid in (10259, "10258"):
            self.who("admin", uid)
            code, _ = get("/api/performance/cost?force=1")
            self.assertEqual(code, 200, uid)

    def test_rich_on_an_operations_account_is_refused(self):
        self.who("operations", 10259)
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


# ── slice D: the forward margin ───────────────────────────────────────────

CFG = {"dayStart": "08:00", "nightStart": "20:00", "publicHolidays": [],
       "doublePenaltyWindows": [], "rates": [], "minBillableHours": 4}


def _inputs():
    # Melbourne: 1795352400 = Mon 23 Nov 2026 00:00 AEDT
    hist = []
    for i, d in enumerate(["2026-06-03", "2026-06-10", "2026-09-02", "2026-09-09"]):
        hist.append({"id": 100 + i, "half": "older" if d < "2026-08" else "newer",
                     "date_iso": d, "start_time": "09:00:00", "est_length": 5,
                     "name": "Load In", "booking_id": 1, "invoice": 7, "invoiced": 450.0,
                     "crew": [{"i": 0, "pay": {"GT1": [5, 161.65]}}]})
    fwd = [{"id": 900, "week_key": "2026-W48", "date_iso": "2026-11-25", "start_time": "09:00:00",
            "est_length": 5, "name": "Load In", "booking_id": 1, "required": 2, "crew": [0]},
           {"id": 901, "week_key": "2026-W48", "date_iso": "2026-11-26", "start_time": "09:00:00",
            "est_length": 5, "name": "Mystery", "booking_id": 1, "required": 3, "crew": []}]
    return {"history": {"older_from": 1779026400, "newer_from": 1786888800, "to": 1794747599,
                        "calls": hist},
            "forward": {"from": 1795352400, "to": 1795352400 + 13 * 86400, "cancelled_calls": 1,
                        "calls": fwd},
            "people": 1}


def _fake_book(fwd, priced_ids=None, call_revenue=None):
    """Prices every call at $100 an hour per seat, except 901 (no role).
    History calls (1 seat, 5 h) price at $500 and were invoiced $450."""
    weeks = {k: {"key": k, "revenue": 0.0, "secured_revenue": 0.0, "unfilled_seats": 0}
             for k in fwd["week_keys"]}
    for c in fwd["calls"]:
        if c["call_id"] == 901:
            continue
        if call_revenue is not None:
            call_revenue[c["call_id"]] = 100.0 * c["est_length"] * c["required"]
        w = weeks[c["week_key"]]
        w["revenue"] += 100.0 * c["est_length"] * c["required"]
        w["secured_revenue"] += 100.0 * c["est_length"] * c["confirmed"]
        w["unfilled_seats"] += c["required"] - c["confirmed"]
        if priced_ids is not None:
            priced_ids.add(c["call_id"])
    return {"weeks": [weeks[k] for k in fwd["week_keys"]], "totals": {}, "data_quality": {}}


class ForwardMargin(unittest.TestCase):

    def setUp(self):
        card = {"rules": {"x": 1}}
        patches = [
            mock.patch.object(A, "load_forward_rate_card", lambda: (card, None)),
            mock.patch.object(A.pricing_rules, "cfg_for_set", lambda r, s: CFG),
            mock.patch.object(A, "price_forward_book", _fake_book),
        ]
        for p in patches:
            p.start()
            self.addCleanup(p.stop)

    def test_weeks_levels_and_only_priced_calls_costed(self):
        fm = A.build_forward_margin(_inputs())
        self.assertTrue(fm["estimated"])
        self.assertEqual(fm["from"], "2026-11-23")
        self.assertEqual(fm["week_keys"], ["2026-W48", "2026-W49"])
        w = fm["weeks"][0]
        # rate card $1,000 / $500, scaled by invoiced / priced = 450 / 500
        self.assertEqual(w["rate_card_revenue"], 1000.0)
        self.assertEqual(w["revenue"], 900.0)
        self.assertEqual(w["secured_revenue"], 450.0)
        self.assertEqual(fm["method"]["revenue_factor"], 0.9)
        self.assertEqual(fm["method"]["revenue_factor_calls"], 2)
        self.assertEqual(fm["backtest"]["revenue"]["total_pct"], 0.0)
        self.assertTrue(fm["backtest"]["revenue"]["passed"])
        # 5h x hours factor 1.0 x $32.33 per seat, 2 seats required, 1 secured
        self.assertAlmostEqual(w["required_cost"], 2 * 5 * 32.33, places=2)
        self.assertAlmostEqual(w["secured_cost"], 5 * 32.33, places=2)
        self.assertAlmostEqual(w["required_margin_pct"], 100 * (900 - 323.3) / 900, places=2)
        self.assertFalse(fm["weeks"][1]["has_work"])
        self.assertEqual(fm["data_quality"]["not_priced_calls"], 1)
        self.assertEqual(fm["data_quality"]["not_priced_seats"], 3)
        self.assertEqual(fm["data_quality"]["cancelled_calls"], 1)
        self.assertEqual(fm["method"]["seats_by_source"]["own"], 1)
        self.assertEqual(fm["method"]["seats_by_source"]["role"], 1)
        self.assertTrue(fm["backtest"]["passed"])
        self.assertEqual(fm["backtest"]["tested_from"], "2026-08-17")

    def test_a_failed_revenue_check_fails_the_backtest(self):
        inp = _inputs()
        for c in inp["history"]["calls"]:
            if c["half"] == "newer":
                c["invoiced"] = 300.0          # older taught 0.9; newer invoiced 0.6
        fm = A.build_forward_margin(inp)
        self.assertFalse(fm["backtest"]["revenue"]["passed"])
        self.assertFalse(fm["backtest"]["passed"])
        self.assertTrue(fm["backtest"]["people"]["passed"])

    def test_no_inputs_is_unavailable(self):
        self.assertTrue(A.build_forward_margin(None)["unavailable"])

    def test_rules_unavailable(self):
        with mock.patch.object(A, "load_forward_rate_card", lambda: (None, "pricing rules unavailable: x")):
            fm = A.build_forward_margin(_inputs())
        self.assertEqual(fm, {"unavailable": True, "error": "pricing rules unavailable: x"})


class ForwardMarginRoute(Route):
    """The route asks for the inputs, strips them, and adds forward_margin."""

    def test_inputs_requested_and_never_sent_to_the_browser(self):
        self.answer = (dict(PAYLOAD, forward_cost_inputs={"secret": True}), None)
        with mock.patch.object(A, "build_forward_margin", lambda i: {"estimated": True, "seen": i}):
            code, body = get("/api/performance/cost")
        self.assertEqual(code, 200)
        self.assertEqual(self.inputs_flags, [True])
        self.assertNotIn("forward_cost_inputs", body)
        self.assertEqual(body["forward_margin"], {"estimated": True, "seen": {"secret": True}})

    def test_estimate_failure_costs_the_forward_zone_only(self):
        def boom(i):
            raise ValueError("x")
        with mock.patch.object(A, "build_forward_margin", boom):
            code, body = get("/api/performance/cost")
        self.assertEqual(code, 200)
        self.assertEqual(body["generated_at"], "2026-11-16T10:00:00")
        self.assertTrue(body["forward_margin"]["unavailable"])


class Allowlist(unittest.TestCase):

    def test_margin_users_are_the_four_admin_logins(self):
        self.assertEqual(A.MARGIN_USERS, {10261, 10260, 10259, 10258})

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
                 ({"user_id": 10258}, 10258), ({"user_id": "10259"}, 10259),
                 ({"user_id": 10494}, None), ({"user_id": 2973}, None), ({"user_id": "0"}, None),
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

    def test_inputs_flag_adds_the_parameter(self):
        seen = []

        class SS:
            def get(self, url, **kw):
                seen.append(url)
                return Resp(200, json.dumps(PAYLOAD))
        A.fetch_performance_cost(SS(), "52", inputs=True)
        A.fetch_performance_cost(SS(), None, inputs=True)
        self.assertTrue(seen[0].endswith("?weeks=52&inputs=1"))
        self.assertTrue(seen[1].endswith("get-performance-cost.php?inputs=1"))


if __name__ == "__main__":
    unittest.main()

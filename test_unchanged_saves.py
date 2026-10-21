"""A save that changes nothing writes nothing and logs nothing: the same
override saved twice, Change… to the type a call already has, and Make Crew
Boss on a row that already is one. Anything different is still written.
Drives the real routes with SmartStaff and Supabase stubbed; no network."""

import json
import os
import unittest

import app as A
import pricing_rules

HERE = os.path.dirname(os.path.abspath(__file__))
BOOKING, CALL, CREW = 12208, 39322, 77
STORED_TYPE = {"call_id": CALL, "booking_id": BOOKING, "role_name": "Show Crew",
               "set_code": "standard", "source": "admin", "set_by_user_id": 1, "set_at": "x"}


class UnchangedSaves(unittest.TestCase):
    def setUp(self):
        self._saved = {n: getattr(A, n) for n in (
            "get_ss_session", "ss_get_call_times", "booking_set_code", "fetch_call_types", "_post_call_types")}
        self._saved_rules = pricing_rules.load_rules
        with open(os.path.join(HERE, "pricing_rules_cache.json")) as f:
            rules = json.load(f)
        rules = rules.get("rules", rules)
        pricing_rules.load_rules = lambda force=False: (rules, {})
        A.get_ss_session = lambda: object()
        A.ss_get_call_times = lambda ss, cid: ({
            "booking_id": BOOKING, "call_name": "Show Call", "crew": [{"user_id": CREW}],
            "paygrades": [{"id": 20, "day_desc": "Crew Boss", "rate": "50.13"},
                          {"id": 43, "day_desc": "Steel Hand/SPH", "rate": "66.45"}]}, None)
        A.booking_set_code = lambda bid: ("standard", None)
        self.overrides = []
        A.fetch_call_types = lambda booking_ids=None, call_ids=None: (
            {"call_types": {CALL: STORED_TYPE}, "overrides": list(self.overrides)}, None)
        self.posts = []
        A._post_call_types = lambda path, body, what: (self.posts.append((path, body)) or {"logged": True}, None)
        A._ss_identity["sid"] = {"cohort": "admin", "user_id": "10261"}
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "sid"

    def tearDown(self):
        for n, v in self._saved.items():
            setattr(A, n, v)
        pricing_rules.load_rules = self._saved_rules
        A._ss_identity.pop("sid", None)

    def override(self, **kw):
        return dict({"call_id": CALL, "user_id": CREW, "booking_id": BOOKING, "set_by_user_id": 1,
                     "set_at": "x"}, **kw)

    def test_same_override_twice_writes_nothing(self):
        self.overrides = [self.override(kind="manual", charge_role="Crew Boss", pay_paygrade_id=None)]
        r = self.client.post(f"/api/call/{BOOKING}/{CALL}/override/{CREW}", json={"charge_role": "Crew Boss"})
        self.assertEqual(r.get_json(), {"ok": True, "unchanged": True})
        self.assertEqual(self.posts, [])

    def test_a_different_override_is_still_written(self):
        self.overrides = [self.override(kind="manual", charge_role="Crew Boss", pay_paygrade_id=None)]
        r = self.client.post(f"/api/call/{BOOKING}/{CALL}/override/{CREW}",
                             json={"charge_role": "Crew Boss", "pay_paygrade_id": 43})
        self.assertEqual(r.get_json(), {"ok": True})
        self.assertEqual([p for p, _ in self.posts], ["/override"])

    def test_same_call_type_writes_nothing_a_new_one_is_written(self):
        r = self.client.post(f"/api/call/{BOOKING}/{CALL}/call-type", json={"role_name": "Show Crew"})
        self.assertTrue(r.get_json().get("unchanged"))
        self.assertEqual(self.posts, [])
        r = self.client.post(f"/api/call/{BOOKING}/{CALL}/call-type", json={"role_name": "Standard Crew"})
        self.assertNotIn("unchanged", r.get_json())
        self.assertEqual([p for p, _ in self.posts], ["/type"])

    def test_crew_boss_on_a_crew_boss_row_writes_nothing(self):
        self.overrides = [self.override(kind="crew_boss", charge_role="Crew Boss", pay_paygrade_id=20)]
        r = self.client.post(f"/api/call/{BOOKING}/{CALL}/crew-boss/{CREW}")
        self.assertEqual(r.get_json(), {"ok": True, "unchanged": True})
        self.assertEqual(self.posts, [])

    def test_manual_override_with_crew_boss_values_becomes_crew_boss(self):
        # Same values, different kind: a real change (it gets the CREW BOSS chip).
        self.overrides = [self.override(kind="manual", charge_role="Crew Boss", pay_paygrade_id=20)]
        self.client.post(f"/api/call/{BOOKING}/{CALL}/crew-boss/{CREW}")
        self.assertEqual([p for p, _ in self.posts], ["/override"])


if __name__ == "__main__":
    unittest.main()

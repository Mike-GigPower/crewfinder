"""Who set a Call Type or an override is shown by name, not "#10261".

Names come from the crew cache (admins are in it) and the signed-in
person's own identity; an unknown ID is left out so the UI shows #id.
Drives /api/call/<b>/<c>/pricing with SmartStaff and Supabase stubbed."""

import json
import os
import unittest

import app as A
import pricing_rules

HERE = os.path.dirname(os.path.abspath(__file__))
CREW = {"E1": {"user_id": 10261, "name": "Mike Lack"},
        "E2": {"user_id": 555, "name": "Rich Example"}}


class SetByNames(unittest.TestCase):
    def setUp(self):
        self._saved = {n: getattr(A, n) for n in (
            "load_cache", "get_ss_session", "ss_get_call_times", "booking_set_code", "fetch_call_types")}
        self._saved_rules = pricing_rules.load_rules
        with open(os.path.join(HERE, "pricing_rules_cache.json")) as f:
            rules = json.load(f)
        rules = rules.get("rules", rules)
        pricing_rules.load_rules = lambda force=False: (rules, {})
        A.load_cache = lambda: (CREW, False)          # stale is fine for names
        A.get_ss_session = lambda: object()
        A.ss_get_call_times = lambda ss, cid: ({"booking_id": 12208, "call_name": "Load In",
                                                "crew": [{"user_id": 77}], "paygrades": []}, None)
        A.booking_set_code = lambda bid: ("standard", None)
        A.fetch_call_types = lambda booking_ids=None, call_ids=None: ({
            "call_types": {39321: {"call_id": 39321, "booking_id": 12208, "role_name": "Standard Crew",
                                   "source": "manual_booking", "set_by_user_id": 10261, "set_at": "x"}},
            "overrides": [{"call_id": 39321, "user_id": 77, "kind": "manual", "charge_role": "Show Crew",
                           "pay_paygrade_id": None, "set_by_user_id": 999, "set_at": "x"}]}, None)
        A._ss_identity["sid"] = {"cohort": "admin", "user_id": "555", "name": "Rich (signed in)"}
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "sid"

    def tearDown(self):
        for n, v in self._saved.items():
            setattr(A, n, v)
        pricing_rules.load_rules = self._saved_rules
        A._ss_identity.pop("sid", None)

    def test_pricing_names_the_setter_and_leaves_unknowns(self):
        j = self.client.get("/api/call/12208/39321/pricing").get_json()
        self.assertEqual(j["call_type"]["set_by_name"], "Mike Lack")
        self.assertIsNone(j["overrides"][0]["set_by_name"])    # 999 is unknown -> "#999"

    def test_user_names_for(self):
        with A.app.test_request_context():
            from flask import session
            session["sid"] = "sid"
            self.assertEqual(A.user_names_for([10261, "555", 999, None]),
                             {10261: "Mike Lack", 555: "Rich (signed in)"})


if __name__ == "__main__":
    unittest.main()

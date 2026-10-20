"""THE GOAT run from source with no rate card key (21 Oct 2026).

Running the branch from source, call #39180 (booking 12175) showed "Call Type:
Unavailable (Rate card service unavailable)" and the booking dialog had no
chips, yet Supabase showed no failed request and call-types was never called.
The source run's config.json had no goat_rate_card_key (build_secrets.json is
merged only into the DMG's config), so fetch_booking_rate_cards returned "rate
card key not configured" WITHOUT sending anything, and the routes reported
that as the service being unavailable.

These drive the real fetch helpers with the HTTP layer recorded, SmartStaff
stubbed, and no network."""

import json
import os
import unittest

import app as A
import pricing_rules

HERE = os.path.dirname(os.path.abspath(__file__))
BOOKING, CALL, CREW = 12175, 39180, 77


class _Resp:
    def __init__(self, body):
        self.status_code = 200
        self._body = body

    def json(self):
        return self._body


class RateCardKeyMissing(unittest.TestCase):
    def setUp(self):
        self._saved = {n: getattr(A, n) for n in ("get_ss_session", "ss_get_call_times",
                                                  "GOAT_RATE_CARD_KEY")}
        self._saved_get = A.http.get
        self._saved_rules = pricing_rules.load_rules
        self.sent = []

        def fake_get(url, params=None, **kw):
            self.sent.append(url)
            if url == A.BOOKING_RATE_CARD_URL:
                return _Resp({"rows": []})
            return _Resp({"call_types": [], "overrides": []})

        A.http.get = fake_get
        A.get_ss_session = lambda: object()
        A.ss_get_call_times = lambda ss, call_id: ({
            "booking_id": BOOKING, "call_name": "Load In",
            "crew": [{"user_id": CREW}],
            "paygrades": [{"id": 20, "day_desc": "Crew Boss", "rate": "52"}]}, None)
        cache = os.path.join(HERE, "pricing_rules_cache.json")
        with open(cache) as f:
            rules = json.load(f)
        rules = rules.get("rules", rules)
        pricing_rules.load_rules = lambda force=False: (rules, {})
        A._ss_identity["test-sid"] = {"cohort": "admin", "user_id": "42"}
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "test-sid"

    def tearDown(self):
        for n, v in self._saved.items():
            setattr(A, n, v)
        A.http.get = self._saved_get
        pricing_rules.load_rules = self._saved_rules
        A._ss_identity.pop("test-sid", None)

    def test_call_dialog_names_the_missing_key_and_sends_nothing(self):
        A.GOAT_RATE_CARD_KEY = ""
        r = self.client.get(f"/api/call/{BOOKING}/{CALL}/pricing")
        err = (r.get_json() or {}).get("error", "")
        self.assertEqual(r.status_code, 503)
        self.assertNotEqual(err, "Rate card service unavailable")
        self.assertIn("goat_rate_card_key", err)
        self.assertEqual(self.sent, [])   # nothing left the machine

    def test_booking_chips_name_the_missing_key(self):
        A.GOAT_RATE_CARD_KEY = ""
        r = self.client.post(f"/api/booking/{BOOKING}/call-types",
                             json={"calls": [{"call_id": CALL, "call_name": "Load In"}]})
        self.assertEqual(r.status_code, 503)
        self.assertIn("goat_rate_card_key", r.get_json()["error"])
        self.assertEqual(self.sent, [])

    def test_with_the_key_both_services_are_called_and_the_type_is_derived(self):
        A.GOAT_RATE_CARD_KEY = "test-key"
        r = self.client.get(f"/api/call/{BOOKING}/{CALL}/pricing")
        self.assertEqual(r.status_code, 200, r.get_json())
        self.assertEqual(self.sent, [A.BOOKING_RATE_CARD_URL, A.CALL_TYPES_URL])
        ct = r.get_json()["call_type"]
        self.assertEqual((ct["role"], ct["source"]), ("Standard Crew", "derived"))

    def test_a_real_service_failure_still_says_unavailable(self):
        self.assertEqual(A.rate_card_read_failure("HTTP 500"), ("Rate card service unavailable", 502))
        self.assertEqual(A.rate_card_read_failure("HTTP 500", "Call types service")[0],
                         "Call types service unavailable")


if __name__ == "__main__":
    unittest.main()

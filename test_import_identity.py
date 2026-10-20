"""The estimate import's background thread and who is importing.

run_import runs in a thread with no Flask request, so it can't read the
session. Before the fix, save_booking_rate_card asked current_user_id() from
inside that thread, which raised "Working outside of request context": the
import ended with "Unexpected error" after creating the booking and calls,
without saving the rate card or writing import_log.json.

This drives /api/import/start through the REAL session -> identity path, with
SmartStaff, the rate card service and the import log stubbed. No network."""

import json
import os
import time
import unittest

import app as A
import pricing_rules

HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURE = os.path.join(HERE, "fixtures", "estimate-1.2-standard.json")


class _Resp:
    status_code = 200

    @staticmethod
    def json():
        return {"logged": True, "set_code": "standard"}


class ImportIdentity(unittest.TestCase):
    def setUp(self):
        self._saved = {n: getattr(A, n) for n in (
            "get_ss_session", "fetch_import_lookups", "find_import_log_entry",
            "load_import_log", "save_import_log", "ss_create_booking_bulk",
            "GOAT_RATE_CARD_KEY")}
        self._saved_post = A.http.post
        self._saved_rules = pricing_rules.load_rules
        self.posts, self.logged = [], []

        A.get_ss_session = lambda: object()
        A.fetch_import_lookups = lambda ss: (
            {"customers": [], "venues": [], "contacts": [], "customer_map": []}, None)
        A.find_import_log_entry = lambda eid: None
        A.load_import_log = lambda: []
        A.save_import_log = lambda log: self.logged.extend(log)
        A.ss_create_booking_bulk = lambda ss, b, calls: (
            {"booking_id": 9000, "call_ids": list(range(9001, 9001 + len(calls))),
             "call_errors": []}, None)
        A.GOAT_RATE_CARD_KEY = "test-key"
        A.http.post = lambda url, json=None, **kw: (self.posts.append((url, json)) or _Resp())
        cache = os.path.join(HERE, "pricing_rules_cache.json")
        rules = None
        if os.path.exists(cache):
            with open(cache) as f:
                rules = json.load(f)
            rules = rules.get("rules", rules)
        pricing_rules.load_rules = lambda force=False: (rules, {})

        A._ss_identity["test-sid"] = {"cohort": "admin", "user_id": "42"}

    def tearDown(self):
        for n, v in self._saved.items():
            setattr(A, n, v)
        A.http.post = self._saved_post
        pricing_rules.load_rules = self._saved_rules
        A._ss_identity.pop("test-sid", None)

    def test_import_attributes_the_rate_card_without_the_request(self):
        with open(FIXTURE) as f:
            payload = json.load(f)
        client = A.app.test_client()
        with client.session_transaction() as s:
            s["sid"] = "test-sid"
        r = client.post("/api/import/start",
                        json={"payload": payload, "customer_id": 1, "venue_id": 1})
        self.assertEqual(r.status_code, 200, r.get_json())
        for _ in range(100):
            if not A._import_progress.get("running"):
                break
            time.sleep(0.05)
        p = A._import_progress
        self.assertEqual(p.get("errors"), [])
        self.assertEqual(p.get("step"), "Complete")
        self.assertIsNone(p.get("rate_card_error"))
        rc = [body for url, body in self.posts if url == A.BOOKING_RATE_CARD_URL]
        self.assertEqual(len(rc), 1)
        self.assertEqual(rc[0]["set_by_user_id"], 42)
        self.assertEqual(len(self.logged), 1)   # import_log.json written


if __name__ == "__main__":
    unittest.main()

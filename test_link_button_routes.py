"""The Link button's two proxy routes (BRIEF-default-link-self-submit-off,
Release 2, C1): /api/booking/<id>/link-crew-boss -> auto-link-supervision.php
and /api/calls/no-crew-boss -> no-crew-boss.php.

  - Admin only (J18): every other cohort gets 403 and nothing reaches
    SmartStaff. A control as Admin shows the recorder does see the request,
    so an empty record for the others means something.
  - The body that reaches SmartStaff is the cleaned one: booking_id from the
    URL as an int, ids as positive ints, junk dropped.
  - 404, 409 and 422 come back as they came (they are answers), anything
    else that is not a 200 is a 502 carrying the endpoint's error.
  - Bad input is refused here, before SmartStaff is asked.
"""

import unittest

import app as A

BOOKING = 11827


class _Resp:
    def __init__(self, status, body):
        self.status_code = status
        self._body = body

    def json(self):
        return self._body


class _SmartStaff:
    """A logged-in SmartStaff session that records each POST and answers with
    whatever the test queued."""

    def __init__(self):
        self.sent = []
        self.reply = (200, {"ok": True})

    def post(self, url, *a, **kw):
        self.sent.append((url.rsplit("/", 1)[-1], kw.get("json")))
        return _Resp(*self.reply)

    def get(self, url, *a, **kw):
        self.sent.append((url.rsplit("/", 1)[-1], None))
        return _Resp(*self.reply)


class LinkButtonRoutes(unittest.TestCase):
    def setUp(self):
        self._saved = A.get_ss_session
        self.ss = _SmartStaff()
        A.get_ss_session = lambda: self.ss
        self.client = A.app.test_client()
        with self.client.session_transaction() as s:
            s["sid"] = "link-sid"

    def tearDown(self):
        A.get_ss_session = self._saved
        A._ss_identity.pop("link-sid", None)

    def as_cohort(self, cohort):
        A._ss_identity["link-sid"] = {"cohort": cohort, "user_id": "2973"}

    def link(self, body):
        return self.client.post(f"/api/booking/{BOOKING}/link-crew-boss", json=body)

    def mark(self, body):
        return self.client.post("/api/calls/no-crew-boss", json=body)

    # ---- J18 ----

    def test_non_admins_are_refused_and_nothing_is_sent(self):
        for cohort in ("operations", "leadership", "crew"):
            self.as_cohort(cohort)
            for label, call in (("dry_run", lambda: self.link({"mode": "dry_run"})),
                                ("apply", lambda: self.link({"mode": "apply", "link": [1]})),
                                ("mark", lambda: self.mark({"action": "set", "call_ids": [1]})),
                                ("undo", lambda: self.mark({"action": "clear", "call_ids": [1]}))):
                with self.subTest(cohort=cohort, route=label):
                    self.ss.sent.clear()
                    r = call()
                    self.assertEqual(r.status_code, 403)
                    self.assertEqual(self.ss.sent, [], "nothing may leave THE GOAT")

    def test_control_admin_reaches_smartstaff(self):
        self.as_cohort("admin")
        r = self.link({"mode": "dry_run"})
        self.assertEqual(r.status_code, 200)
        self.assertEqual(self.ss.sent, [("auto-link-supervision.php", {"mode": "dry_run", "booking_id": BOOKING})])

    # ---- the body that is sent ----

    def test_apply_sends_clean_int_ids(self):
        self.as_cohort("admin")
        self.link({"mode": "apply", "link": ["900003", 900004, "x", -2, 900004, None], "mark": [37882, "0"]})
        self.assertEqual(self.ss.sent, [("auto-link-supervision.php",
                                         {"mode": "apply", "booking_id": BOOKING,
                                          "link": [900003, 900004], "mark": [37882]})])

    def test_dry_run_sends_no_lists(self):
        self.as_cohort("admin")
        self.link({"mode": "dry_run", "link": [1], "mark": [2]})
        self.assertEqual(self.ss.sent[0][1], {"mode": "dry_run", "booking_id": BOOKING})

    def test_no_crew_boss_sends_action_and_ids(self):
        self.as_cohort("admin")
        self.mark({"action": "clear", "call_ids": ["37882", 37882, "nope"]})
        self.assertEqual(self.ss.sent, [("no-crew-boss.php", {"action": "clear", "call_ids": [37882]})])

    # ---- refused here, nothing sent ----

    def test_bad_input_is_refused_before_smartstaff(self):
        self.as_cohort("admin")
        cases = (
            ("bad mode", lambda: self.link({"mode": "delete"})),
            ("apply with nothing", lambda: self.link({"mode": "apply", "link": ["x"], "mark": []})),
            ("bad booking id", lambda: self.client.post("/api/booking/abc/link-crew-boss", json={"mode": "dry_run"})),
            ("bad action", lambda: self.mark({"action": "drop", "call_ids": [1]})),
            ("no ids", lambda: self.mark({"action": "set", "call_ids": ["a"]})),
        )
        for label, call in cases:
            with self.subTest(case=label):
                self.ss.sent.clear()
                r = call()
                self.assertEqual(r.status_code, 400)
                self.assertEqual(self.ss.sent, [])

    # ---- status pass-through ----

    def test_answers_pass_through_with_their_status(self):
        self.as_cohort("admin")
        for status in (404, 409, 422):
            with self.subTest(status=status):
                self.ss.reply = (status, {"error": "said by SmartStaff"})
                r = self.link({"mode": "apply", "link": [1]})
                self.assertEqual(r.status_code, status)
                self.assertEqual(r.get_json(), {"error": "said by SmartStaff"})

    def test_a_lock_409_reaches_the_browser_as_409(self):
        self.as_cohort("admin")
        self.ss.reply = (409, {"error": "Someone else is linking this booking — try again."})
        r = self.link({"mode": "apply", "link": [900003]})
        self.assertEqual(r.status_code, 409)
        self.assertIn("Someone else", r.get_json()["error"])

    def test_other_failures_are_502_with_the_endpoint_error(self):
        self.as_cohort("admin")
        for status in (500, 403):
            with self.subTest(status=status):
                self.ss.reply = (status, {"error": "lookup failed"})
                r = self.mark({"action": "set", "call_ids": [1]})
                self.assertEqual(r.status_code, 502)
                self.assertEqual(r.get_json(), {"error": "lookup failed"})

    def test_per_call_results_come_back_whole(self):
        self.as_cohort("admin")
        body = {"ok": True, "mode": "apply", "booking_id": BOOKING,
                "results": [{"call_id": 900003, "action": "link", "result": "refused",
                             "reason": "Already linked to Crew Boss call #900001.", "boss_call": 0, "note": ""}],
                "counts": {"linked": 0, "marked": 0, "refused": 1}}
        self.ss.reply = (200, body)
        r = self.link({"mode": "apply", "link": [900003]})
        self.assertEqual(r.status_code, 200)
        self.assertEqual(r.get_json(), body)


if __name__ == "__main__":
    unittest.main()

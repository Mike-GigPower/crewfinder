#!/usr/bin/env python3
"""Tests for Change pay grade's Flask routes (BRIEF-promotion-paygrade-slice1 §4.1).

Drives the REAL routes through a Flask test client. SmartStaff is faked at the
HTTP boundary (a fake session answering preview-grade-change.php and
apply-grade-change.php from canned dicts), and the grade-change-log edge
function is faked by patching http.post. Nothing leaves this machine.

Run:  python3 test_grade_change_routes.py
"""
import unittest
from unittest import mock

import app as A

UID = 9734

PREVIEW = {
    "ok": True,
    "person": {"user_id": UID, "ein": "5925", "name": "O'Lack, Mike", "active": True,
               "current_grade": 10, "current_grade_name": "T1 / T2", "current_on_ladder": True},
    "new_grade": 26, "new_grade_name": "T1B / T2B", "next_step": True, "same_grade": False,
    "wef": "2026-11-17", "today": "2026-11-17", "pay_period_start": "2026-11-17",
    "rows": [
        {"call_id": 101, "booking_id": 1, "call_name": "Show", "call_date": "2026-11-20", "status": 5,
         "from_grade": 10, "to_grade": 26, "direction": "up", "default_ticked": True},
        {"call_id": 102, "booking_id": 1, "call_name": "Show", "call_date": "2026-11-21", "status": 7,
         "from_grade": 38, "to_grade": 39, "direction": "up", "default_ticked": True},
        {"call_id": 103, "booking_id": 1, "call_name": "Lead", "call_date": "2026-11-22", "status": 5,
         "from_grade": 25, "to_grade": 26, "direction": "down", "default_ticked": False},
    ],
    "before_wef": [{"call_id": 90, "booking_id": 1, "call_name": "Old", "call_date": "2026-11-16",
                    "status": 5, "from_grade": 10, "to_grade": 26, "direction": "up"}],
    "locked": [{"call_id": 95, "booking_id": 1, "call_name": "Locked", "call_date": "2026-11-17",
                "status": 5, "from_grade": 10, "to_grade": 26, "direction": "up"}],
    "ladder": [],
}


class FakeResp:
    def __init__(self, code, data):
        self.status_code = code
        self._d = data

    def json(self):
        return self._d


class FakeSS:
    """Answers the two GOAT endpoints; records every call."""
    def __init__(self, t):
        self.t = t

    def get(self, url, params=None, timeout=None):
        self.t.calls.append(("GET", url.rsplit("/", 1)[-1], dict(params or {})))
        return FakeResp(*self.t.preview_answer)

    def post(self, url, json=None, timeout=None):
        self.t.calls.append(("POST", url.rsplit("/", 1)[-1], json))
        return FakeResp(*self.t.apply_answer)


def call(method, path, body=None, cohort="admin"):
    with A.app.test_client() as c:
        with c.session_transaction() as s:
            s["sid"] = "h"
        with mock.patch.dict(A._ss_identity, {"h": {"cohort": cohort, "usergroupID": 1, "user_id": 42}}):
            r = c.open(path, method=method, json=body)
        return r.status_code, r.get_json()


class GradeChangeRoutes(unittest.TestCase):
    def setUp(self):
        self.calls, self.logs = [], []
        self.preview_answer = (200, PREVIEW)
        self.apply_answer = (200, {"ok": True, "grade_changed": True, "from_grade": 10, "to_grade": 26,
                                   "wef": "2026-11-17",
                                   "updated": [{"call_id": 101, "from_grade": 10, "to_grade": 26}],
                                   "skipped": [{"call_id": 102, "reason": "locked"}]})
        self.log_answer = (200, {"id": 7, "rows_logged": True})
        ss = FakeSS(self)

        def fake_http_post(url, json=None, headers=None, timeout=None):
            self.logs.append((url, json, headers))
            return FakeResp(*self.log_answer)

        for p in (mock.patch.multiple(A, get_ss_session=lambda: ss, GOAT_RATE_CARD_KEY="k"),
                  mock.patch.dict(A._ss_sessions, {"h": ss}),
                  mock.patch.object(A.http, "post", fake_http_post)):
            p.start()
            self.addCleanup(p.stop)

    def test_preview_proxies(self):
        code, d = call("GET", f"/api/admin/crew/{UID}/grade-change/preview?new_grade=26&wef=2026-11-17")
        self.assertEqual(code, 200)
        self.assertEqual(self.calls[0][2], {"user_id": UID, "new_grade": 26, "wef": "2026-11-17"})

    def test_preview_marks_kept_rows_unticked(self):
        self.log_answer = (200, {"call_ids": [101]})
        code, d = call("GET", f"/api/admin/crew/{UID}/grade-change/preview?new_grade=26")
        r = {x["call_id"]: x for x in d["rows"]}
        self.assertTrue(r[101]["kept_before"] and r[101]["default_ticked"] is False)
        self.assertTrue(r[102]["default_ticked"] and "kept_before" not in r[102])
        self.assertEqual(self.logs[0][1], {"mode": "kept", "user_id": UID, "grade": 26})

    def test_preview_passes_400_through(self):
        self.preview_answer = (400, {"error": "WEF must be…", "code": "wef_out_of_range"})
        code, d = call("GET", f"/api/admin/crew/{UID}/grade-change/preview?new_grade=26&wef=2026-01-01")
        self.assertEqual((code, d["code"]), (400, "wef_out_of_range"))

    def test_non_admin_forbidden(self):
        for m, p in (("GET", f"/api/admin/crew/{UID}/grade-change/preview?new_grade=26"),
                     ("POST", f"/api/admin/crew/{UID}/grade-change"),
                     ("GET", f"/api/admin/crew/{UID}/grade-change/stale")):
            code, _ = call(m, p, {} if m == "POST" else None, cohort="operations")
            self.assertEqual(code, 403, p)
        self.assertEqual(self.calls, [])

    def test_apply_sends_server_side_choices_and_logs(self):
        code, d = call("POST", f"/api/admin/crew/{UID}/grade-change",
                       {"new_grade": 26, "wef": "2026-11-17", "call_ids": [101, 102, 101, "x"],
                        "expected_current_grade": 10})
        self.assertEqual(code, 200, d)
        post = [c for c in self.calls if c[0] == "POST"][0]
        self.assertEqual(post[2], {"user_id": UID, "new_grade": 26, "wef": "2026-11-17",
                                   "call_ids": [101, 102], "expected_current_grade": 10})
        url, body, headers = self.logs[0]
        self.assertTrue(url.endswith("/grade-change-log"))
        self.assertEqual(headers["X-Goat-Service-Key"], "k")
        self.assertEqual(body["mode"], "log")
        self.assertEqual(body["applied_by"], 42)          # from the session, never the browser
        actions = {r["call_id"]: (r["action"], r.get("reason")) for r in body["rows"]}
        self.assertEqual(actions, {101: ("updated", None), 102: ("skipped", "locked"),
                                   103: ("kept", None), 90: ("before_wef", None), 95: ("locked", None)})
        self.assertEqual(d["log_id"], 7)
        self.assertIn("probation_note", d)               # promoted off T1
        self.assertEqual([r["call_id"] for r in d["locked"]], [95])

    def test_apply_conflict_passed_through_and_not_logged(self):
        self.apply_answer = (409, {"error": "changed", "code": "grade_changed", "current_grade": 26})
        code, d = call("POST", f"/api/admin/crew/{UID}/grade-change",
                       {"new_grade": 26, "wef": "2026-11-17", "call_ids": [101], "expected_current_grade": 10})
        self.assertEqual((code, d["code"]), (409, "grade_changed"))
        self.assertEqual(self.logs, [])

    def test_log_failure_is_not_a_failed_change(self):
        self.log_answer = (500, {"error": "boom"})
        code, d = call("POST", f"/api/admin/crew/{UID}/grade-change",
                       {"new_grade": 26, "wef": "2026-11-17", "call_ids": [101], "expected_current_grade": 10})
        self.assertEqual(code, 200)
        self.assertTrue(d["grade_changed"])
        self.assertIn("log_error", d)

    def test_apply_needs_expected_grade(self):
        code, d = call("POST", f"/api/admin/crew/{UID}/grade-change",
                       {"new_grade": 26, "wef": "2026-11-17", "call_ids": [101]})
        self.assertEqual(code, 400)
        self.assertEqual(self.calls, [])

    def test_stale_counts_only_rows_below_grade_minus_kept(self):
        with mock.patch.object(A, "ss_get_crew", lambda ss, cid: ({"paygrade_id": 10}, None)):
            self.log_answer = (200, {"call_ids": [102]})
            code, d = call("GET", f"/api/admin/crew/{UID}/grade-change/stale")
        self.assertEqual(code, 200)
        # 101 counts; 102 was kept; 103 is above their grade (deliberate, D12)
        self.assertEqual(d, {"count": 1, "grade": 10})
        self.assertEqual(self.calls[0][2]["new_grade"], 10)

    def test_stale_off_ladder_or_failure_is_zero(self):
        with mock.patch.object(A, "ss_get_crew", lambda ss, cid: ({"paygrade_id": 12}, None)):
            self.assertEqual(call("GET", f"/api/admin/crew/{UID}/grade-change/stale")[1], {"count": 0})
        with mock.patch.object(A, "ss_get_crew", lambda ss, cid: (None, "HTTP 500")):
            self.assertEqual(call("GET", f"/api/admin/crew/{UID}/grade-change/stale")[1], {"count": 0})

    def test_crew_update_still_cannot_write_grade(self):
        import inspect
        src = inspect.getsource(A.api_admin_update_crew)
        self.assertNotIn("paygrade", src)


if __name__ == "__main__":
    unittest.main(verbosity=2)

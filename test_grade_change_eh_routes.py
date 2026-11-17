#!/usr/bin/env python3
"""Tests for Change pay grade, Slice 2 (Employment Hero) Flask routes.

Drives the REAL routes through a Flask test client. SmartStaff's get-crew is
faked via ss_get_crew, and the keypay-pay-level edge function is faked by
patching http.post. Nothing leaves this machine.

Run:  python3 test_grade_change_eh_routes.py
"""
import unittest
from unittest import mock

import app as A

UID = 9734
EH_PREVIEW = {"ok": True, "ein": "5925", "employee_id": 14267720, "verdict": "ready",
              "before_hash": "abc", "write_enabled": True, "write_allowlisted": True,
              "diff": [], "employee": {"payRateTemplate": "Casual - Level 1"}}


class FakeResp:
    def __init__(self, code, data):
        self.status_code = code
        self._d = data

    def json(self):
        return self._d


def call(method, path, body=None, cohort="admin"):
    with A.app.test_client() as c:
        with c.session_transaction() as s:
            s["sid"] = "h"
        with mock.patch.dict(A._ss_identity, {"h": {"cohort": cohort, "usergroupID": 1, "user_id": 42}}):
            r = c.open(path, method=method, json=body)
        return r.status_code, r.get_json()


class EhRoutes(unittest.TestCase):
    def setUp(self):
        self.posts = []
        self.edge_answer = (200, EH_PREVIEW)
        self.crew = ({"ein": "5925", "paygrade_id": 26}, None)

        def fake_http_post(url, json=None, headers=None, timeout=None):
            self.posts.append((url, json, headers, timeout))
            if isinstance(self.edge_answer, Exception):
                raise self.edge_answer
            return FakeResp(*self.edge_answer)

        ss = object()
        for p in (mock.patch.multiple(A, get_ss_session=lambda: ss, GOAT_RECRUITMENT_KEY="rk",
                                      ss_get_crew=lambda s, cid: self.crew),
                  mock.patch.dict(A._ss_sessions, {"h": ss}),
                  mock.patch.object(A.http, "post", fake_http_post)):
            p.start()
            self.addCleanup(p.stop)

    # ── preview ──
    def test_preview_sends_ein_from_smartstaff_not_browser(self):
        code, d = call("GET", f"/api/admin/crew/{UID}/grade-change/eh-preview?new_grade=26&ein=1")
        self.assertEqual(code, 200)
        url, body, headers, _ = self.posts[0]
        self.assertTrue(url.endswith("/keypay-pay-level"))
        self.assertEqual(body, {"mode": "preview", "ein": "5925", "new_grade": 26})
        self.assertEqual(headers["X-Goat-Service-Key"], "rk")

    def test_preview_rejects_off_ladder_grade(self):
        code, _ = call("GET", f"/api/admin/crew/{UID}/grade-change/eh-preview?new_grade=12")
        self.assertEqual(code, 400)
        self.assertEqual(self.posts, [])

    def test_preview_no_ein_is_a_soft_answer(self):
        self.crew = ({"ein": "", "paygrade_id": 26}, None)
        code, d = call("GET", f"/api/admin/crew/{UID}/grade-change/eh-preview?new_grade=26")
        self.assertEqual((code, d["ok"], d["code"]), (200, False, "NO_EIN"))
        self.assertEqual(self.posts, [])

    def test_preview_unreachable_is_502(self):
        self.edge_answer = ConnectionError("down")
        code, d = call("GET", f"/api/admin/crew/{UID}/grade-change/eh-preview?new_grade=26")
        self.assertEqual((code, d["code"]), (502, "EH_UNREACHABLE"))

    # ── commit ──
    def body(self, **kw):
        b = {"new_grade": 26, "employee_id": 14267720, "before_hash": "abc", "log_id": 7}
        b.update(kw)
        return b

    def test_commit_payload_built_server_side(self):
        self.edge_answer = (200, {"ok": True, "code": "UPDATED"})
        code, d = call("POST", f"/api/admin/crew/{UID}/grade-change/eh", self.body(ein="1", acting_user_id="999"))
        self.assertEqual(code, 200, d)
        _, body, _, timeout = self.posts[0]
        self.assertEqual(body, {"mode": "commit", "ein": "5925", "new_grade": 26, "employee_id": 14267720,
                                "before_hash": "abc", "acting_user_id": "42", "log_id": 7})
        self.assertGreaterEqual(timeout, 120)

    def test_commit_refuses_when_smartstaff_not_on_grade(self):
        self.crew = ({"ein": "5925", "paygrade_id": 10}, None)
        code, d = call("POST", f"/api/admin/crew/{UID}/grade-change/eh", self.body())
        self.assertEqual((code, d["code"]), (409, "SMARTSTAFF_NOT_CHANGED"))
        self.assertEqual(self.posts, [])

    def test_commit_requires_fields(self):
        for missing in ("employee_id", "before_hash", "new_grade"):
            b = self.body()
            b.pop(missing)
            code, _ = call("POST", f"/api/admin/crew/{UID}/grade-change/eh", b)
            self.assertEqual(code, 400, missing)
        self.assertEqual(self.posts, [])

    def test_commit_passes_edge_conflict_through(self):
        self.edge_answer = (409, {"ok": False, "code": "STALE_PREVIEW", "error": "changed"})
        code, d = call("POST", f"/api/admin/crew/{UID}/grade-change/eh", self.body())
        self.assertEqual((code, d["code"]), (409, "STALE_PREVIEW"))

    def test_commit_unreachable_says_outcome_unknown(self):
        self.edge_answer = TimeoutError("slow")
        code, d = call("POST", f"/api/admin/crew/{UID}/grade-change/eh", self.body())
        self.assertEqual(code, 502)
        self.assertIn("may or may not", d["error"])

    def test_non_admin_forbidden(self):
        for m, p in (("GET", f"/api/admin/crew/{UID}/grade-change/eh-preview?new_grade=26"),
                     ("POST", f"/api/admin/crew/{UID}/grade-change/eh")):
            code, _ = call(m, p, self.body() if m == "POST" else None, cohort="operations")
            self.assertEqual(code, 403, p)
        self.assertEqual(self.posts, [])


if __name__ == "__main__":
    unittest.main(verbosity=2)

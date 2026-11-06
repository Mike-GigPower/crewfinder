#!/usr/bin/env python3
"""Tests for re-offering a declined crew member (DESIGN-reoffer-declined-v0_1).

Drives the REAL route through a Flask test client with SmartStaff faked:
  POST /api/call/<b>/<c>/crew/<u>/reoffer   {dry_run}

and the REAL gp_notify_offer with the HTTP post faked, for the dedup and
force=True behaviour.

Run (no network and no login required):
    python3 test_reoffer_declined.py

Everything replaced in app (get_ss_session, fetch_booking_bulk,
ss_reoffer_declined, gp_notify_offer, http.post) is patched in setUp and put
back by addCleanup, so nothing leaks into test modules loaded after this one.

Booking 100:
  1 Load in   Sat 7 Nov 2026 08:00, 4 h  ─locked─▶  2 Load out  Sat 14:00, 3.5 h
  Crew member 60 declined both. 3 is a call in ANOTHER booking.
"""
import ast
import inspect
import unittest
from datetime import datetime
from unittest import mock

import app as A


SAT = int(datetime(2026, 11, 7).timestamp())   # midnight, local, as get-booking.php sends it

BOOKING = {"name": "Test Show", "venue": {"name": "Rod Laver"}, "calls": [
    {"call_id": 1, "call_name": "Load in",  "start_date": SAT, "start_time": "08:00:00", "est_length": "4"},
    {"call_id": 2, "call_name": "Load out", "start_date": SAT, "start_time": "14:00:00", "est_length": "3.5"},
]}

def sm_call(cid, name, start, **kw):
    c = {"call_id": cid, "call_name": name, "start": start, "started": False,
         "cancelled": False, "full": False, "required": 10, "confirmed": 2}
    c.update(kw)
    return c

PACKAGE = [sm_call(1, "Load in", "2026-11-07T08:00:00"),
           sm_call(2, "Load out", "2026-11-07T14:00:00")]

events = []   # ("reoffer", call_id, user_id, dry_run) and ("push", user_id, call, force), in order


class FakeSS:
    pass


def post(path, body):
    with A.app.test_client() as c:
        with c.session_transaction() as s:
            s["sid"] = "h"
        r = c.post(path, json=body)
        return r.status_code, r.get_json()


URL = "/api/call/100/1/crew/60/reoffer"


class ReofferRoute(unittest.TestCase):
    """The route: proxy, then push with force=True on a real apply."""

    # What the faked reoffer-declined.php answers, per test.
    sm_dry = None
    sm_apply = None
    push_ok = True

    def setUp(self):
        events.clear()
        self.sm_dry = ({"ok": True, "dry_run": True, "call_id": 1, "user_id": 60,
                        "responded_at": "2026-11-05T21:14:00", "calls": PACKAGE,
                        "refused": None}, None)
        self.sm_apply = ({"ok": True, "dry_run": False, "call_id": 1, "user_id": 60,
                          "calls": PACKAGE, "refused": None, "reopened": [1, 2]}, None)
        self.push_ok = True

        def fake_reoffer(ss, call_id, user_id, dry_run):
            events.append(("reoffer", str(call_id), str(user_id), dry_run))
            return self.sm_dry if dry_run else self.sm_apply

        def fake_push(crew_id, call, force=False):
            events.append(("push", str(crew_id), dict(call), force))
            ok = self.push_ok
            return ok(call) if callable(ok) else ok

        patches = [
            mock.patch.multiple(
                A,
                fetch_booking_bulk=lambda ss, bid: (BOOKING, None),
                get_ss_session=lambda: FakeSS(),
                ss_reoffer_declined=fake_reoffer,
                gp_notify_offer=fake_push,
            ),
            mock.patch.dict(A._ss_sessions, {"h": FakeSS()}),
            mock.patch.dict(A._ss_identity, {"h": {"cohort": "admin", "usergroupID": 1}}),
        ]
        for p in patches:
            p.start()
            self.addCleanup(p.stop)

    def pushes(self):
        return [e for e in events if e[0] == "push"]

    # ── Send offer ───────────────────────────────────────────────────────────
    def test_send_offer_reoffers_then_pushes_with_force(self):
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(code, 200, d)
        self.assertEqual(d["push"], "sent")
        self.assertEqual(events[0], ("reoffer", "1", "60", False))
        pushes = self.pushes()
        # OQ-1 default: one push per re-opened call, after the write.
        self.assertEqual([p[2]["call_id"] for p in pushes], ["1", "2"])
        self.assertTrue(all(p[3] is True for p in pushes), pushes)
        self.assertTrue(all(p[1] == "60" for p in pushes))
        self.assertEqual(d["reopened"], [1, 2])

    def test_push_call_dict_fields(self):
        post(URL, {"dry_run": False})
        load_out = self.pushes()[1][2]
        self.assertEqual(load_out["call_name"], "Load out")
        self.assertEqual(load_out["booking_name"], "Test Show")
        self.assertEqual(load_out["venue"], "Rod Laver")
        self.assertEqual(load_out["start_dt"], "2026-11-07T14:00:00")
        self.assertEqual(load_out["end_dt"], "2026-11-07T17:30:00")

    def test_only_reopened_calls_are_pushed(self):
        # Load out moved between the read and the guarded UPDATE: not re-opened.
        self.sm_apply[0]["reopened"] = [1]
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(code, 200)
        self.assertEqual([p[2]["call_id"] for p in self.pushes()], ["1"])

    def test_cross_booking_call_keeps_endpoint_name_and_start(self):
        self.sm_apply[0]["calls"] = PACKAGE + [sm_call(3, "Other show", "2026-11-08T09:00:00")]
        self.sm_apply[0]["reopened"] = [1, 2, 3]
        post(URL, {"dry_run": False})
        other = self.pushes()[2][2]
        self.assertEqual(other["call_name"], "Other show")
        self.assertEqual(other["start_dt"], "2026-11-08T09:00:00")
        self.assertEqual(other["end_dt"], "")

    def test_nothing_reopened_is_a_stale_409_and_no_push(self):
        self.sm_apply[0]["reopened"] = []
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(code, 409)
        self.assertEqual(d["error"], "not_declined")
        self.assertEqual(self.pushes(), [])

    # ── Dry run ──────────────────────────────────────────────────────────────
    def test_dry_run_never_pushes(self):
        code, d = post(URL, {"dry_run": True})
        self.assertEqual(code, 200, d)
        self.assertEqual(events, [("reoffer", "1", "60", True)])
        self.assertNotIn("push", d)
        self.assertEqual(d["responded_at"], "2026-11-05T21:14:00")

    # ── Refusals ─────────────────────────────────────────────────────────────
    def test_409_not_declined_passes_through_without_push(self):
        self.sm_apply = ({"ok": False, "error": "not_declined", "status": 0}, None)
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(code, 409)
        self.assertEqual(d["error"], "not_declined")
        self.assertEqual(self.pushes(), [])

    def test_409_started_passes_through_without_push(self):
        self.sm_apply = ({"ok": False, "error": "started", "calls": PACKAGE,
                          "refused": {"reason": "started", "call_id": 1, "call_name": "Load in"}}, None)
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(code, 409)
        self.assertEqual(d["refused"]["reason"], "started")
        self.assertEqual(self.pushes(), [])

    def test_smartstaff_error_is_502_without_push(self):
        self.sm_apply = (None, "HTTP 500: Update failed")
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(code, 502)
        self.assertEqual(self.pushes(), [])

    # ── Push failure ─────────────────────────────────────────────────────────
    def test_push_failure_is_reported_and_still_200(self):
        self.push_ok = False
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(code, 200)
        self.assertEqual(d["push"], "failed")
        self.assertTrue(d["ok"])

    def test_some_pushes_failed_is_partial(self):
        self.push_ok = lambda call: call["call_id"] == "1"
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(code, 200)
        self.assertEqual(d["push"], "partial")

    def test_single_push_failed_is_failed_not_partial(self):
        self.sm_apply[0]["reopened"] = [1]
        self.push_ok = False
        code, d = post(URL, {"dry_run": False})
        self.assertEqual(d["push"], "failed")


class FakeHTTPResp:
    def __init__(self, code):
        self.status_code = code


class NotifyOffer(unittest.TestCase):
    """The real gp_notify_offer: dedup unchanged for existing callers,
    bypassed by force=True, and a True / False result."""

    def setUp(self):
        self.posts = []
        self.code = 200

        def fake_post(url, **kw):
            self.posts.append(kw["json"]["call_id"])
            if self.code is None:
                raise RuntimeError("network down")
            return FakeHTTPResp(self.code)

        for p in (mock.patch.object(A.http, "post", fake_post),
                  mock.patch.dict(A._gp_offer_notified, {}, clear=True)):
            p.start()
            self.addCleanup(p.stop)

    def test_dedup_still_applies_without_force(self):
        call = {"call_id": 1}
        self.assertTrue(A.gp_notify_offer(60, call))
        self.assertFalse(A.gp_notify_offer(60, call))      # inside 15 min: suppressed
        self.assertEqual(self.posts, [1])

    def test_force_skips_dedup_but_records_send_time(self):
        call = {"call_id": 1}
        A.gp_notify_offer(60, call)
        before = A._gp_offer_notified[("60", "1")]
        self.assertTrue(A.gp_notify_offer(60, call, force=True))
        self.assertEqual(self.posts, [1, 1])
        self.assertGreaterEqual(A._gp_offer_notified[("60", "1")], before)
        # ...so an ordinary offer straight after the re-offer is still deduped.
        self.assertFalse(A.gp_notify_offer(60, call))
        self.assertEqual(self.posts, [1, 1])

    def test_failure_returns_false_and_never_raises(self):
        self.code = 500
        self.assertFalse(A.gp_notify_offer(60, {"call_id": 1}, force=True))
        self.code = None
        self.assertFalse(A.gp_notify_offer(60, {"call_id": 2}, force=True))

    def test_existing_callers_do_not_pass_force(self):
        """Every gp_notify_offer call in app.py outside the re-offer route
        passes no force, so the add-crew and send-sms paths keep their dedup."""
        tree = ast.parse(inspect.getsource(A))
        found = []
        for fn in ast.walk(tree):
            if not isinstance(fn, ast.FunctionDef):
                continue
            for node in ast.walk(fn):
                if (isinstance(node, ast.Call) and isinstance(node.func, ast.Name)
                        and node.func.id == "gp_notify_offer"):
                    found.append((fn.name, [k.arg for k in node.keywords], len(node.args)))
        others = [f for f in found if f[0] != "api_call_crew_reoffer"]
        self.assertGreaterEqual(len(others), 2, found)
        for name, kws, nargs in others:
            self.assertNotIn("force", kws, name)
            self.assertEqual(nargs, 2, name)
        reoffer = [f for f in found if f[0] == "api_call_crew_reoffer"]
        self.assertTrue(reoffer and all("force" in f[1] for f in reoffer), found)


class FakeSMResp:
    def __init__(self, code, body):
        self.status_code = code
        self._body = body
        self.text = ""
    def json(self):
        return self._body


class FakePostSS:
    def __init__(self, resp):
        self.resp = resp
        self.calls = []
    def post(self, url, **kw):
        self.calls.append((url, kw))
        return self.resp


class SSReoffer(unittest.TestCase):
    """ss_reoffer_declined: request shape and 409 pass-through."""

    def test_request_shape(self):
        ss = FakePostSS(FakeSMResp(200, {"ok": True, "calls": []}))
        res, err = A.ss_reoffer_declined(ss, "1", "60", True)
        self.assertIsNone(err)
        url, kw = ss.calls[0]
        self.assertTrue(url.endswith("/ajax/crew/reoffer-declined.php"))
        self.assertEqual(kw["params"], {"id": "1"})
        self.assertEqual(kw["json"], {"userID": "60", "dry_run": 1})

    def test_409_refusals_come_back_as_result(self):
        for reason in ("not_declined", "started", "cancelled"):
            ss = FakePostSS(FakeSMResp(409, {"ok": False, "error": reason}))
            res, err = A.ss_reoffer_declined(ss, "1", "60", False)
            self.assertIsNone(err, reason)
            self.assertEqual(res["error"], reason)
            self.assertIs(res["ok"], False)

    def test_other_errors_are_errors(self):
        ss = FakePostSS(FakeSMResp(403, {"error": "Admin only"}))
        res, err = A.ss_reoffer_declined(ss, "1", "60", False)
        self.assertIsNone(res)
        self.assertIn("403", err)


if __name__ == "__main__":
    unittest.main(verbosity=2)

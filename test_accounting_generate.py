#!/usr/bin/env python3
"""Who may generate an invoice, and what every answer looks like.

BRIEF-accounting-slice-b.md §7 ("Routes"), in the style of
test_accounting_access.py.

GENERATE IS THE FIRST WRITE IN ACCOUNTING, so these tests assert the stronger
version of each refusal: not merely that the answer was a 400 or a 403, but
that SMARTSTAFF WAS NEVER ASKED — nothing was written, because nothing was
sent. SmartStaff is faked at the HTTP boundary and every request is recorded.

The audit is faked too, and WHEN IT FIRES IS PART OF THE CONTRACT: an invoice
is money leaving the building, so every attempt that reached SmartStaff is
logged — success, refusal and half-failure alike — and a request that was
rejected here is not, having written nothing.

Run:  python3 test_accounting_generate.py
"""
import json
import unittest
from unittest import mock

import app as A

MIKE  = sorted(A.ACCOUNTING_USERS)[0]
OTHER = 2973                      # an admin who is NOT an accounting user

FP    = "a" * 40                  # a well-formed fingerprint
BKID  = 11734

READY_BODY = {
    "window_months": 12, "all": False, "truncated": False, "total_found": 1,
    "bookings": [{
        "booking_id": BKID, "booking_name": "FIVE @ RLA",
        "booking_reference": "RLA-1", "customer_id": 24,
        "customer_name": "Live Nation",
        "ready_calls": [{"call_id": 37598, "call_name": "Show",
                         "start_date": 1748131200, "crew_rows": 9,
                         "hours": "72.000000", "expected_charge": "32783.9500"}],
        "stuck_calls": [], "unlocked_calls": 0, "locked_no_crew_calls": 0,
        "blocked": False, "expected_exact": True, "mixed_groups": 0,
        "null_calc": 0, "fingerprint": FP,
    }],
}

OK_BODY = {
    "ok": True, "invoice_id": 34783, "booking_id": BKID, "call_ids": [37598],
    "line_count": 22, "hourly_sum": "32783.9500", "flat_sum_pdf": "0",
    "flat_sum_qty": "0", "expected_charge": "32783.9500",
    "expected_exact": True, "money_checked": True,
}


class FakeResp:
    def __init__(self, code, text):
        self.status_code = code
        self.text = text
        self.content = text.encode() if isinstance(text, str) else text


class FakeSS:
    """Answers whatever the test told it to, and records every request."""
    def __init__(self, t):
        self.t = t

    def get(self, url, params=None, timeout=None):
        self.t.calls.append(("GET", url.rsplit("/", 1)[-1], dict(params or {}), timeout))
        return self.t.answer

    def post(self, url, json=None, timeout=None):
        self.t.calls.append(("POST", url.rsplit("/", 1)[-1], json, timeout))
        if isinstance(self.t.answer, Exception):
            raise self.t.answer
        return self.t.answer


def _cohorts_for(view_name):
    """The cohorts require_cohort() was actually given, read from the closure."""
    view = A.app.view_functions[view_name]
    for cell in (view.__closure__ or ()):
        val = cell.cell_contents
        if isinstance(val, tuple) and val and all(isinstance(x, str) for x in val):
            return set(val)
    raise AssertionError("no cohort tuple in the closure of %s" % view_name)


class Base(unittest.TestCase):
    def setUp(self):
        self.calls  = []
        self.audits = []
        self.answer = FakeResp(200, "{}")
        self.ss     = FakeSS(self)

        def fake_audit(action, subject_type, subject_id, detail):
            self.audits.append((action, subject_type, subject_id, dict(detail or {})))

        p = mock.patch.object(A, "_write_audit", fake_audit)
        p.start()
        self.addCleanup(p.stop)

    def call(self, path, method="GET", body=None, cohort="admin",
             user_id=MIKE, logged_in=True):
        ss = self.ss if logged_in else None
        with A.app.test_client() as c:
            with c.session_transaction() as s:
                s["sid"] = "t"
            with mock.patch.multiple(A, get_ss_session=lambda: ss):
                with mock.patch.dict(A._ss_identity,
                                     {"t": {"cohort": cohort, "usergroupID": 1,
                                            "user_id": user_id}}):
                    r = c.open(path, method=method,
                               json=body if body is not None else None)
        try:
            return r, r.get_json()
        except Exception:
            return r, None

    def generate(self, body=None, **kw):
        if body is None:
            body = {"booking_id": BKID, "fingerprint": FP}
        return self.call("/api/accounting/invoices/generate", "POST", body, **kw)


# ──────────────────────────────────────────────────────────────────────────────
class RouteGates(Base):

    def test_both_new_routes_are_admin_only(self):
        for view in ("api_accounting_ready", "api_accounting_generate"):
            self.assertEqual(_cohorts_for(view), {"admin"}, view)

    def test_a_non_admin_cohort_is_refused_and_smartstaff_is_never_asked(self):
        for cohort in ("operations", "leadership", "crew"):
            self.calls = []
            r, _ = self.call("/api/accounting/ready", cohort=cohort)
            self.assertEqual(r.status_code, 403, cohort)
            self.assertEqual(self.calls, [], cohort)

            self.calls = []
            r, _ = self.generate(cohort=cohort)
            self.assertEqual(r.status_code, 403, cohort)
            self.assertEqual(self.calls, [], cohort)

    def test_an_admin_who_is_not_an_accounting_user_is_refused(self):
        """2973 is an admin on test and must still be refused on both."""
        for path, method in (("/api/accounting/ready", "GET"),
                             ("/api/accounting/invoices/generate", "POST")):
            self.calls = []
            r, body = self.call(path, method,
                                {"booking_id": BKID, "fingerprint": FP},
                                user_id=OTHER)
            self.assertEqual(r.status_code, 403, path)
            self.assertEqual(body.get("error"), "Not enabled for this account", path)
            self.assertEqual(self.calls, [], path)

    def test_no_session_is_a_401_not_a_403(self):
        r, _ = self.generate(logged_in=False)
        self.assertEqual(r.status_code, 401)
        self.assertEqual(self.calls, [])

    def test_a_refused_request_is_never_audited(self):
        self.generate(cohort="operations")
        self.generate(user_id=OTHER)
        self.assertEqual(self.audits, [])

    def test_generate_refuses_a_get(self):
        """405 from Flask's own routing: the write is POST only."""
        r, _ = self.call("/api/accounting/invoices/generate", "GET")
        self.assertEqual(r.status_code, 405)
        self.assertEqual(self.calls, [])

    def test_generate_does_not_collide_with_the_invoice_id_route(self):
        """/invoices/<int:id> must not swallow /invoices/generate."""
        self.answer = FakeResp(200, json.dumps(OK_BODY))
        r, body = self.generate()
        self.assertEqual(r.status_code, 200, body)
        self.assertEqual(self.calls[0][1], "generate-invoice.php")


# ──────────────────────────────────────────────────────────────────────────────
class Validation(Base):
    """Both inputs are checked HERE as well as in the PHP, and a bad one never
    reaches SmartStaff — so it cannot have written anything."""

    def test_a_missing_booking_id_is_a_400_with_no_upstream_call(self):
        r, body = self.generate({"fingerprint": FP})
        self.assertEqual(r.status_code, 400)
        self.assertEqual(body["reason"], "bad_booking_id")
        self.assertEqual(self.calls, [])
        self.assertEqual(self.audits, [])

    def test_a_non_numeric_booking_id_is_a_400(self):
        for bad in ("abc", None, "", [], {}, 0, -5):
            self.calls = []
            r, body = self.generate({"booking_id": bad, "fingerprint": FP})
            self.assertEqual(r.status_code, 400, bad)
            self.assertEqual(body["reason"], "bad_booking_id", bad)
            self.assertEqual(self.calls, [], bad)

    def test_a_bad_fingerprint_is_a_400_with_no_upstream_call(self):
        for bad in ("", "x" * 40, "a" * 39, "a" * 41, None, 123,
                    "A" * 40 + "b", "../../etc/passwd"):
            self.calls = []
            r, body = self.generate({"booking_id": BKID, "fingerprint": bad})
            self.assertEqual(r.status_code, 400, bad)
            self.assertEqual(body["reason"], "bad_fingerprint", bad)
            self.assertEqual(self.calls, [], bad)
            self.assertEqual(self.audits, [], bad)

    def test_an_uppercase_fingerprint_is_accepted_and_sent_down_in_lower(self):
        """PHP compares against sha1()'s own lowercase output."""
        self.answer = FakeResp(200, json.dumps(OK_BODY))
        r, _ = self.generate({"booking_id": BKID, "fingerprint": "A" * 40})
        self.assertEqual(r.status_code, 200)
        self.assertEqual(self.calls[0][2]["fingerprint"], "a" * 40)

    def test_an_empty_body_is_a_400(self):
        r, body = self.generate({})
        self.assertEqual(r.status_code, 400)
        self.assertEqual(self.calls, [])


# ──────────────────────────────────────────────────────────────────────────────
class Success(Base):

    def setUp(self):
        super().setUp()
        self.answer = FakeResp(200, json.dumps(OK_BODY))

    def test_the_booking_and_fingerprint_are_posted_as_json(self):
        self.generate()
        method, path, body, timeout = self.calls[0]
        self.assertEqual(method, "POST")
        self.assertEqual(path, "generate-invoice.php")
        self.assertEqual(body, {"booking_id": BKID, "fingerprint": FP})

    def test_the_generate_timeout_is_two_minutes(self):
        """SmartStaff writes every line in PHP, one INSERT each."""
        self.generate()
        self.assertEqual(self.calls[0][3], A.ACCOUNTING_GENERATE_TIMEOUT)
        self.assertEqual(A.ACCOUNTING_GENERATE_TIMEOUT, 120)

    def test_the_totals_are_added_by_invoice_totals(self):
        """The invoice the Generate just made is priced by the same function
        the list and the dialog use."""
        r, body = self.generate()
        self.assertEqual(r.status_code, 200)
        self.assertEqual(body["totals"]["ex_gst"], 32783.95)
        self.assertEqual(body["totals"]["gst"], 3278.40)
        self.assertEqual(body["totals"]["inc_gst"], 36062.35)

    def test_the_invoice_id_reaches_the_browser(self):
        _, body = self.generate()
        self.assertEqual(body["invoice_id"], 34783)
        self.assertEqual(body["call_ids"], [37598])

    def test_success_is_audited_with_the_invoice_and_the_calls(self):
        self.generate()
        self.assertEqual(len(self.audits), 1)
        action, kind, subject, detail = self.audits[0]
        self.assertEqual((action, kind, subject), ("invoice.generate", "booking", BKID))
        self.assertTrue(detail["ok"])
        self.assertEqual(detail["invoice_id"], 34783)
        self.assertEqual(detail["call_ids"], [37598])

    def test_nothing_is_cached(self):
        r, _ = self.generate()
        self.assertEqual(r.headers.get("Cache-Control"), "no-store")


# ──────────────────────────────────────────────────────────────────────────────
class Refusals(Base):
    """A 409 is a REFUSAL: the PHP wrote nothing and said which guard stopped
    it. The reason has to arrive unchanged, because the modal offers a
    different next step for each one (§5.3)."""

    def refuse(self, reason, extra=None, code=409):
        body = {"error": "no", "reason": reason}
        body.update(extra or {})
        self.answer = FakeResp(code, json.dumps(body))
        return self.generate()

    def test_busy_passes_through(self):
        r, body = self.refuse("busy")
        self.assertEqual(r.status_code, 409)
        self.assertEqual(body["reason"], "busy")

    def test_stuck_calls_passes_through_with_the_calls(self):
        r, body = self.refuse("stuck_calls", {
            "stuck_calls": [{"call_id": 37598, "invoice_id": 34012}]})
        self.assertEqual(r.status_code, 409)
        self.assertEqual(body["reason"], "stuck_calls")
        self.assertEqual(body["stuck_calls"][0]["invoice_id"], 34012)

    def test_nothing_to_invoice_keeps_the_invoice_it_named(self):
        """THE DOUBLE-GENERATE GUARD. The modal turns latest_invoice into an
        Open-invoice button, so the answer to "did it work?" is one click."""
        r, body = self.refuse("nothing_to_invoice", {"latest_invoice": 34783})
        self.assertEqual(r.status_code, 409)
        self.assertEqual(body["reason"], "nothing_to_invoice")
        self.assertEqual(body["latest_invoice"], 34783)

    def test_changed_keeps_the_booking_as_it_is_now(self):
        """So the modal can show the new call list and ask again."""
        now = READY_BODY["bookings"][0]
        r, body = self.refuse("changed", {"booking": now})
        self.assertEqual(r.status_code, 409)
        self.assertEqual(body["reason"], "changed")
        self.assertEqual(body["booking"]["booking_id"], BKID)

    def test_the_php_message_is_what_the_person_reads(self):
        self.answer = FakeResp(409, json.dumps(
            {"error": "Nothing to invoice: the calls were invoiced since you "
                      "looked (invoice #34783)", "reason": "nothing_to_invoice"}))
        _, body = self.generate()
        self.assertIn("invoiced since you looked", body["error"])

    def test_a_404_passes_through_as_not_found(self):
        r, body = self.refuse("not_found", code=404)
        self.assertEqual(r.status_code, 404)
        self.assertEqual(body["reason"], "not_found")

    def test_every_refusal_is_audited(self):
        """It reached SmartStaff. Even a refusal is worth a row — three
        `busy`s in a row is two people fighting over one booking."""
        for reason in ("busy", "stuck_calls", "nothing_to_invoice", "changed"):
            self.audits = []
            self.refuse(reason)
            self.assertEqual(len(self.audits), 1, reason)
            self.assertFalse(self.audits[0][3]["ok"], reason)
            self.assertEqual(self.audits[0][3]["reason"], reason)


# ──────────────────────────────────────────────────────────────────────────────
class HalfFailed(Base):
    """THE ONE THAT MATTERS MOST. A 500 from the PHP means the invoice EXISTS
    and did not pass its checks, and it cannot be rolled back — SmartStaff's
    own code wrote it, on its own connection. So the checks that failed have to
    survive the trip, and the wording must not invite a second press."""

    def setUp(self):
        super().setUp()
        self.answer = FakeResp(500, json.dumps({
            "ok": False, "invoice_id": 34783, "booking_id": BKID,
            "failed": ["the invoice has no priced lines",
                       "the invoice came to 100.00 but 32783.95 was expected"],
            "error": "SmartStaff generated the invoice but these checks failed.",
        }))

    def test_a_500_becomes_a_502(self):
        r, _ = self.generate()
        self.assertEqual(r.status_code, 502)

    def test_the_failed_checks_are_kept(self):
        _, body = self.generate()
        self.assertEqual(len(body["failed"]), 2)
        self.assertIn("no priced lines", body["failed"][0])

    def test_the_invoice_id_is_kept_so_it_can_be_opened(self):
        _, body = self.generate()
        self.assertEqual(body["invoice_id"], 34783)

    def test_the_message_says_do_not_generate_again(self):
        _, body = self.generate()
        self.assertIn("Don't generate again", body["error"])
        self.assertIn("check it", body["error"])

    def test_it_is_never_reported_as_ok(self):
        _, body = self.generate()
        self.assertNotEqual(body.get("ok"), True)

    def test_it_is_audited_with_the_failures(self):
        self.generate()
        self.assertEqual(len(self.audits), 1)
        detail = self.audits[0][3]
        self.assertFalse(detail["ok"])
        self.assertEqual(len(detail["failed"]), 2)
        self.assertEqual(detail["invoice_id"], 34783)


# ──────────────────────────────────────────────────────────────────────────────
class Wording(Base):
    """design §11 / review B25: "SmartStaff refused the request" sent Mike to
    the wrong place. The three failures that actually happen now say what to do
    about them — on the Slice A routes too."""

    def test_a_web_server_404_says_the_file_may_not_be_deployed(self):
        self.answer = FakeResp(404, "<html><head><title>404</title></head></html>")
        r, body = self.call("/api/accounting/ready")
        self.assertEqual(r.status_code, 404)
        self.assertIn("list-ready-to-invoice.php", body["error"])
        self.assertIn("not be deployed", body["error"])

    def test_an_endpoints_own_404_still_passes_its_message_through(self):
        """get-invoice.php's "Invoice not found" is a real 404 WITH a body, and
        must not be rewritten into "it isn't deployed"."""
        self.answer = FakeResp(404, '{"error":"Invoice not found"}')
        r, body = self.call("/api/accounting/invoices/34782")
        self.assertEqual(r.status_code, 404)
        self.assertEqual(body["error"], "Invoice not found")

    def test_a_login_page_with_a_200_says_the_session_expired(self):
        self.answer = FakeResp(200, "<html><body>Please log in</body></html>")
        r, body = self.call("/api/accounting/ready")
        self.assertEqual(r.status_code, 502)
        self.assertIn("session expired", body["error"])
        self.assertIn("Sign out", body["error"])
        self.assertNotIn("groups", body)

    def test_a_timeout_says_how_long_was_waited(self):
        self.answer = A.http.exceptions.Timeout("too slow")
        r, body = self.generate()
        self.assertIn("didn't answer within", body["error"])
        self.assertIn("120", body["error"])

    def test_a_generate_timeout_warns_the_invoice_may_exist(self):
        """A TIMEOUT IS NOT A "NO". The lines may have been most of the way
        written when we stopped waiting, and "it failed" would invite exactly
        the second press that makes two invoices."""
        self.answer = A.http.exceptions.Timeout("too slow")
        r, body = self.generate()
        self.assertIn("may still have created the invoice", body["error"])
        self.assertIn("before generating again", body["error"])
        self.assertEqual(body["reason"], "timeout")

    def test_a_ready_list_failure_is_never_an_empty_list(self):
        """"Nothing is ready" and "the query failed" mean opposite things."""
        self.answer = FakeResp(500, '{"error":"Ready-to-invoice query failed",'
                                    '"query":"pay rows"}')
        r, body = self.call("/api/accounting/ready")
        self.assertEqual(r.status_code, 502)
        self.assertNotIn("groups", body)
        self.assertIn("query failed", body["error"])


# ──────────────────────────────────────────────────────────────────────────────
class ReadyRoute(Base):

    def setUp(self):
        super().setUp()
        self.answer = FakeResp(200, json.dumps(READY_BODY))

    def test_all_is_forwarded_as_zero_or_one(self):
        self.call("/api/accounting/ready")
        self.assertEqual(self.calls[0][2], {"all": "0"})
        self.calls = []
        self.call("/api/accounting/ready?all=1")
        self.assertEqual(self.calls[0][2], {"all": "1"})

    def test_anything_other_than_one_is_not_all(self):
        for v in ("", "0", "yes", "true", "2"):
            self.calls = []
            self.call("/api/accounting/ready?all=" + v)
            self.assertEqual(self.calls[0][2], {"all": "0"}, v)

    def test_the_bookings_come_back_grouped_with_totals(self):
        r, body = self.call("/api/accounting/ready")
        self.assertEqual(r.status_code, 200)
        self.assertEqual(len(body["groups"]), 1)
        self.assertEqual(body["groups"][0]["customer_name"], "Live Nation")
        self.assertEqual(body["ready_bookings"], 1)
        self.assertEqual(body["totals"]["ex_gst"], 32783.95)
        self.assertEqual(body["totals"]["inc_gst"], 36062.35)

    def test_the_window_and_truncation_are_passed_on(self):
        r, body = self.call("/api/accounting/ready")
        self.assertEqual(body["window_months"], 12)
        self.assertFalse(body["truncated"])
        self.assertEqual(body["total_found"], 1)

    def test_an_unexpected_shape_is_a_502_not_a_crash(self):
        self.answer = FakeResp(200, '{"bookings":"not a list"}')
        r, body = self.call("/api/accounting/ready")
        self.assertEqual(r.status_code, 502)
        self.assertIn("unexpected shape", body["error"])

    def test_reading_the_list_is_never_audited(self):
        """Only the write is. An audit row per page view is noise that hides
        the rows that matter."""
        self.call("/api/accounting/ready")
        self.assertEqual(self.audits, [])

    def test_nothing_is_cached(self):
        r, _ = self.call("/api/accounting/ready")
        self.assertEqual(r.headers.get("Cache-Control"), "no-store")


if __name__ == "__main__":
    unittest.main(verbosity=2)

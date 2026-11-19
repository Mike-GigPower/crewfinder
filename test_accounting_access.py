#!/usr/bin/env python3
"""Who may reach /api/accounting/* — and what a failure looks like.

BRIEF-accounting-slice-a.md §8, in the style of test_booking_docs_access.py.

TWO LAYERS, AND THEY ARE NOT THE SAME THING:

  * the ROUTE guard, which is the actual access control;
  * the tab button in index.html, which only decides what is drawn.

"Hiding a tab is not access control" (the note on ACCOUNTING_USERS in app.py),
so the route tests are the ones that matter. The tab test is here because a tab
that is drawn and then 403s is a bug of its own.

SmartStaff is faked at the HTTP boundary and every call to it is RECORDED, so
the refusal tests can assert the stronger thing: not merely that the answer was
403, but that SmartStaff was never asked in the first place.

Run:  python3 test_accounting_access.py
"""
import re
import unittest
from unittest import mock

import app as A

ROUTES = (
    ("api_accounting_invoices",     "/api/accounting/invoices"),
    ("api_accounting_invoice",      "/api/accounting/invoices/34782"),
    ("api_accounting_invoice_pdf",  "/api/accounting/invoices/34782/pdf"),
)

# An account that may see Accounting, and one that may not. Both are admins:
# the second one is the whole point of the user check existing on top of the
# cohort check.
MIKE  = sorted(A.ACCOUNTING_USERS)[0]
OTHER = 2973                      # the shared admin login on test

LIST_BODY = {
    "page": 0, "per_page": 50, "total": 1, "q": "",
    "invoices": [{
        "id": 34782, "booking_id": 12275, "booking_name": "Foo &amp; Bar",
        "booking_reference": "FB-1", "customer_id": 9, "customer_name": "Foo Fighters",
        "generated": 1794952800, "exported": 1, "call_count": 3,
        "hourly_sum": 1000.0, "flat_sum_pdf": 450.0, "flat_sum_qty": 1350.0,
        "line_count": 7,
    }],
}

INVOICE_BODY = {
    "invoice": {"id": 34782, "generated": 1794952800, "exported": 0, "call_list": [1, 2]},
    "booking": {"id": 12275, "name": "Foo &amp; Bar", "reference": "FB-1"},
    "customer": {"id": 9, "name": "Foo Fighters", "billing_is_fallback": True},
    "contact": {"user_id": 5, "first_name": "Jo", "last_name": "Bloggs"},
    "venue": {"id": 3, "name": "Rod Laver Arena"},
    "lines": [
        {"id": 1, "call_id": 1, "description": "LOAD IN", "qty": 2,
         "chargeout_rate": 61.50, "hours": 8, "flatrate": 0,
         "start_date": 1794952800, "start_time": "08:00", "end_time": "16:00",
         "break_time": "00:30"},
        {"id": 2, "call_id": 1, "description": "<< BLANK_LINE >>", "qty": 1,
         "chargeout_rate": 0, "hours": 0, "flatrate": 0, "start_date": 0,
         "start_time": None, "end_time": None, "break_time": None},
    ],
    "calls": [], "extra_line_call_ids": [], "hand_added_lines": 0,
    "capabilities": {"billing_address": True},
}


class FakeResp:
    def __init__(self, code, text="", content=b""):
        self.status_code = code
        self.text = text
        self.content = content


class FakeSS:
    """Answers whatever the test told it to, and records every request."""
    def __init__(self, t):
        self.t = t

    def get(self, url, params=None, timeout=None):
        self.t.calls.append((url.rsplit("/", 1)[-1], dict(params or {}), timeout))
        return self.t.answer


def _cohorts_for(view_name):
    """The cohorts require_cohort() was actually given for a route.

    Read out of the live wrapper's closure, not grepped, so this tests the
    object that runs. require_accounting applies require_cohort("admin")
    itself, so a route that forgot the decorator has no tuple to find and this
    raises rather than passing quietly.
    """
    view = A.app.view_functions[view_name]
    for cell in (view.__closure__ or ()):
        val = cell.cell_contents
        if isinstance(val, tuple) and val and all(isinstance(x, str) for x in val):
            return set(val)
    raise AssertionError("no cohort tuple in the closure of %s" % view_name)


class Base(unittest.TestCase):
    def setUp(self):
        self.calls = []
        self.answer = FakeResp(200, "{}")
        self.ss = FakeSS(self)

    def call(self, path, cohort="admin", user_id=MIKE, logged_in=True):
        ss = self.ss if logged_in else None
        with A.app.test_client() as c:
            with c.session_transaction() as s:
                s["sid"] = "t"
            with mock.patch.multiple(A, get_ss_session=lambda: ss):
                with mock.patch.dict(A._ss_identity,
                                     {"t": {"cohort": cohort, "usergroupID": 1,
                                            "user_id": user_id}}):
                    r = c.get(path)
        body = None
        try:
            body = r.get_json()
        except Exception:
            body = None
        return r, body


class RouteGates(Base):

    def test_every_route_is_admin_only(self):
        """Not READ_ALL_COHORTS, and not admin+operations. It is money."""
        for view, _ in ROUTES:
            self.assertEqual(_cohorts_for(view), {"admin"}, view)

    def test_a_non_admin_cohort_is_refused_and_smartstaff_is_never_asked(self):
        for cohort in ("operations", "leadership", "crew"):
            for _, path in ROUTES:
                self.calls = []
                r, body = self.call(path, cohort=cohort)
                self.assertEqual(r.status_code, 403, (cohort, path))
                self.assertEqual(self.calls, [], (cohort, path))

    def test_an_admin_who_is_not_an_accounting_user_is_refused(self):
        """THE CHECK THIS SLICE TURNS ON. 2973 is an admin on test and must
        still be refused: hiding the tab is not access control."""
        for _, path in ROUTES:
            self.calls = []
            r, body = self.call(path, cohort="admin", user_id=OTHER)
            self.assertEqual(r.status_code, 403, path)
            self.assertEqual(body.get("error"), "Not enabled for this account", path)
            # The stronger assertion: SmartStaff was never asked.
            self.assertEqual(self.calls, [], path)

    def test_an_identity_with_no_user_id_is_refused(self):
        """An admin account whose userID did not come back is not Mike."""
        for _, path in ROUTES:
            self.calls = []
            r, _ = self.call(path, cohort="admin", user_id="0")
            self.assertEqual(r.status_code, 403, path)
            self.assertEqual(self.calls, [], path)

    def test_no_session_is_401_not_403(self):
        """"Log in again" and "you may not have this" are different answers."""
        for _, path in ROUTES:
            self.calls = []
            r, _ = self.call(path, logged_in=False)
            self.assertEqual(r.status_code, 401, path)
            self.assertEqual(self.calls, [], path)

    def test_the_accounting_user_set_has_not_quietly_widened(self):
        """Widening Accounting is one edit here and one in each PHP file, and
        it should be deliberate (brief §10, Q2)."""
        self.assertEqual(A.ACCOUNTING_USERS, {10261})


class ListRoute(Base):

    def test_the_totals_are_added_per_invoice(self):
        import json as _json
        self.answer = FakeResp(200, _json.dumps(LIST_BODY))
        r, body = self.call("/api/accounting/invoices?q=foo&page=2")
        self.assertEqual(r.status_code, 200)
        inv = body["invoices"][0]
        # 1000 hourly + 450 flat (the PDF's flat sum, qty ignored).
        self.assertEqual(inv["totals"]["ex_gst"], 1450.00)
        self.assertEqual(inv["totals"]["inc_gst"], 1595.00)
        self.assertEqual(inv["totals"]["xero_ex_gst"], 2350.00)
        self.assertTrue(inv["totals"]["pdf_xero_differ"])

    def test_the_totals_are_right_when_the_php_sends_decimal_strings(self):
        """WHAT THE PHP NOW SENDS (fix 1 §1). mysqli returns a DECIMAL as a
        string and list-invoices.php passes those digits through untouched, so
        the route has to read strings. #34334's figures, which are the PDF's."""
        import json as _json
        body = _json.loads(_json.dumps(LIST_BODY))
        body["invoices"][0].update({"hourly_sum": "32783.95000000",
                                    "flat_sum_pdf": "0.0000",
                                    "flat_sum_qty": "0.0000"})
        self.answer = FakeResp(200, _json.dumps(body))
        r, out = self.call("/api/accounting/invoices")
        t = out["invoices"][0]["totals"]
        self.assertEqual((t["ex_gst"], t["gst"], t["inc_gst"]),
                         (32783.95, 3278.40, 36062.35))
        self.assertFalse(t["pdf_xero_differ"])

    def test_an_invoice_with_no_lines_sends_zero_strings(self):
        """The PHP default for a row query 3 found no lines for."""
        import json as _json
        body = _json.loads(_json.dumps(LIST_BODY))
        body["invoices"][0].update({"hourly_sum": "0", "flat_sum_pdf": "0",
                                    "flat_sum_qty": "0", "line_count": 0})
        self.answer = FakeResp(200, _json.dumps(body))
        r, out = self.call("/api/accounting/invoices")
        self.assertEqual(r.status_code, 200)
        self.assertEqual(out["invoices"][0]["totals"]["inc_gst"], 0.0)

    def test_the_search_and_the_page_are_forwarded(self):
        import json as _json
        self.answer = FakeResp(200, _json.dumps(LIST_BODY))
        self.call("/api/accounting/invoices?q=Foo+%26+Bar&page=2")
        self.assertEqual(len(self.calls), 1)
        name, params, _ = self.calls[0]
        self.assertEqual(name, "list-invoices.php")
        self.assertEqual(params["q"], "Foo & Bar")
        self.assertEqual(params["p"], "2")

    def test_a_403_from_the_php_passes_its_own_message_through(self):
        self.answer = FakeResp(403, '{"error":"Not enabled for this account"}')
        r, body = self.call("/api/accounting/invoices")
        self.assertEqual(r.status_code, 403)
        self.assertEqual(body["error"], "Not enabled for this account")

    def test_a_login_page_with_an_http_200_is_a_502_never_an_empty_list(self):
        """SmartStaff answers a lapsed session with HTML AND a 200. An empty
        list would mean "no invoices", which would be a lie."""
        self.answer = FakeResp(200, "<html><body>Please log in</body></html>")
        r, body = self.call("/api/accounting/invoices")
        self.assertEqual(r.status_code, 502)
        self.assertNotIn("invoices", body)
        self.assertIn("error", body)

    def test_a_failed_query_is_a_502_naming_it(self):
        self.answer = FakeResp(500, '{"error":"Invoice list query failed","query":"line_sums"}')
        r, body = self.call("/api/accounting/invoices")
        self.assertEqual(r.status_code, 502)
        self.assertIn("query failed", body["error"])

    def test_nothing_is_cached(self):
        import json as _json
        self.answer = FakeResp(200, _json.dumps(LIST_BODY))
        r, _ = self.call("/api/accounting/invoices")
        self.assertEqual(r.headers.get("Cache-Control"), "no-store")


class InvoiceRoute(Base):

    def test_the_lines_come_back_as_views_with_totals(self):
        import json as _json
        self.answer = FakeResp(200, _json.dumps(INVOICE_BODY))
        r, body = self.call("/api/accounting/invoices/34782")
        self.assertEqual(r.status_code, 200)
        self.assertEqual(body["totals"]["ex_gst"], 984.00)     # 2 x 61.50 x 8
        self.assertEqual(body["totals"]["line_count"], 1)      # the spacer is not a line
        self.assertEqual(len(body["lines"]), 2)                # but it is still returned
        self.assertTrue(body["lines"][1]["is_spacer"])
        self.assertEqual(body["lines"][0]["start"], "08:00")

    def test_the_description_reaches_the_browser_as_two_plain_text_fields(self):
        """fix 1 §2: the page gets desc_main and desc_note, already stripped
        and decoded, plus the raw column for comparison. Nothing from the data
        is markup by the time it leaves Flask."""
        import json as _json
        body = _json.loads(_json.dumps(INVOICE_BODY))
        raw = 'Crew Boss <i style="font-size: 9px;">(Night/Crew Boss)</i>'
        body["lines"][0]["description"] = raw
        self.answer = FakeResp(200, _json.dumps(body))
        r, out = self.call("/api/accounting/invoices/34782")
        line = out["lines"][0]
        self.assertEqual(line["desc_main"], "Crew Boss")
        self.assertEqual(line["desc_note"], "(Night/Crew Boss)")
        self.assertEqual(line["description"], raw)   # kept, but never drawn

    def test_an_unknown_invoice_is_a_404(self):
        self.answer = FakeResp(404, '{"error":"Invoice not found"}')
        r, body = self.call("/api/accounting/invoices/34782")
        self.assertEqual(r.status_code, 404)
        self.assertEqual(body["error"], "Invoice not found")


class PdfRoute(Base):

    def test_a_pdf_is_streamed_inline(self):
        self.answer = FakeResp(200, "", b"%PDF-1.4\nstuff")
        r, _ = self.call("/api/accounting/invoices/34782/pdf")
        self.assertEqual(r.status_code, 200)
        self.assertEqual(r.mimetype, "application/pdf")
        self.assertIn("inline", r.headers["Content-Disposition"])
        self.assertIn("invoice_34782.pdf", r.headers["Content-Disposition"])
        self.assertEqual(r.headers["X-Content-Type-Options"], "nosniff")
        self.assertEqual(r.data, b"%PDF-1.4\nstuff")

    def test_a_body_that_is_not_a_pdf_is_a_502(self):
        """The failure this check exists for: SmartStaff answers a lapsed or
        non-admin session with an HTML LOGIN PAGE and an HTTP 200, so a status
        check alone would hand Mike a file his viewer cannot open."""
        self.answer = FakeResp(200, "", b"<html><body>Please log in</body></html>")
        r, body = self.call("/api/accounting/invoices/34782/pdf")
        self.assertEqual(r.status_code, 502)
        self.assertEqual(body["error"], "SmartStaff did not return a PDF")

    def test_an_empty_body_is_a_502(self):
        self.answer = FakeResp(200, "", b"")
        r, body = self.call("/api/accounting/invoices/34782/pdf")
        self.assertEqual(r.status_code, 502)

    def test_dompdf_gets_the_long_timeout(self):
        """120 s, because dompdf is slow on a 20-line invoice. The default 10 s
        would time out on exactly the biggest invoices (§5.2)."""
        self.answer = FakeResp(200, "", b"%PDF-1.4")
        self.call("/api/accounting/invoices/34782/pdf")
        self.assertEqual(self.calls[0][2], 120)
        self.assertEqual(self.calls[0][1], {"method": "pdf", "id": 34782})


class TabStaysFailClosed(unittest.TestCase):
    """The Invoicing tab button must still ship hidden."""

    @classmethod
    def setUpClass(cls):
        with open("templates/index.html", encoding="utf-8") as f:
            cls.page = f.read()

    def panel(self):
        p = self.page[self.page.index('id="tab-invoicing"'):]
        return p[:p.index('id="tab-payroll"')]

    def test_the_tab_button_still_ships_display_none(self):
        """Revealed only by whoami.accounting === true. If this ever ships
        visible, every admin sees a tab whose routes refuse them."""
        m = re.search(r'id="tab-btn-invoicing"[^>]*>', self.page)
        self.assertIsNotNone(m)
        self.assertIn('display:none', m.group(0))

    def test_the_panel_is_the_real_one_not_the_placeholder(self):
        """Keyed on the placeholder's "Coming soon.", not on its title.

        Slice A replaced the placeholder and titled the panel "Invoices";
        slice B holds two views and titles it "Invoicing" again, which is the
        placeholder's old title — so the title is not a discriminator and this
        test used to fail on a correct page. The sub-heading is.
        """
        self.assertIn('id="inv-list"', self.page)
        self.assertNotIn('Coming soon.', self.panel())

    def test_the_static_panel_still_has_no_write_button(self):
        """Unchanged from slice A, and it has to stay that way even though
        slice B writes: the Generate button belongs to ONE booking and is
        drawn per row by rdyBookingHtml(), which omits it entirely for a
        blocked booking. A button in the static markup could not do that.
        """
        panel = self.panel()
        self.assertNotIn('class="rdy-gen"', panel)
        for word in ("Generate", "Regenerate", "Edit", "Delete", "Email", "Export"):
            self.assertNotIn(">" + word, panel, word)

    def test_generate_is_in_the_row_renderer_instead(self):
        """So the test above cannot pass by there being no Generate at all.
        test_accounting_generate_js then proves the blocked case omits it."""
        i = self.page.index("function rdyBookingHtml(")
        j = self.page.index("function rdyCallsHtml(", i)
        renderer = self.page[i:j]
        self.assertIn('class="rdy-gen"', renderer)
        self.assertIn("Generate invoice", renderer)
        self.assertIn("blocked ? ''", renderer)      # no button when blocked

    def test_slice_b_still_adds_no_edit_email_or_export(self):
        """Regenerate, Edit, Delete and Email are slice E; the Xero export is
        slice D (slice B §1, "Not in this slice"). "Open in SmartStaff" is a
        LINK to SmartStaff's own page, not an edit control."""
        i = self.page.index("// ── READY TO INVOICE")
        j = self.page.index("function rdyRefreshAfterGenerate(", i)
        block = self.page[i:j]
        for word in ("Regenerate", ">Edit", ">Delete", ">Email", ">Export"):
            self.assertNotIn(word, block, word)


if __name__ == "__main__":
    unittest.main(verbosity=2)

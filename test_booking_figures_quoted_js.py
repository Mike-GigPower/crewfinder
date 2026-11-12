"""The Financials tab's quoted-hours column and the Quoted button (5.103.1).

Runs bkFiguresRender from templates/index.html under node (js_harness) against
synthetic payloads — not the test_fixtures/ exports, so the expected figures are
written out here beside the lines they come from. Skips when node isn't
installed.

Two things are under test.

1. "Quoted h (billed)" in Hours by call type. The figure is Σ qty ×
   billable_hours over the quote's labour lines, keyed on crew_type, which is
   the same string as the call type's role. It is NOT matched on call name: the
   quote's call names are the Estimator's. billable_hours already includes the
   minimum billable hours when the quote was priced with them, so a role's
   quoted figure can exceed its Scheduled h with nothing wrong.

2. The Quoted button. An imported quote drew it with an empty label — an empty
   oval that opened an amount form the edge function then refused with a 409.
"""

import re
import unittest

import js_harness as H

FUNCS = H.extract(
    "bkSafe", "bkMoney", "bkDocWhen", "bkCallHasPay", "bkFiguresRender",
) if H.NODE else ""

BID = "900002"

# GP-999901's labour lines, staff-shaped: Standard Crew 34×4 + 10×4 + 44×4 =
# 352, Crew Boss 1×6 + 1×4 = 10, Show Crew 6×4 + 4×4 = 40. Total 402.
STAFF_LINES = """[
  {call_name:'Bump in',  crew_type:'Standard Crew', qty:34, billable_hours:4},
  {call_name:'Show',     crew_type:'Standard Crew', qty:10, billable_hours:4},
  {call_name:'Bump out', crew_type:'Standard Crew', qty:44, billable_hours:4},
  {call_name:'Bump in',  crew_type:'Crew Boss',     qty:1,  billable_hours:6},
  {call_name:'Bump out', crew_type:'Crew Boss',     qty:1,  billable_hours:4},
  {call_name:'Show',     crew_type:'Show Crew',     qty:6,  billable_hours:4},
  {call_name:'Show 2',   crew_type:'Show Crew',     qty:4,  billable_hours:4}
]"""

# The same quote Admin-shaped: Show Crew at 3 hours rather than 4, so Show Crew
# is 6×3 + 4×3 = 30 and the total is 392.
ADMIN_LINES = STAFF_LINES.replace("crew_type:'Show Crew',     qty:6,  billable_hours:4",
                                  "crew_type:'Show Crew',     qty:6,  billable_hours:3") \
                         .replace("crew_type:'Show Crew',     qty:4,  billable_hours:4",
                                  "crew_type:'Show Crew',     qty:4,  billable_hours:3")


def payload(lines, source="estimate_import"):
    """Three calls — one per quoted role — with crew and times on the first.

    est_length 3 with one confirmed crew member gives Standard Crew 3.00
    Scheduled h against 352.00 quoted, which is the shape of a real booking
    whose Estimator quote covered far more crew than the schedule names yet.
    """
    quote = ("null" if lines is None else
             "{source:'%s', total_ex_gst:40200, quote_number:'GP-999901', "
             "minimums_applied:true, billable_hours:%s}" % (source, lines))
    return r"""
window._cohort = 'admin';
window._bkFigures = {};
window._bkFigures['%s'] = {
  rules_label: 'Standard',
  errors: {},
  figures: {
    calls: [
      {call_id:1, call_name:'Bump in', start_date:1763000000, start_time:'08:00', est_length:3},
      {call_id:2, call_name:'Show',    start_date:1763100000, start_time:'08:00', est_length:4},
      {call_id:3, call_name:'Show 2',  start_date:1763200000, start_time:'08:00', est_length:4}
    ],
    invoiced: {},
    pay: {},
    crew: [{call_id:1, status:5, promo_pending:false}]
  },
  expected: {calls:{}, total:null, total_reason:'no calls priced'},
  call_types: {
    '1': {role:'Standard Crew', source:'stored'},
    '2': {role:'Crew Boss',     source:'stored'},
    '3': {role:'Show Crew',     source:'stored'}
  },
  quote: %s
};
_el('bk-figures-host');
""" % (BID, quote)


RENDER = """
bkFiguresRender('%s');
console.log(JSON.stringify({html: _els['bk-figures-host'].innerHTML}));
""" % BID


def hours_table(html):
    """{role: [calls, quoted, scheduled, actual]} from Hours by call type."""
    start = html.index("Hours by call type")
    block = html[start:html.index("</table>", start)]
    rows = {}
    for tr in re.split(r"<tr\b", block)[1:]:
        cells = [re.sub(r"<[^>]*>", "", c).strip()
                 for c in re.split(r"<t[dh]\b[^>]*>", tr)[1:]]
        if len(cells) == 5 and cells[0] != "Call type":
            rows[cells[0]] = cells[1:]
    return rows


class QuotedHoursColumnTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_the_heading_sits_between_calls_and_scheduled(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + payload(STAFF_LINES) + RENDER)
        head = out["html"][out["html"].index("Call type"):]
        self.assertLess(head.index("Calls"), head.index("Quoted h (billed)"))
        self.assertLess(head.index("Quoted h (billed)"), head.index("Scheduled h"))

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_staff_shaped_quote_sums_per_role_to_the_quote(self):
        """34×4 + 10×4 + 44×4 = 352, 1×6 + 1×4 = 10, 6×4 + 4×4 = 40."""
        rows = hours_table(H.run(H.DOM_PRELUDE + FUNCS
                                 + payload(STAFF_LINES) + RENDER)["html"])
        self.assertEqual(rows["Standard Crew"][1], "352.00")
        self.assertEqual(rows["Crew Boss"][1], "10.00")
        self.assertEqual(rows["Show Crew"][1], "40.00")
        self.assertEqual(rows["Total"][1], "402.00")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_quoted_hours_may_exceed_scheduled_hours(self):
        """352 quoted against 3.00 scheduled is the minimums at work, not a
        bug — the column must print both rather than clamp either."""
        rows = hours_table(H.run(H.DOM_PRELUDE + FUNCS
                                 + payload(STAFF_LINES) + RENDER)["html"])
        self.assertEqual(rows["Standard Crew"][2], "3.00")
        self.assertEqual(rows["Standard Crew"][1], "352.00")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_an_admin_shaped_quote_prices_show_crew_at_three_hours(self):
        """6×3 + 4×3 = 30, so the total is 392."""
        rows = hours_table(H.run(H.DOM_PRELUDE + FUNCS
                                 + payload(ADMIN_LINES) + RENDER)["html"])
        self.assertEqual(rows["Show Crew"][1], "30.00")
        self.assertEqual(rows["Total"][1], "392.00")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_quoted_role_with_no_matching_call_gets_its_own_row(self):
        """Every quoted hour has to appear somewhere or the total stops
        equalling the quote. Rigger 2×8 = 16 is quoted and never called."""
        lines = STAFF_LINES.rstrip("]").rstrip() + \
            ",\n  {call_name:'Rig',crew_type:'Rigger',qty:2,billable_hours:8}\n]"
        rows = hours_table(H.run(H.DOM_PRELUDE + FUNCS
                                 + payload(lines) + RENDER)["html"])
        self.assertIn("Rigger", rows)
        self.assertEqual(rows["Rigger"][0], "0", "no calls of that type")
        self.assertEqual(rows["Rigger"][1], "16.00")
        self.assertEqual(rows["Rigger"][2], "—", "nothing scheduled")
        self.assertEqual(rows["Rigger"][3], "—", "nothing keyed")
        self.assertEqual(rows["Total"][1], "418.00", "402 quoted + 16 uncalled")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_quoted_role_matches_on_crew_type_not_call_name(self):
        """The quote's call names are the Estimator's. Renaming every one of
        them must not move a single quoted hour."""
        lines = re.sub(r"call_name:'[^']*'", "call_name:'Estimator line'", STAFF_LINES)
        rows = hours_table(H.run(H.DOM_PRELUDE + FUNCS
                                 + payload(lines) + RENDER)["html"])
        self.assertEqual(rows["Standard Crew"][1], "352.00")
        self.assertEqual(rows["Total"][1], "402.00")


class NoBreakdownTests(unittest.TestCase):

    def _all_dashes(self, html):
        rows = hours_table(html)
        self.assertTrue(rows, "the table must still render")
        for role, cells in rows.items():
            self.assertEqual(cells[1], "—",
                             "%s must read '—' with no breakdown" % role)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_manual_quote_shows_a_dash_for_every_row(self):
        """A manual amount is a figure somebody typed; it has no lines behind
        it, and booking_quote.billable_hours is null for manual rows."""
        out = H.run(H.DOM_PRELUDE + FUNCS
                    + payload("null", source="manual") + RENDER)
        self._all_dashes(out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_an_import_with_no_billable_hours_shows_a_dash(self):
        """Schema 1.0-1.2 imports carried no billable_hours."""
        self._all_dashes(H.run(H.DOM_PRELUDE + FUNCS
                               + payload("null") + RENDER)["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_an_empty_billable_hours_array_shows_a_dash(self):
        self._all_dashes(H.run(H.DOM_PRELUDE + FUNCS
                               + payload("[]") + RENDER)["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_no_quote_at_all_shows_a_dash(self):
        self._all_dashes(H.run(H.DOM_PRELUDE + FUNCS
                               + payload(None) + RENDER)["html"])


class PaidCancellationTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_the_paid_cancellation_row_has_no_quoted_hours(self):
        """Q7's row is out of Scheduled h, and nothing in the quote priced it."""
        p = payload(STAFF_LINES).replace(
            "{call_id:3, call_name:'Show 2',  start_date:1763200000, start_time:'08:00', est_length:4}",
            "{call_id:3, call_name:'Show 2',  start_date:1763200000, start_time:'08:00', "
            "est_length:4, cancelled_at:'2026-11-01T00:00:00Z', cancel_pay:true}")
        rows = hours_table(H.run(H.DOM_PRELUDE + FUNCS + p + RENDER)["html"])
        self.assertIn("Cancelled – paid", rows)
        self.assertEqual(rows["Cancelled – paid"][1], "—")
        # Show Crew's own hours are still quoted, now on a called-nothing row.
        self.assertEqual(rows["Show Crew"][1], "40.00")
        self.assertEqual(rows["Total"][1], "402.00")


class QuotedButtonTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_an_imported_quote_draws_no_button(self):
        """The bug: an empty oval that opened a form the edge function refuses
        with a 409. Nothing is drawn, and neither is the form's host."""
        out = H.run(H.DOM_PRELUDE + FUNCS + payload(STAFF_LINES) + RENDER)
        self.assertNotIn("bkQuoteForm", out["html"])
        self.assertNotIn('id="bk-quote-form"', out["html"])
        self.assertIn("Estimator GP-999901", out["html"],
                      "the note already says where the figure came from")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_a_manual_quote_offers_change(self):
        out = H.run(H.DOM_PRELUDE + FUNCS
                    + payload("null", source="manual") + RENDER)
        self.assertIn("Change…", out["html"])
        self.assertIn("bkQuoteForm", out["html"])
        self.assertIn('id="bk-quote-form"', out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_no_quote_offers_set_amount(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + payload(None) + RENDER)
        self.assertIn("Set amount…", out["html"])
        self.assertIn("bkQuoteForm", out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_no_empty_button_label_for_any_quote_source(self):
        """Whatever the source, a button that reaches the page has words in
        it. This is the shape of the bug rather than one instance of it."""
        for src in ("estimate_import", "manual"):
            out = H.run(H.DOM_PRELUDE + FUNCS
                        + payload("null", source=src) + RENDER)
            self.assertNotRegex(out["html"], r'<button[^>]*>\s*</button>')

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_non_admin_cohorts_never_get_the_button(self):
        out = H.run(H.DOM_PRELUDE + FUNCS
                    + payload(None).replace("'admin'", "'operations'") + RENDER)
        self.assertNotIn("bkQuoteForm", out["html"])


if __name__ == "__main__":
    unittest.main()

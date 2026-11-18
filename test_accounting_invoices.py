#!/usr/bin/env python3
"""The invoice arithmetic — accounting_invoices.py (BRIEF-accounting-slice-a §8).

PURE. No server, no database, no network: every case here is a dict in and a
number out, which is the whole point of putting the maths in its own module.

The three things being pinned down:

  1. A FLAT-RATE LINE IGNORES QTY in the PDF and does not in the Xero export,
     so an invoice can have two defensible totals. pdf_xero_differ must be True
     exactly when they differ, and never as a side effect of rounding.
  2. ROUNDING ONCE. GST is Sigma(line x 0.10) unrounded, then rounded — which
     is what invoice.tpl computes. Rounding each line's GST to cents FIRST and
     adding those is a cent out on the case below, and is the mistake this
     module exists to avoid.
  3. TIMES COME IN THREE SHAPES and anything else is handed back raw.
  4. DESCRIPTIONS ARRIVE AS MARKUP and leave as two plain-text fields, with
     <script> and its contents gone and nothing readable dropped.

Run:  python3 test_accounting_invoices.py
"""
import io
import json
import os
import struct
import unittest

import accounting_invoices as AI

# 09:00 and 17:30 Melbourne. Both sides of the daylight-saving switch on
# purpose: a naive UTC reading would be an hour out on one of them.
TS_0900_NOV = 1794952800   # 18 Nov 2026, AEDT (UTC+11)
TS_1730_JUN = 1781767800   # 18 Jun 2026, AEST (UTC+10)


HERE = os.path.dirname(os.path.abspath(__file__))

# get-invoice.php?id=34334 on TEST, as the endpoint returned it (see the file's
# own _source note). Looked for where Mike put it, then in the repo's other
# fixture directory, so moving it between the two does not break anything.
#
# IT IS NEVER COMMITTED, AND THE TESTS THAT READ IT SKIP WHEN IT IS ABSENT --
# the arrangement test_invoice_expect.py has with test_fixtures/booking_12220
# .json and test_visa_extract.py has with fixtures/. This file is a literal
# response carrying a real customer's booking name and the real money on their
# invoice, and THIS REPO IS PUBLIC. Both directories are already gitignored
# (.gitignore `fixtures/` and `test_fixtures/`), so the file stays on the Air
# and the build machine and nowhere else.
#
# A skip names the missing file, so "skipped" never reads as "passed" to
# someone who meant to have the data. And because a skip is not a pass,
# Invoice34334PublishedFigures below pins the cent from the PUBLISHED numbers
# alone -- no customer data, so it runs everywhere, fixture or no fixture.
FIXTURE_34334 = [
    os.path.join(HERE, "claude", "fixtures", "get-invoice-34334-test.json"),
    os.path.join(HERE, "test_fixtures", "get-invoice-34334-test.json"),
]


def fixture_34334_path():
    for path in FIXTURE_34334:
        if os.path.exists(path):
            return path
    return None


def load_34334():
    path = fixture_34334_path()
    if path is None:                      # guarded by needs_34334; belt and braces
        raise AssertionError("#34334's response is not on this machine")
    with io.open(path, encoding="utf-8") as fh:
        return json.load(fh)


needs_34334 = unittest.skipUnless(
    fixture_34334_path() is not None,
    "fixture not on this machine: claude/fixtures/get-invoice-34334-test.json "
    "(a real customer's invoice; never committed, see the note above)")


def hourly(qty, rate, hours, **kw):
    line = {"description": "LOAD OUT", "qty": qty, "chargeout_rate": rate,
            "hours": hours, "flatrate": 0, "start_date": TS_0900_NOV,
            "start_time": "08:00", "end_time": "16:00", "break_time": "00:30"}
    line.update(kw)
    return line


def flat(flatrate, qty=1, **kw):
    line = {"description": "Equipment hire", "qty": qty, "chargeout_rate": 0,
            "hours": 0, "flatrate": flatrate, "start_date": TS_0900_NOV,
            "start_time": "", "end_time": "", "break_time": None}
    line.update(kw)
    return line


def spacer(**kw):
    line = {"description": AI.SPACER_DESCRIPTION, "qty": 1, "chargeout_rate": 0,
            "hours": 0, "flatrate": 0, "start_date": 0,
            "start_time": None, "end_time": None, "break_time": None}
    line.update(kw)
    return line


class InvoiceTotals(unittest.TestCase):
    """invoice_totals() on the list's three SQL sums."""

    def test_hourly_only(self):
        t = AI.invoice_totals(1000.0, 0.0, 0.0)
        self.assertEqual(t["ex_gst"], 1000.00)
        self.assertEqual(t["gst"], 100.00)
        self.assertEqual(t["inc_gst"], 1100.00)
        self.assertFalse(t["pdf_xero_differ"])
        # With no flat lines the two formulas cannot disagree.
        self.assertEqual(t["xero_ex_gst"], 1000.00)

    def test_flat_only_qty_one(self):
        """qty 1 everywhere: the PDF and the Xero export agree."""
        t = AI.invoice_totals(0.0, 450.0, 450.0)
        self.assertEqual(t["ex_gst"], 450.00)
        self.assertEqual(t["gst"], 45.00)
        self.assertEqual(t["inc_gst"], 495.00)
        self.assertEqual(t["xero_ex_gst"], 450.00)
        self.assertFalse(t["pdf_xero_differ"])

    def test_flat_with_qty_above_one_is_where_they_part(self):
        """One flat line of $450 with qty 3: the PDF charges 450, Xero 1350."""
        t = AI.invoice_totals(0.0, 450.0, 1350.0)
        self.assertEqual(t["ex_gst"], 450.00)        # the PDF, which is the invoice
        self.assertEqual(t["inc_gst"], 495.00)
        self.assertEqual(t["xero_ex_gst"], 1350.00)  # what the Xero file would say
        self.assertEqual(t["xero_inc_gst"], 1485.00)
        self.assertTrue(t["pdf_xero_differ"])

    def test_mixed(self):
        t = AI.invoice_totals(2500.50, 450.0, 450.0)
        self.assertEqual(t["ex_gst"], 2950.50)
        self.assertEqual(t["gst"], 295.05)
        self.assertEqual(t["inc_gst"], 3245.55)
        self.assertFalse(t["pdf_xero_differ"])

    def test_the_flag_is_set_only_when_the_two_flat_sums_differ(self):
        """Not when there is merely a flat line, and not when only the hourly
        side is large — the flag is about the DISAGREEMENT, nothing else."""
        self.assertFalse(AI.invoice_totals(99.99, 0.0, 0.0)["pdf_xero_differ"])
        self.assertFalse(AI.invoice_totals(99.99, 10.0, 10.0)["pdf_xero_differ"])
        self.assertTrue(AI.invoice_totals(99.99, 10.0, 20.0)["pdf_xero_differ"])
        # Compared UNROUNDED: a sub-cent disagreement is still a disagreement.
        self.assertTrue(AI.invoice_totals(0.0, 10.001, 10.002)["pdf_xero_differ"])

    def test_an_invoice_with_no_lines_totals_zero_rather_than_failing(self):
        t = AI.invoice_totals(0, 0, 0)
        self.assertEqual((t["ex_gst"], t["gst"], t["inc_gst"]), (0.0, 0.0, 0.0))
        # None is what a SUM() over no rows comes back as.
        t = AI.invoice_totals(None, None, None)
        self.assertEqual(t["ex_gst"], 0.0)


class GstRounding(unittest.TestCase):
    """Which GST the code computes, and that it is invoice.tpl's."""

    # Two lines of $12.345. Three ways to reach the GST:
    #   (a) round each line's GST to cents, then add:  1.23 + 1.23 = 2.46
    #   (b) add the per-line GST unrounded, then round: 1.2345 x 2 = 2.469 -> 2.47
    #   (c) 10% of the total, then round:               24.69 x 0.10 -> 2.47
    # invoice.tpl sums `linetotal x 0.1` per line and adds, with no rounding
    # along the way, so it is (b). In exact Decimal (b) and (c) are the SAME
    # NUMBER; only (a) differs, and (a) is a cent light.
    LINES = [hourly(1, 12.345, 1), hourly(1, 12.345, 1)]

    def test_the_code_uses_the_unrounded_sum_not_the_per_line_rounded_one(self):
        d = AI.invoice_detail(self.LINES)
        self.assertEqual(d["totals"]["ex_gst"], 24.69)
        self.assertEqual(d["totals"]["gst"], 2.47)          # (b)/(c), not (a)
        self.assertNotEqual(d["totals"]["gst"], 2.46)       # (a) would be wrong
        self.assertEqual(d["totals"]["inc_gst"], 27.16)

    def test_per_line_rounding_really_would_differ(self):
        """Proof the case above is a real one, not a case that cannot distinguish
        the two methods: the displayed per-line GST adds to a cent less."""
        views = AI.invoice_detail(self.LINES)["lines"]
        per_line_rounded = sum(round(v["line_total"] * 0.10, 2) for v in views)
        self.assertEqual(round(per_line_rounded, 2), 2.46)
        self.assertEqual(AI.detail_totals(views)["gst"], 2.47)

    def test_summing_first_and_per_line_agree_when_nothing_is_rounded_early(self):
        """0.10 x Sigma(line) == Sigma(0.10 x line) in Decimal. Stated as a test
        so a future change that quantizes inside the loop fails here."""
        d = AI.invoice_detail(self.LINES)["totals"]
        self.assertAlmostEqual(d["gst"], round(d["ex_gst_exact"] * 0.10, 2), places=6)

    def test_the_detail_totals_agree_with_the_list_sums_on_the_same_data(self):
        """The list adds in SQL and the detail adds in Python. If they can
        disagree, one of the two screens is lying."""
        lines = [hourly(3, 61.50, 8.5), flat(450.0, qty=1), hourly(2, 61.50, 4)]
        detail = AI.invoice_detail(lines)["totals"]
        hourly_sum = 3 * 61.50 * 8.5 + 2 * 61.50 * 4
        listed = AI.invoice_totals(hourly_sum, 450.0, 450.0)
        self.assertEqual(detail["ex_gst"], listed["ex_gst"])
        self.assertEqual(detail["gst"], listed["gst"])
        self.assertEqual(detail["inc_gst"], listed["inc_gst"])


class LineView(unittest.TestCase):

    def test_an_hourly_line_is_qty_times_rate_times_hours(self):
        v = AI.line_view(hourly(3, 61.50, 8.5))
        self.assertEqual(v["kind"], "hourly")
        self.assertEqual(v["line_total_display"], 1568.25)   # 3 x 61.50 x 8.5
        self.assertEqual(v["per_worker_display"], 522.75)    # 61.50 x 8.5
        self.assertFalse(v["xero_differs"])                  # same both ways

    def test_a_flat_line_ignores_qty_for_the_pdf_and_does_not_for_xero(self):
        v = AI.line_view(flat(450.0, qty=3))
        self.assertEqual(v["kind"], "flat")
        self.assertEqual(v["line_total_display"], 450.00)
        self.assertEqual(v["xero_line_total_display"], 1350.00)
        self.assertTrue(v["xero_differs"])
        # A flat line has no per-worker rate to show.
        self.assertIsNone(v["per_worker"])

    def test_a_flat_line_with_qty_one_does_not_raise_the_flag(self):
        self.assertFalse(AI.line_view(flat(450.0, qty=1))["xero_differs"])

    def test_the_date_is_melbourne_and_dd_mm_yy(self):
        v = AI.line_view(hourly(1, 10, 1, start_date=TS_0900_NOV))
        self.assertEqual(v["date"], "18/11/26")
        self.assertEqual(v["weekday"], "Wednesday")

    def test_no_date_is_blank_not_nineteen_seventy(self):
        self.assertEqual(AI.line_view(hourly(1, 10, 1, start_date=0))["date"], "")
        self.assertEqual(AI.line_view(hourly(1, 10, 1, start_date=None))["date"], "")


class Spacers(unittest.TestCase):
    """'<< BLANK_LINE >>' is layout, not a line."""

    LINES = [hourly(2, 61.50, 8), spacer(), hourly(2, 61.50, 4), spacer()]

    def test_a_spacer_is_recognised_and_carries_no_money(self):
        v = AI.line_view(spacer())
        self.assertTrue(v["is_spacer"])
        self.assertEqual(v["kind"], "spacer")
        self.assertEqual(v["line_total"], 0.0)

    def test_spacers_are_excluded_from_the_totals_and_the_line_count(self):
        t = AI.invoice_detail(self.LINES)["totals"]
        self.assertEqual(t["line_count"], 2)
        self.assertEqual(t["ex_gst"], 2 * 61.50 * 8 + 2 * 61.50 * 4)

    def test_spacers_are_still_returned_so_the_panel_can_draw_them(self):
        """They are dropped from the arithmetic, NOT from the list: the PDF
        prints them between calls and the panel has to match its order."""
        views = AI.invoice_detail(self.LINES)["lines"]
        self.assertEqual(len(views), 4)
        self.assertEqual([v["is_spacer"] for v in views], [False, True, False, True])

    def test_a_spacer_that_somehow_carried_a_rate_still_counts_for_nothing(self):
        odd = spacer(qty=4, chargeout_rate=61.50, hours=8, flatrate=99)
        self.assertEqual(AI.line_view(odd)["line_total"], 0.0)
        self.assertEqual(AI.detail_totals([AI.line_view(odd)])["ex_gst"], 0.0)


class TimeText(unittest.TestCase):
    """start_time and end_time hold one of three shapes, or something else."""

    def test_unix_seconds_as_text_read_in_melbourne(self):
        self.assertEqual(AI._time_text(str(TS_0900_NOV)), ("09:00", False))
        # Across the daylight-saving switch: AEST, not AEDT.
        self.assertEqual(AI._time_text(str(TS_1730_JUN)), ("17:30", False))

    def test_unix_seconds_as_an_int_too(self):
        self.assertEqual(AI._time_text(TS_0900_NOV), ("09:00", False))

    def test_hh_mm_is_kept(self):
        self.assertEqual(AI._time_text("08:00"), ("08:00", False))

    def test_a_single_digit_hour_is_padded_to_the_24_hour_clock(self):
        self.assertEqual(AI._time_text("8:30"), ("08:30", False))

    def test_sec_to_time_seconds_are_dropped_and_past_midnight_is_kept(self):
        """SEC_TO_TIME() writes 'HH:MM:SS', and '28:00:00' for a shift that ran
        past midnight. Both are real stored values; neither is a guess."""
        self.assertEqual(AI._time_text("20:00:00"), ("20:00", False))
        self.assertEqual(AI._time_text("28:00:00"), ("28:00", False))

    def test_empty_is_empty(self):
        self.assertEqual(AI._time_text(""), ("", False))
        self.assertEqual(AI._time_text(None), ("", False))
        self.assertEqual(AI._time_text("   "), ("", False))

    def test_anything_else_comes_back_unchanged_and_marked_raw(self):
        """Never guessed. A value this cannot read is shown as it is stored, so
        it looks wrong on screen rather than looking plausible."""
        for junk in ("noon", "8am", "-", "08.00"):
            self.assertEqual(AI._time_text(junk), (junk, True), junk)

    def test_a_number_too_large_to_be_a_date_is_raw_rather_than_a_crash(self):
        text, raw = AI._time_text("99999999999999999999")
        self.assertEqual(text, "99999999999999999999")
        self.assertTrue(raw)

    def test_the_raw_flag_reaches_the_line_view(self):
        v = AI.line_view(hourly(1, 10, 1, start_time="noon", end_time="16:00"))
        self.assertEqual(v["start"], "noon")
        self.assertTrue(v["start_raw"])
        self.assertEqual(v["end"], "16:00")
        self.assertFalse(v["end_raw"])


class Invoice34334PublishedFigures(unittest.TestCase):
    """The #34334 regression, from the PUBLISHED numbers alone (fix 1 \u00a71).

    No fixture, no customer data, so this runs on every machine -- including a
    fresh public checkout, where Invoice34334 below is skipped for want of the
    response file. Everything here was already written down in the brief: the
    PDF's three figures off the 18 Nov screenshot, and the hourly_sum the list
    endpoint sent before the CAST.

    Invoice34334 proves these figures come out of #34334's own 34 lines. This
    class makes sure that if the file is missing, THE CENT IS STILL PINNED.
    """

    PDF_EX_GST = 32783.95
    PDF_GST    = 3278.40
    PDF_TOTAL  = 36062.35

    FLOAT_SUM_BEFORE_THE_FIX = 32783.949155807

    def test_the_decimal_string_the_sql_now_returns_gives_the_pdf(self):
        t = AI.invoice_totals("32783.95000000", "0", "0")
        self.assertEqual((t["ex_gst"], t["gst"], t["inc_gst"]),
                         (self.PDF_EX_GST, self.PDF_GST, self.PDF_TOTAL))

    def test_the_float_sum_the_sql_used_to_return_is_a_cent_light(self):
        """REGRESSION NOTE, not a wish: this is what the list showed before the
        CAST. Delete this test and the reason for the cast goes with it."""
        t = AI.invoice_totals(self.FLOAT_SUM_BEFORE_THE_FIX, 0, 0)
        self.assertEqual(t["gst"], 3278.39)
        self.assertEqual(t["inc_gst"], 36062.34)

    def test_the_half_cent_is_what_decides_it(self):
        """32,783.95 x 10% is 3,278.395 exactly. ROUND_HALF_UP takes that up;
        a total a whisker under takes it down. The entire bug, in two lines."""
        from decimal import Decimal, ROUND_HALF_UP
        exact = Decimal("32783.95") * Decimal("0.10")
        self.assertEqual(exact, Decimal("3278.3950"))
        self.assertEqual(exact.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP),
                         Decimal("3278.40"))
        under = Decimal("32783.949155807") * Decimal("0.10")
        self.assertEqual(under.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP),
                         Decimal("3278.39"))


@needs_34334
class Invoice34334(unittest.TestCase):
    """The half-cent that put the list a cent under the PDF (fix 1 \u00a71).

    Found on test, 18 Nov 2026: invoice #34334's dialog agreed with the
    SmartStaff PDF at $36,062.35, and the LIST said $36,062.34.

    WHY. chargeout_rate and hours are FLOAT columns, so MySQL stores them in
    single precision and 89.95 is really 89.9499969... Summed INSIDE SQL those
    errors accumulate and the ex-GST total lands a fraction low. The exact sum
    is 32,783.950, so the GST is 3,278.395 EXACTLY -- a half cent, which
    ROUND_HALF_UP takes to 3,278.40. A total a fraction under 32,783.95 takes
    it to 3,278.39 instead. The whole bug is that one half cent.

    The fix is CAST(... AS DECIMAL(12,4)) in list-invoices.php's query 3, which
    rounds the stored float back to the 89.9500 that mysqli's text protocol
    returns row by row -- the value the PDF, and get-invoice.php, already use.

    THESE ARE #34334's REAL 34 LINES, from get-invoice.php on test. Nothing
    here is computed from anything this module produced: the three figures are
    the PDF's, read off the 18 Nov screenshot, and the float sum is the one the
    list endpoint actually sent before the cast.
    """

    # The same three figures Invoice34334PublishedFigures pins without the
    # fixture. Repeated rather than imported from it: this class is about
    # whether #34334's REAL LINES produce them, and a reader should be able to
    # see what is being claimed without going to another class for it.
    PDF_EX_GST = 32783.95
    PDF_GST    = 3278.40
    PDF_TOTAL  = 36062.35

    # What list-invoices.php sent BEFORE the cast: SUM() over single-precision
    # floats, as read off test. test_single_precision_rates_... below rebuilds
    # this number from the fixture's own rates, which is what makes it evidence
    # of the mechanism rather than a magic constant.
    FLOAT_SUM_BEFORE_THE_FIX = 32783.949155807

    @classmethod
    def setUpClass(cls):
        cls.fix    = load_34334()
        cls.lines  = cls.fix["lines"]
        cls.priced = [l for l in cls.lines if not AI.is_spacer(l)]

    def test_the_fixture_is_the_real_response_for_34334(self):
        """A guard on the evidence itself. If the file is ever replaced with a
        different invoice, every figure below silently starts pinning the wrong
        thing -- so the file has to identify itself first."""
        self.assertEqual(self.fix["invoice"]["id"], 34334)
        self.assertEqual(self.fix["booking"]["name"], "FIVE @ RLA")
        self.assertEqual(len(self.lines), 34)
        self.assertEqual(len(self.priced), 22)
        self.assertEqual(len(self.lines) - len(self.priced), 12)   # the spacers
        self.assertEqual(self.fix["invoice"]["call_list"],
                         [37597, 37598, 37599, 37600, 37601, 37602, 37603, 37604, 37605])

    def test_the_lines_total_what_the_pdf_shows(self):
        """THE ONE THAT MATTERS. This is the PDF, so it is pinned."""
        t = AI.invoice_detail(self.lines)["totals"]
        self.assertEqual(t["ex_gst"], self.PDF_EX_GST)
        self.assertEqual(t["gst"], self.PDF_GST)
        self.assertEqual(t["inc_gst"], self.PDF_TOTAL)
        self.assertEqual(t["line_count"], 22)      # the 12 spacers are not lines

    def test_the_gst_really_is_an_exact_half_cent(self):
        """Not an approximation of one. Recomputed straight from the fixture's
        columns, so it says something about #34334 rather than about this
        module: if this stops being exact the invoice has changed and the rest
        of this class proves nothing."""
        from decimal import Decimal
        exact = Decimal("0")
        for l in self.priced:
            exact += (Decimal(l["qty"]) * Decimal(str(l["chargeout_rate"]))
                      * Decimal(str(l["hours"])))
        self.assertEqual(exact, Decimal("32783.950"))
        self.assertEqual(exact * Decimal("0.10"), Decimal("3278.3950000"))

    def test_the_list_path_agrees_once_the_sql_returns_a_decimal_string(self):
        """What list-invoices.php now sends: a DECIMAL as a string, straight
        from mysqli, never cast to float on the way."""
        t = AI.invoice_totals("32783.95000000", "0", "0")
        self.assertEqual(t["ex_gst"], self.PDF_EX_GST)
        self.assertEqual(t["gst"], self.PDF_GST)
        self.assertEqual(t["inc_gst"], self.PDF_TOTAL)

    def test_the_list_and_the_detail_now_give_the_same_three_figures(self):
        """The bug was these two disagreeing. One invoice, one answer."""
        detail = AI.invoice_detail(self.lines)["totals"]
        listed = AI.invoice_totals("32783.95000000", "0", "0")
        for k in ("ex_gst", "gst", "inc_gst"):
            self.assertEqual(detail[k], listed[k], k)

    def test_the_old_float_sum_is_a_cent_light_which_is_why_the_sql_casts(self):
        """REGRESSION NOTE, not a wish: this is what the list showed before the
        CAST, and it is a cent under the PDF. Delete this test and the reason
        for the cast goes with it."""
        t = AI.invoice_totals(self.FLOAT_SUM_BEFORE_THE_FIX, 0, 0)
        self.assertEqual(t["gst"], 3278.39)
        self.assertEqual(t["inc_gst"], 36062.34)
        self.assertNotEqual(t["gst"], self.PDF_GST)
        self.assertNotEqual(t["inc_gst"], self.PDF_TOTAL)

    def test_single_precision_rates_reproduce_the_number_the_list_actually_sent(self):
        """THE MECHANISM, PROVED WITHOUT A DATABASE. Round every rate and hours
        to float32 (what a MySQL FLOAT column holds), then multiply and add in
        doubles (what SUM(qty * chargeout_rate * hours) does).

        It comes out at 32783.949155807495 -- the hourly_sum the list endpoint
        sent from test, to every digit Mike recorded. So the diagnosis is not
        an inference about floats in general; it is this invoice's own rates
        reproducing this invoice's own wrong total."""
        def f32(x):
            return struct.unpack("f", struct.pack("f", float(x)))[0]

        sql_sum = 0.0
        for l in self.priced:
            sql_sum += l["qty"] * f32(l["chargeout_rate"]) * f32(l["hours"])

        self.assertAlmostEqual(sql_sum, self.FLOAT_SUM_BEFORE_THE_FIX, places=9)
        self.assertLess(sql_sum, self.PDF_EX_GST)
        # Still 32,783.95 once rounded to cents -- the ex-GST column was never
        # the visible symptom. The GST is where a half cent decides.
        self.assertEqual(AI.invoice_totals(sql_sum, 0, 0)["ex_gst"], self.PDF_EX_GST)
        self.assertEqual(AI.invoice_totals(sql_sum, 0, 0)["gst"], 3278.39)

    def test_34334_has_no_flat_rate_lines_so_it_cannot_show_the_xero_split(self):
        """Stated so the synthetic flat-rate cases in InvoiceTotals are not
        mistaken for duplicates of this class: #34334 is all hourly, and the
        PDF and the Xero export agree about it."""
        self.assertEqual([l for l in self.priced if float(l["flatrate"] or 0) > 0], [])
        self.assertFalse(AI.invoice_detail(self.lines)["totals"]["pdf_xero_differ"])

    def test_every_real_description_survives_as_plain_text(self):
        """18 distinct descriptions of real SmartStaff markup, including
        '(Night/Sun/Pub HolB)'. None may reach the page as markup, and none may
        lose its name."""
        seen = set()
        for l in self.priced:
            raw = l["description"]
            seen.add(raw)
            main, note = AI.description_parts(raw)
            self.assertNotEqual(main, "", raw)        # the name is never lost
            self.assertTrue(note.startswith("("), raw)
            for field in (main, note):
                self.assertNotIn("<", field, raw)
                self.assertNotIn(">", field, raw)
                self.assertNotIn("font-size", field, raw)
        self.assertEqual(len(seen), 18)

    def test_every_real_time_is_read_rather_than_handed_back_raw(self):
        """#34334's lines carry "HH:MM" throughout, including the 00:00 that
        means midnight on a Load Out. Nothing in it should trip the raw flag --
        if something does, _time_text has a shape it cannot read."""
        for v in AI.invoice_detail(self.lines)["lines"]:
            self.assertFalse(v["start_raw"], v)
            self.assertFalse(v["end_raw"], v)

    def test_the_sums_are_read_as_decimals_whether_they_arrive_as_strings_or_not(self):
        """list-invoices.php sends strings now; nothing else should have to
        care, and a float-sending caller must not start failing."""
        a = AI.invoice_totals("1000.00", "450.0000", "1350.0000")
        b = AI.invoice_totals(1000.0, 450.0, 1350.0)
        self.assertEqual(a, b)
        # And the no-lines default the PHP now sends for an invoice with none.
        z = AI.invoice_totals("0", "0", "0")
        self.assertEqual((z["ex_gst"], z["gst"], z["inc_gst"]), (0.0, 0.0, 0.0))
        self.assertFalse(z["pdf_xero_differ"])


class DescriptionParts(unittest.TestCase):
    """Descriptions arrive as markup and leave as two plain-text fields.

    The generator writes the grade suffix as an <i> element and the PDF renders
    it. THE GOAT cannot: putting stored markup into the page as markup is how a
    customer name becomes a script tag. So it is split HERE and the page
    escapes both halves and supplies its own <span> (fix 1 §2).
    """

    def test_a_generated_line_splits_into_name_and_grade_suffix(self):
        self.assertEqual(
            AI.description_parts('Crew Boss <i style="font-size: 9px;">(Night/Crew Boss)</i>'),
            ("Crew Boss", "(Night/Crew Boss)"))

    def test_the_longest_real_suffix_too(self):
        self.assertEqual(
            AI.description_parts('Load Out <i style="font-size: 9px;">(Night/Sun/Pub HolB)</i>'),
            ("Load Out", "(Night/Sun/Pub HolB)"))

    def test_a_hand_added_line_has_no_markup_and_keeps_all_of_its_text(self):
        self.assertEqual(AI.description_parts("Vehicle hire"), ("Vehicle hire", ""))

    def test_entities_are_decoded_exactly_once(self):
        """SmartStaff stores names encoded. Decoded here; the page must NOT
        decode again, or `&amp;amp;` would come out as a bare `&`."""
        self.assertEqual(AI.description_parts("Smith &amp; Co <i>(Day/T1)</i>"),
                         ("Smith & Co", "(Day/T1)"))
        self.assertEqual(AI.description_parts("Smith &amp;amp; Co"), ("Smith &amp; Co", ""))

    def test_a_script_tag_goes_with_its_contents(self):
        """The tag AND its text. "alert(1)" is code, and showing it as part of
        a line would be worse than showing nothing."""
        self.assertEqual(AI.description_parts("<script>alert(1)</script>Fork"), ("Fork", ""))
        self.assertEqual(AI.description_parts("Fork<style>body{}</style>"), ("Fork", ""))

    def test_an_unterminated_script_tag_leaves_nothing_behind(self):
        self.assertEqual(AI.description_parts("<script>alert(1)"), ("", ""))
        self.assertEqual(AI.description_parts("Fork <SCRIPT SRC=x>"), ("Fork", ""))

    def test_an_ordinary_tag_is_stripped_but_its_text_is_kept(self):
        """Only script and style lose their contents. Anything else is markup
        around words that belong to the description."""
        self.assertEqual(AI.description_parts("Load In <b>urgent</b>"), ("Load In", "urgent"))

    def test_a_description_beginning_with_a_tag_keeps_its_words_as_the_name(self):
        """No leading plain text means the words after the tag ARE the name.
        Returning them as the suffix would draw the whole line muted and
        italic, as though the description had no name at all."""
        self.assertEqual(AI.description_parts("<img src=x onerror=alert(1)>Fork"),
                         ("Fork", ""))
        self.assertEqual(AI.description_parts("<i>(Day/T1)</i>"), ("(Day/T1)", ""))

    def test_text_after_the_italic_is_not_dropped(self):
        """Nothing readable is thrown away — on a screen about money, text that
        silently vanishes is the worst failure available."""
        self.assertEqual(AI.description_parts("A <i>(x)</i> trailing"), ("A", "(x) trailing"))

    def test_nothing_at_all(self):
        self.assertEqual(AI.description_parts(None), ("", ""))
        self.assertEqual(AI.description_parts(""), ("", ""))
        self.assertEqual(AI.description_parts("   "), ("", ""))

    def test_whitespace_is_collapsed(self):
        self.assertEqual(AI.description_parts("  Crew   Boss \n <i> (Day/T1) </i> "),
                         ("Crew Boss", "(Day/T1)"))

    def test_the_two_fields_reach_the_line_view_and_the_raw_one_is_kept(self):
        """The raw description stays in the payload so it can be compared with
        SmartStaff; the page draws the two plain fields instead."""
        raw = 'Crew Boss <i style="font-size: 9px;">(Night/Crew Boss)</i>'
        v = AI.line_view(hourly(2, 89.95, 8, description=raw))
        self.assertEqual(v["desc_main"], "Crew Boss")
        self.assertEqual(v["desc_note"], "(Night/Crew Boss)")
        self.assertEqual(v["description"], raw)

    def test_no_markup_survives_into_either_field(self):
        """The property that matters, over every shape above."""
        for raw in ('Crew Boss <i style="font-size: 9px;">(Night/Crew Boss)</i>',
                    "<script>alert(1)</script>Fork",
                    "Smith &amp; Co <i>(Day/T1)</i>",
                    "Load In <b>urgent</b>",
                    "<img src=x onerror=alert(1)>Fork",
                    AI.SPACER_DESCRIPTION):
            main, note = AI.description_parts(raw)
            for field in (main, note):
                self.assertNotIn("<", field, raw)
                self.assertNotIn(">", field, raw)
                self.assertNotIn("onerror", field, raw)
                self.assertNotIn("alert", field, raw)

    def test_a_spacer_has_no_description_to_show(self):
        """It is a sentinel, not a description. Written as a test because the
        tag stripper reads '<< BLANK_LINE >' as a tag and leaves a bare '>'
        behind -- which is what the property test above caught."""
        self.assertEqual(AI.description_parts(AI.SPACER_DESCRIPTION), ("", ""))
        v = AI.line_view(spacer())
        self.assertEqual((v["desc_main"], v["desc_note"]), ("", ""))
        # And is_spacer still reads the RAW description, so the splitter is not
        # what decides whether a spacer is a spacer.
        self.assertTrue(AI.is_spacer(spacer()))
        self.assertEqual(v["description"], AI.SPACER_DESCRIPTION)


if __name__ == "__main__":
    unittest.main(verbosity=2)

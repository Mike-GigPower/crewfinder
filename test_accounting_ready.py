#!/usr/bin/env python3
"""Ready to invoice, and the Generate confirm — the pure side.

BRIEF-accounting-slice-b.md §7 ("Pure"). Everything here runs without a
database, a SmartStaff session or a browser: list-ready-to-invoice.php has
already classified the calls, and these are the functions that turn its answer
into figures and sentences.

THE FINGERPRINT IS TESTED AGAINST THE REAL PHP. accounting_invoices.py and
accounting-ready-lib.php each have an implementation, and if they ever disagree
Generate refuses every booking with `changed` — a bug that would look like a
data problem, not a code one. FingerprintMatchesPHP extracts the shipped PHP
function and runs it, so the two are pinned together rather than merely
believed to agree.

Run:  python3 test_accounting_ready.py
"""
import io
import json
import os
import re
import shutil
import subprocess
import unittest
from decimal import Decimal

import accounting_invoices as AI

HERE = os.path.dirname(os.path.abspath(__file__))
LIB  = os.path.join(HERE, "smartstaff", "accounting-ready-lib.php")
PHP  = shutil.which("php")


def booking(**over):
    """A ready booking in list-ready-to-invoice.php's own shape."""
    b = {
        "booking_id": 90001,
        "booking_name": "Test Show @ Test Venue",
        "booking_reference": "TEST-2026-05",
        "customer_id": 90024,
        "customer_name": "Test Customer Pty Ltd",
        "ready_calls": [
            {"call_id": 37598, "call_name": "Load in", "start_date": 1748131200,
             "crew_rows": 9, "hours": "72.000000", "expected_charge": "4428.0000"},
            {"call_id": 37599, "call_name": "Show", "start_date": 1748217600,
             "crew_rows": 12, "hours": "96.000000", "expected_charge": "5904.0000"},
        ],
        "stuck_calls": [],
        "unlocked_calls": 0,
        "locked_no_crew_calls": 0,
        "blocked": False,
        "expected_exact": True,
        "mixed_groups": 0,
        "null_calc": 0,
        "fingerprint": AI.ready_fingerprint([37598, 37599]),
    }
    b.update(over)
    return b


# ──────────────────────────────────────────────────────────────────────────────
class Fingerprint(unittest.TestCase):

    def test_the_order_the_ids_arrive_in_cannot_change_it(self):
        a = AI.ready_fingerprint([103, 101, 102])
        b = AI.ready_fingerprint([102, 103, 101])
        c = AI.ready_fingerprint([101, 102, 103])
        self.assertEqual(a, b)
        self.assertEqual(b, c)

    def test_a_different_set_is_a_different_fingerprint(self):
        """The whole point: one more locked call must not pass as the same."""
        self.assertNotEqual(AI.ready_fingerprint([101, 102]),
                            AI.ready_fingerprint([101, 102, 103]))

    def test_strings_and_ints_are_the_same_call(self):
        self.assertEqual(AI.ready_fingerprint(["101", 102]),
                         AI.ready_fingerprint([101, "102"]))

    def test_it_is_forty_hex_characters(self):
        """app.py and generate-invoice.php both validate that shape."""
        self.assertRegex(AI.ready_fingerprint([1, 2, 3]), r"^[0-9a-f]{40}$")
        self.assertRegex(AI.ready_fingerprint([]), r"^[0-9a-f]{40}$")

    def test_duplicates_do_not_change_it(self):
        self.assertNotEqual(AI.ready_fingerprint([101, 101]),
                            AI.ready_fingerprint([101]))
        # PHP's sort() keeps duplicates too, so this is agreement, not a bug —
        # FingerprintMatchesPHP pins it.


@unittest.skipUnless(PHP and os.path.exists(LIB), "php or the lib file is not here")
class FingerprintMatchesPHP(unittest.TestCase):
    """The shipped PHP function, run for real, against the Python one."""

    @classmethod
    def setUpClass(cls):
        with io.open(LIB, encoding="utf-8") as fh:
            src = fh.read()
        i = src.find("function goat_ready_fingerprint")
        if i < 0:
            raise AssertionError("goat_ready_fingerprint is not in " + LIB)
        # Brace-match the function so the test runs the REAL body, not a copy.
        j = src.index("{", i)
        depth, k = 0, j
        while k < len(src):
            if src[k] == "{":
                depth += 1
            elif src[k] == "}":
                depth -= 1
                if depth == 0:
                    break
            k += 1
        cls.fn = src[i:k + 1]

    def php_hash(self, ids):
        script = (self.fn + "\n$ids = json_decode($argv[1], true);\n"
                  + "echo goat_ready_fingerprint($ids);\n")
        out = subprocess.run([PHP, "-r", script, json.dumps(ids)],
                             capture_output=True, text=True, timeout=30)
        self.assertEqual(out.returncode, 0, out.stderr)
        return out.stdout.strip()

    def test_the_two_implementations_agree(self):
        for ids in ([], [37598], [103, 101, 102], [1, 2, 3, 4, 5],
                    [999999, 1], [101, 101]):
            self.assertEqual(self.php_hash(ids), AI.ready_fingerprint(ids), ids)

    def test_they_agree_on_strings_too(self):
        """Flask forwards what PHP sent; both sides (int)-cast the same way."""
        self.assertEqual(self.php_hash(["102", "101"]),
                         AI.ready_fingerprint(["102", "101"]))


# ──────────────────────────────────────────────────────────────────────────────
class Classification(unittest.TestCase):
    """ready / stuck / blocked, as it reaches the screen (§3.1)."""

    def test_a_plain_booking_is_not_blocked_and_has_its_money(self):
        v = AI.ready_view(booking())
        self.assertFalse(v["blocked"])
        self.assertEqual(v["block_reason"], "")
        self.assertEqual(v["ready_count"], 2)
        self.assertEqual(v["expected"]["ex_gst"], 10332.00)

    def test_a_stuck_call_blocks_the_booking(self):
        """§3.1: Generate would write the stuck call's lines onto the NEW
        invoice, so the booking is refused, not warned about."""
        v = AI.ready_view(booking(stuck_calls=[
            {"call_id": 37598, "invoice_id": 34012, "call_name": "Load in",
             "start_date": 1748131200}]))
        self.assertTrue(v["blocked"])
        self.assertIn("#37598", v["block_reason"])
        self.assertIn("#34012", v["block_reason"])
        self.assertIn("never marked invoiced", v["block_reason"])

    def test_blocked_survives_even_if_php_only_sent_the_flag(self):
        self.assertTrue(AI.ready_view(booking(blocked=True))["blocked"])

    def test_several_stuck_calls_are_all_named(self):
        v = AI.ready_view(booking(stuck_calls=[
            {"call_id": 1, "invoice_id": 10, "call_name": "a", "start_date": 0},
            {"call_id": 2, "invoice_id": 11, "call_name": "b", "start_date": 0}]))
        self.assertIn("#1", v["block_reason"])
        self.assertIn("#2", v["block_reason"])

    def test_the_notes_say_what_will_and_will_not_be_on_the_invoice(self):
        v = AI.ready_view(booking(unlocked_calls=2, locked_no_crew_calls=1))
        self.assertIn("2 calls not locked yet", v["notes"])
        self.assertIn("1 locked call with no crew will be included", v["notes"])

    def test_the_notes_are_singular_for_one(self):
        v = AI.ready_view(booking(unlocked_calls=1, locked_no_crew_calls=2))
        self.assertIn("1 call not locked yet", v["notes"])
        self.assertIn("2 locked calls with no crew will be included", v["notes"])

    def test_no_notes_when_there_is_nothing_to_say(self):
        self.assertEqual(AI.ready_view(booking())["notes"], [])

    def test_an_inexact_prediction_says_so(self):
        """mixed crew groups or NULL `calculated` — the figure is an estimate
        and generate-invoice.php will skip its money check."""
        v = AI.ready_view(booking(expected_exact=False, mixed_groups=1))
        self.assertTrue(any("estimate" in n for n in v["notes"]))

    def test_each_ready_call_gets_its_own_rounded_figure_and_a_date(self):
        v = AI.ready_view(booking())
        c = v["ready_calls"][0]
        self.assertEqual(c["expected_ex_gst"], 4428.00)
        self.assertRegex(c["date_text"], r"^[A-Z][a-z]{2} \d{1,2} [A-Z][a-z]{2}$")

    def test_a_date_range_collapses_when_every_call_is_one_day(self):
        b = booking()
        b["ready_calls"][1]["start_date"] = b["ready_calls"][0]["start_date"]
        self.assertNotIn("–", AI.ready_view(b)["date_range"])

    def test_a_date_range_spans_when_they_differ(self):
        self.assertIn("–", AI.ready_view(booking())["date_range"])

    def test_a_missing_date_is_not_1970(self):
        b = booking()
        for c in b["ready_calls"]:
            c["start_date"] = 0
        self.assertEqual(AI.ready_view(b)["date_range"], "")

    def test_the_fingerprint_and_call_ids_travel_through_untouched(self):
        """ready_view must not reshape what Generate has to send back."""
        b = booking()
        v = AI.ready_view(b)
        self.assertEqual(v["fingerprint"], b["fingerprint"])
        self.assertEqual([c["call_id"] for c in v["ready_calls"]], [37598, 37599])


# ──────────────────────────────────────────────────────────────────────────────
class Totals(unittest.TestCase):

    def test_the_expected_total_goes_through_invoice_totals(self):
        """The same function the invoice list and the dialog use, so a ready
        booking and the invoice it becomes are priced by one code path."""
        v = AI.ready_view(booking())
        direct = AI.invoice_totals("10332.0000", "0", "0")
        self.assertEqual(v["expected"]["ex_gst"],  direct["ex_gst"])
        self.assertEqual(v["expected"]["gst"],     direct["gst"])
        self.assertEqual(v["expected"]["inc_gst"], direct["inc_gst"])

    def test_gst_is_ten_per_cent_and_inc_is_the_sum(self):
        v = AI.ready_view(booking())
        self.assertEqual(v["expected"]["gst"], 1033.20)
        self.assertEqual(v["expected"]["inc_gst"], 11365.20)

    def test_a_group_total_is_summed_then_rounded_once(self):
        """NOT the sum of rounded parts. Two bookings at 10.125 each are
        20.25 exactly; rounding each first would give 20.26."""
        a = booking(booking_id=1, ready_calls=[
            {"call_id": 1, "call_name": "a", "start_date": 0,
             "crew_rows": 1, "hours": "1", "expected_charge": "10.1250"}],
            stuck_calls=[])
        b = booking(booking_id=2, ready_calls=[
            {"call_id": 2, "call_name": "b", "start_date": 0,
             "crew_rows": 1, "hours": "1", "expected_charge": "10.1250"}],
            stuck_calls=[])
        g = AI.ready_groups([a, b])
        self.assertEqual(len(g["groups"]), 1)
        self.assertEqual(g["groups"][0]["totals"]["ex_gst"], 20.25)
        # And each booking on its own still rounds half up, as the PDF does.
        self.assertEqual(g["groups"][0]["bookings"][0]["expected"]["ex_gst"], 10.13)

    def test_the_overall_total_is_also_summed_exactly(self):
        a = booking(booking_id=1, customer_id=1, customer_name="A", ready_calls=[
            {"call_id": 1, "call_name": "a", "start_date": 0, "crew_rows": 1,
             "hours": "1", "expected_charge": "10.1250"}])
        b = booking(booking_id=2, customer_id=2, customer_name="B", ready_calls=[
            {"call_id": 2, "call_name": "b", "start_date": 0, "crew_rows": 1,
             "hours": "1", "expected_charge": "10.1250"}])
        g = AI.ready_groups([a, b])
        self.assertEqual(len(g["groups"]), 2)
        self.assertEqual(g["totals"]["ex_gst"], 20.25)


# ──────────────────────────────────────────────────────────────────────────────
class Grouping(unittest.TestCase):

    def test_bookings_are_grouped_by_customer(self):
        g = AI.ready_groups([
            booking(booking_id=1, customer_id=90024, customer_name="Test Customer Pty Ltd"),
            booking(booking_id=2, customer_id=90024, customer_name="Test Customer Pty Ltd"),
            booking(booking_id=3, customer_id=90009, customer_name="Other Test Customer"),
        ])
        by = {x["customer_id"]: x for x in g["groups"]}
        self.assertEqual(len(by[90024]["bookings"]), 2)
        self.assertEqual(len(by[90009]["bookings"]), 1)

    def test_the_biggest_customer_is_first(self):
        small = booking(booking_id=1, customer_id=90009, customer_name="Other Test Customer",
                        ready_calls=[{"call_id": 1, "call_name": "a", "start_date": 0,
                                      "crew_rows": 1, "hours": "1",
                                      "expected_charge": "100.0000"}])
        g = AI.ready_groups([small, booking(booking_id=2)])
        self.assertEqual(g["groups"][0]["customer_id"], 90024)

    def test_a_booking_with_no_customer_still_appears(self):
        g = AI.ready_groups([booking(customer_id=0, customer_name=None)])
        self.assertEqual(len(g["groups"]), 1)
        self.assertEqual(g["groups"][0]["customer_id"], 0)

    def test_n_counts_only_the_bookings_that_are_not_blocked(self):
        """§5.1: the tab's count promises a Generate button, and a blocked
        booking has none."""
        g = AI.ready_groups([
            booking(booking_id=1),
            booking(booking_id=2, stuck_calls=[
                {"call_id": 9, "invoice_id": 1, "call_name": "x", "start_date": 0}]),
        ])
        self.assertEqual(g["ready_bookings"], 1)
        self.assertEqual(g["booking_count"], 2)      # both are still LISTED

    def test_a_blocked_bookings_money_is_not_in_any_total(self):
        """Nobody can invoice it today, so counting it would overstate what
        the buttons on screen would produce."""
        blocked = booking(booking_id=2, stuck_calls=[
            {"call_id": 9, "invoice_id": 1, "call_name": "x", "start_date": 0}])
        g = AI.ready_groups([blocked])
        self.assertEqual(g["totals"]["ex_gst"], 0.0)
        self.assertEqual(g["groups"][0]["totals"]["ex_gst"], 0.0)
        self.assertTrue(g["groups"][0]["bookings"][0]["blocked"])

    def test_the_window_and_the_cap_are_reported_as_given(self):
        g = AI.ready_groups([booking()], truncated=True, window_months=12,
                            all_time=False, total_found=412)
        self.assertTrue(g["truncated"])
        self.assertEqual(g["window_months"], 12)
        self.assertEqual(g["total_found"], 412)
        self.assertFalse(g["all"])

    def test_total_found_defaults_to_what_was_sent(self):
        g = AI.ready_groups([booking()])
        self.assertEqual(g["total_found"], 1)

    def test_an_empty_list_is_empty_not_an_error(self):
        g = AI.ready_groups([])
        self.assertEqual(g["groups"], [])
        self.assertEqual(g["ready_bookings"], 0)
        self.assertEqual(g["totals"]["ex_gst"], 0.0)


# ──────────────────────────────────────────────────────────────────────────────
class Consequence(unittest.TestCase):
    """§5.3 / review R5: the confirm states what pressing it does."""

    def test_it_says_smartstaff_assigns_the_number_now(self):
        c = AI.generate_consequence(booking())
        self.assertTrue(any("assigns its number now" in l for l in c["lines"]))

    def test_it_says_the_xero_export_will_pick_it_up(self):
        c = AI.generate_consequence(booking())
        self.assertTrue(any("Xero export" in l for l in c["lines"]))

    def test_it_says_lines_cannot_be_edited_here_yet(self):
        c = AI.generate_consequence(booking())
        self.assertTrue(any("can't be edited in THE GOAT yet" in l for l in c["lines"]))

    def test_no_warning_when_every_call_is_locked(self):
        self.assertIsNone(AI.generate_consequence(booking())["warning"])

    def test_unlocked_calls_warn_that_a_second_invoice_is_coming(self):
        c = AI.generate_consequence(booking(unlocked_calls=2))
        self.assertIn("2 calls", c["warning"])
        self.assertIn("aren't locked yet", c["warning"])
        self.assertIn("not be on this invoice", c["warning"])
        self.assertIn("second invoice", c["warning"])

    def test_the_warning_is_singular_for_one_call(self):
        c = AI.generate_consequence(booking(unlocked_calls=1))
        self.assertIn("1 call ", c["warning"])
        self.assertIn("isn't locked yet", c["warning"])

    def test_it_carries_the_figures_the_modal_prints(self):
        c = AI.generate_consequence(booking())
        self.assertEqual(c["ready_count"], 2)
        self.assertEqual(c["expected"]["ex_gst"], 10332.00)
        self.assertEqual(c["expected"]["inc_gst"], 11365.20)
        self.assertEqual(c["booking_id"], 90001)


if __name__ == "__main__":
    unittest.main(verbosity=2)

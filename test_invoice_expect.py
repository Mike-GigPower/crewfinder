"""Tests for invoice_expect.py — BRIEF §5.4, plus the §14 additions 11-15.

Run from source, on the Air:

    venv/bin/python3 -m unittest test_invoice_expect -v

unittest rather than pytest, because that is what every other test in this
repo uses and it needs nothing installed. `python3 -m pytest
test_invoice_expect.py` (BRIEF §12 test 1) also works where pytest exists —
it collects unittest TestCases unchanged.

THESE TESTS USE THE LIVE CARD, deliberately. Test 1 prices booking #12220 and
asserts the total to the cent: if the live rates have moved since October it
FAILS, and that is the point of it. Do not loosen it — tell Mike instead.
They skip, rather than fail, on a machine with no rate-card key or no network.
"""

import datetime as dt
from decimal import Decimal, ROUND_HALF_UP
import json
import os
import unittest

import call_types
import invoice_expect as ie
import pricing_rules

HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURE = os.path.join(HERE, "test_fixtures", "booking_12220.json")
BEFORE_RATES_FIXTURE = os.path.join(HERE, "test_fixtures",
                                    "booking_11770_before_rates.json")


def _have(path):
    """test_fixtures/ is GITIGNORED: booking_12220.json is a literal
    get-booking-figures.php response carrying real customer money and real
    SmartStaff user IDs, and this repo is public. So the files exist on the
    build machine and the Air and nowhere else, and every test that reads one
    SKIPS rather than fails when it is absent — the same arrangement
    test_visa_extract.py has with fixtures/.

    A skip says which file is missing, so "skipped" never reads as "passed"
    to someone who meant to have the data."""
    return os.path.exists(path)


def _needs(path):
    return unittest.skipUnless(
        _have(path), "fixture not on this machine: %s" % os.path.relpath(path, HERE))

# Midnight 1 Nov 2026 Melbourne — a SUNDAY, the day booking #12220 sits on.
SUNDAY = 1793451600
MONDAY = SUNDAY + 86400                      # a plain weekday, no penalty
MAY_SUNDAY = int(dt.datetime(2026, 5, 24).timestamp())   # before the cut-over


def _load_rules():
    secret = os.environ.get("RATE_CARDS_SECRET", "")
    if not secret:
        try:
            with open(os.path.join(HERE, "config.json")) as fh:
                secret = str(json.load(fh).get("rate_cards_secret") or "")
        except Exception:
            secret = ""
    pricing_rules.configure(secret, os.path.join(HERE, ".pricing_rules_test_cache.json"))
    return pricing_rules.load_rules()


def _one_call(crew, start_date=SUNDAY, call_id=1, **call_over):
    """A minimal get-booking-figures.php payload: one call, the given rows."""
    call = {"call_id": call_id, "call_name": "Load In", "start_date": start_date,
            "start_time": "06:30:00", "est_length": None, "required": len(crew),
            "call_locked": 1, "invoice_id": 0, "invoice_generated": 0,
            "cancelled_at": None, "cancel_pay": 0}
    call.update(call_over)
    rows = []
    for i, c in enumerate(crew):
        row = {"call_id": call_id, "user_id": 1000 + i, "status": 5,
               "prev_status": 0, "paygrade_id": 10, "on": "08:00:00",
               "off": "16:00:00", "break": "00:00:00", "break_night": "00:00:00",
               "cancel_pay": 0, "cancel_charge": 0, "promo_pending": 0}
        row.update(c)
        rows.append(row)
    return {"booking_id": 9999, "calls": [call], "crew": rows,
            "invoiced": {}, "pay": {},
            "capabilities": {"cancel_pay": True, "promo_ack": True}}


class LiveRules(unittest.TestCase):
    """Base: loads the live card once, and skips the lot if it is unreachable."""

    rules = None

    @classmethod
    def setUpClass(cls):
        rules, meta = _load_rules()
        if not rules:
            raise unittest.SkipTest(
                "live rules unavailable: %s" % (meta or {}).get("error"))
        cls.rules = rules
        cls.standard = pricing_rules.find_set(rules, pricing_rules.STANDARD)

    def crew_rate(self, key, role="Standard Crew"):
        row = [r for r in self.standard["rates"] if r["role_name"] == role][0]
        return float(row[key])

    def price(self, figures, set_code=None):
        return ie.expected_for_booking(
            figures, self.rules, set_code or pricing_rules.STANDARD)


# ──────────────────────────────────────────────────────────────────────────────
# 1. The #12220 fixture, to the cent
# ──────────────────────────────────────────────────────────────────────────────

@_needs(FIXTURE)
class Booking12220(LiveRules):

    @classmethod
    def setUpClass(cls):
        super(Booking12220, cls).setUpClass()
        with open(FIXTURE) as fh:
            cls.fixture = json.load(fh)
        cls.res = ie.expected_for_booking(cls.fixture, cls.rules,
                                          pricing_rules.STANDARD)

    def test_1_total_is_exactly_23302_35(self):
        self.assertEqual(
            self.res["total"], 23302.35,
            "Expected total moved. If the live card changed, say so — do not "
            "loosen this test.")

    def test_1_hours_are_242(self):
        hours = sum(c["hours"] for c in self.res["calls"].values())
        self.assertEqual(round(hours, 2), 242.00)

    def test_1_every_call_within_a_cent_of_the_invoice(self):
        bad = []
        for cid, entry in self.res["calls"].items():
            invoiced = self.fixture["invoiced"][str(cid)]["ex_gst"]
            if entry["expected"] is None or abs(entry["expected"] - invoiced) > 0.01:
                bad.append((cid, entry["expected"], invoiced))
        self.assertEqual(bad, [], "calls more than a cent from the invoice")

    def test_1_every_call_is_ok(self):
        self.assertEqual(self.res["unpriced_calls"], 0)
        for cid, entry in self.res["calls"].items():
            self.assertEqual(entry["status"], "ok", "call %s" % cid)

    def test_1_all_eight_calls_priced_so_no_partial_note(self):
        """A fully priced booking reports 8 of 8 and no reason, so the tab's
        "k of n calls priced" line stays off."""
        self.assertEqual(self.res["priced_calls"], 8)
        self.assertEqual(self.res["total_calls"], 8)
        self.assertEqual(self.res["total_reason"], "")

    def test_1_fixture_is_the_shape_slice_b_returns(self):
        self.assertEqual(len(self.fixture["crew"]), 54)
        self.assertEqual(len(self.fixture["calls"]), 8)

    def test_1_fixture_is_the_real_prod_payload(self):
        """Guards against the reconstruction being put back by accident.

        The rebuilt fixture carried _source / _reconstructed markers, synthetic
        user IDs from 900001, and null est_length / invoiced[].lines. The real
        get-booking-figures.php response has none of those. If this fails, the
        file has been replaced with a stand-in and test 10 below is measuring
        nothing.
        """
        self.assertNotIn("_source", self.fixture)
        self.assertNotIn("_reconstructed", self.fixture)
        self.assertTrue(all(c["user_id"] < 900000 for c in self.fixture["crew"]),
                        "synthetic user IDs — this is the rebuilt fixture")
        self.assertTrue(all(c["est_length"] is not None
                            for c in self.fixture["calls"]))
        # FINDINGS: 17 priced invoice_lines across the booking.
        self.assertEqual(
            sum(v["lines"] for v in self.fixture["invoiced"].values()), 17)

    def test_1_real_breaks_use_the_short_hh_mm_form(self):
        """Prod returns "00:30", not "00:30:00" — _minutes() reads both, and
        this is the fixture that proves the short form is the live one."""
        forms = {c["break"] for c in self.fixture["crew"]}
        forms |= {c["break_night"] for c in self.fixture["crew"]}
        self.assertTrue(all(len(f) == 5 for f in forms), sorted(forms))


# ──────────────────────────────────────────────────────────────────────────────
# 10. Cost for #12220 — read, never derived (§5.5 point 2)
# ──────────────────────────────────────────────────────────────────────────────

@_needs(FIXTURE)
class Cost12220(unittest.TestCase):
    """Cost comes from each row's own accounting.calculated buckets, which
    already carry the PERSON's pay rate. It is never role x card rate: calls
    39358, 39359 and 39363 mix probation grade 38 with promoted grade 39, who
    are paid differently and charged identically.

    No live rules needed — this reads the fixture, which is the point of it.
    """

    # BRIEF §10's pay column. The table is display-rounded per call, so two
    # entries sit a cent off the unrounded floats prod returns (39358 and
    # 39363). The BOOKING total is exact either way, so that is asserted
    # exactly and the per-call figures to the cent — the same shape as test 1.
    S10_PAY = {39356: 347.05, 39357: 1131.12, 39358: 2563.65, 39359: 2364.42,
               39360: 502.72, 39361: 2005.86, 39362: 257.86, 39363: 5308.75}

    @classmethod
    def setUpClass(cls):
        with open(FIXTURE) as fh:
            cls.fixture = json.load(fh)

    def test_10_pay_per_call_matches_the_fixture_table(self):
        bad = []
        for call_id, want in self.S10_PAY.items():
            got = self.fixture["pay"][str(call_id)]["pay"]
            if abs(got - want) > 0.01:
                bad.append((call_id, got, want))
        self.assertEqual(bad, [], "pay per call more than a cent from §10")

    def test_10_pay_total_is_exactly_14481_42(self):
        total = sum(Decimal(repr(v["pay"])) for v in self.fixture["pay"].values())
        self.assertEqual(total.quantize(Decimal("0.01"), ROUND_HALF_UP),
                         Decimal("14481.42"))

    def test_10_charge_equals_invoiced_call_by_call(self):
        """P2's free self-check: Sum(timeTotal x chargeout) from `calculated`
        equals invoice_lines to the cent. If these ever drift apart, one of the
        two readings is wrong and the Cost figure cannot be trusted."""
        for call_id in self.S10_PAY:
            charge = self.fixture["pay"][str(call_id)]["charge"]
            invoiced = self.fixture["invoiced"][str(call_id)]["ex_gst"]
            self.assertLess(abs(charge - invoiced), 0.01, "call %s" % call_id)

    def test_10_every_row_has_calculated_json(self):
        """null_calc > 0 would mean some rows fall back to legacy_pay, and the
        two must never be quietly mixed."""
        self.assertEqual(
            sum(v["null_calc"] for v in self.fixture["pay"].values()), 0)
        self.assertEqual(
            sum(v["rows"] for v in self.fixture["pay"].values()), 54)


# ──────────────────────────────────────────────────────────────────────────────
# 2-4. The pricing mechanics
# ──────────────────────────────────────────────────────────────────────────────

class Mechanics(LiveRules):

    def test_2_break_comes_out_of_the_middle(self):
        res = self.price(_one_call(
            [{"on": "14:00:00", "off": "22:30:00", "break": "00:30:00"}],
            start_date=MONDAY))
        self.assertEqual(res["calls"][1]["hours"], 8.0)

    def test_3_sunday_stops_at_midnight(self):
        """22:00 Sunday -> 02:00 Monday: 2 h Sunday, then 2 h Monday night."""
        res = self.price(_one_call([{"on": "22:00:00", "off": "02:00:00"}]))
        want = 2 * self.crew_rate("sunday_rate") + 2 * self.crew_rate("night_rate")
        self.assertEqual(res["calls"][1]["hours"], 4.0)
        self.assertLess(abs(res["calls"][1]["expected"] - want), 0.01)

    def test_4_overtime_tiers_are_floors_not_replacements(self):
        """11 h on a weekday: 8 h base, 2 h at over8, 1 h at over10, max-of."""
        res = self.price(_one_call([{"on": "08:00:00", "off": "19:00:00"}],
                                   start_date=MONDAY))
        day = self.crew_rate("day_rate")
        want = (8 * day
                + 2 * max(day, self.crew_rate("ot_8_day_rate"))
                + 1 * max(day, self.crew_rate("ot_10_day_rate")))
        self.assertEqual(res["calls"][1]["hours"], 11.0)
        self.assertLess(abs(res["calls"][1]["expected"] - want), 0.01)

    def test_a_null_overtime_tier_is_no_floor_not_zero(self):
        """Every MOPT role has a null ot_8_day_rate. Reading it as 0.0 would
        floor an overtime minute at nothing."""
        self.assertIsNone(ie._num(None))
        self.assertEqual(ie._num(0), 0.0)
        mopt = pricing_rules.find_set(self.rules, pricing_rules.MOPT)
        self.assertTrue(any(r.get("ot_8_day_rate") is None for r in mopt["rates"]),
                        "expected at least one null ot_8_day_rate on MOPT")


# ──────────────────────────────────────────────────────────────────────────────
# 5-6. Rows that cannot be priced (A5, A6)
# ──────────────────────────────────────────────────────────────────────────────

class Unpriceable(LiveRules):

    def test_5_unknown_paygrade_excludes_the_row_not_the_call(self):
        res = self.price(_one_call([{"paygrade_id": 10}, {"paygrade_id": 9999}],
                                   start_date=MONDAY))
        call = res["calls"][1]
        self.assertEqual(call["status"], "partial")
        self.assertEqual(call["excluded_rows"], 1)
        self.assertIsNotNone(call["expected"])
        self.assertTrue(any("9999" in n for n in call["notes"]))

    def test_5b_a_call_with_no_priceable_row_is_unpriced(self):
        res = self.price(_one_call([{"paygrade_id": 9999}], start_date=MONDAY))
        self.assertEqual(res["calls"][1]["status"], "unpriced")
        self.assertIsNone(res["calls"][1]["expected"])
        self.assertEqual(res["unpriced_calls"], 1)

    def test_6_broadcast_row_excluded_and_the_rest_priced(self):
        res = self.price(_one_call([{"paygrade_id": 10}, {"paygrade_id": 34}],
                                   start_date=MONDAY))
        call = res["calls"][1]
        self.assertEqual(call["status"], "partial")
        self.assertEqual(call["excluded_rows"], 1)
        self.assertIsNotNone(call["expected"])
        self.assertTrue(any("broadcast" in n.lower() for n in call["notes"]))

    def test_no_times_is_its_own_status(self):
        res = self.price(_one_call([{"on": "", "off": ""}], start_date=MONDAY))
        self.assertEqual(res["calls"][1]["status"], "no_times")
        self.assertIsNone(res["calls"][1]["expected"])

    def test_unknown_card_prices_nothing(self):
        res = self.price(_one_call([{}]), set_code="no-such-card")
        self.assertIsNone(res["total"])
        self.assertEqual(res["calls"][1]["reason"], "rules unavailable")
        self.assertEqual(res["priced_calls"], 0)
        self.assertEqual(res["total_reason"], "rules unavailable")

    def test_a_call_with_no_times_gives_no_total_either(self):
        """The other way a booking ends up with nothing priced."""
        res = self.price(_one_call([{"on": "", "off": ""}], start_date=MONDAY))
        self.assertIsNone(res["total"])
        self.assertEqual(res["priced_calls"], 0)
        self.assertIn("no times", res["total_reason"])


# ──────────────────────────────────────────────────────────────────────────────
# 13. Cancellations (A7)
# ──────────────────────────────────────────────────────────────────────────────

class Cancellations(LiveRules):

    def test_13_charge_only_cancellation_is_priced(self):
        res = self.price(_one_call(
            [{"status": 9, "prev_status": 5, "cancel_pay": 0, "cancel_charge": 1}],
            start_date=MONDAY))
        self.assertIsNotNone(res["calls"][1]["expected"])
        self.assertEqual(res["calls"][1]["status"], "ok")

    def test_13b_pay_only_cancellation_is_not_charged(self):
        """cancel_pay drives the 'Cancelled - paid' hours row, never Expected."""
        res = self.price(_one_call(
            [{"status": 9, "prev_status": 5, "cancel_pay": 1, "cancel_charge": 0}],
            start_date=MONDAY))
        self.assertIsNone(res["calls"][1]["expected"])


# ──────────────────────────────────────────────────────────────────────────────
# 8, 9, 15. The paygrade map against the live card
# ──────────────────────────────────────────────────────────────────────────────

class PaygradeMap(LiveRules):

    def test_8_every_mapped_role_is_on_the_standard_card(self):
        card = set(call_types.roles_for_set(self.rules, pricing_rules.STANDARD))
        missing = sorted(set(ie.PAYGRADE_ROLE.values()) - card)
        self.assertEqual(missing, [], "roles not on the live standard card")

    def test_15_paygrades_14_and_43_are_steel_hand(self):
        self.assertEqual(ie.PAYGRADE_ROLE[14], "Steel Hand")
        self.assertEqual(ie.PAYGRADE_ROLE[43], "Steel Hand")
        self.assertIn("Steel Hand",
                      call_types.roles_for_set(self.rules, pricing_rules.STANDARD))

    def test_card_items_are_not_mapped_as_roles(self):
        """34 and 36 are card ITEMS. Mapping either to a role would charge an
        event allowance or a harness by the hour."""
        self.assertNotIn(34, ie.PAYGRADE_ROLE)
        self.assertNotIn(36, ie.PAYGRADE_ROLE)

    def test_every_paygrade_in_use_is_covered(self):
        """The 18 grades seen on status-5 rows in the 12 months to 11 Nov 2026
        (§11.5), each either mapped to a role or deliberately a card item."""
        in_use = [10, 12, 14, 20, 24, 25, 26, 33, 34, 35,
                  36, 37, 38, 39, 40, 41, 42, 43]
        handled = set(ie.PAYGRADE_ROLE) | set(ie.PAYGRADE_ITEM) | set(ie.PAYGRADE_EXCLUDED)
        self.assertEqual(sorted(set(in_use) - handled), [])

    def test_9_probation_and_promoted_share_one_line(self):
        """38 and 39 on the same call and times price identically and group
        into one invoice line — the customer is charged by the role."""
        res = self.price(_one_call([
            {"paygrade_id": 38, "on": "06:30:00", "off": "10:30:00"},
            {"paygrade_id": 39, "on": "06:30:00", "off": "10:30:00"}]))
        groups = res["calls"][1]["groups"]
        self.assertEqual(len(groups), 1, "mixed grades must share one line")
        self.assertEqual(groups[0]["qty"], 2)


# ──────────────────────────────────────────────────────────────────────────────
# 11. Harness hire is a card item (A4)
# ──────────────────────────────────────────────────────────────────────────────

class HarnessHire(LiveRules):

    def test_11_harness_row_is_priced_as_one_card_item(self):
        item = [i for i in self.standard["items"] if i["code"] == "harness_hire"][0]
        charge = float(item["charge_ex_gst"])

        base = self.price(_one_call([{"paygrade_id": 10}], start_date=MONDAY))
        both = self.price(_one_call([{"paygrade_id": 10}, {"paygrade_id": 36}],
                                    start_date=MONDAY))

        self.assertLess(
            abs(both["calls"][1]["expected"]
                - (base["calls"][1]["expected"] + charge)), 0.01)
        self.assertTrue(any("harness" in n.lower()
                            for n in both["calls"][1]["notes"]))
        # PRICED, not excluded.
        self.assertEqual(both["calls"][1]["status"], "ok")

    def test_11b_harness_is_not_charged_by_the_hour(self):
        """Two harness rows on different shift lengths cost the same."""
        short = self.price(_one_call(
            [{"paygrade_id": 36, "on": "08:00:00", "off": "12:00:00"}],
            start_date=MONDAY))
        long_ = self.price(_one_call(
            [{"paygrade_id": 36, "on": "08:00:00", "off": "18:00:00"}],
            start_date=MONDAY))
        self.assertEqual(short["calls"][1]["expected"],
                         long_["calls"][1]["expected"])


# ──────────────────────────────────────────────────────────────────────────────
# 14. Promo-pending (A2)
# ──────────────────────────────────────────────────────────────────────────────

class PromoPending(LiveRules):

    def test_14_promo_pending_row_is_still_priced(self):
        """A2: the tab leaves them out of SCHEDULED hours, but they are
        confirmed rows and Expected still prices them."""
        res = self.price(_one_call([{"promo_pending": 1}], start_date=MONDAY))
        self.assertIsNotNone(res["calls"][1]["expected"])
        self.assertEqual(res["calls"][1]["status"], "ok")


# ──────────────────────────────────────────────────────────────────────────────
# Blank breaks, has_pay, and the rate cut-over
# ──────────────────────────────────────────────────────────────────────────────

class BlankBreaks(LiveRules):

    def test_minutes_reads_a_blank_as_zero(self):
        self.assertEqual(ie._minutes(""), 0)
        self.assertEqual(ie._minutes(None), 0)
        self.assertEqual(ie._minutes("   "), 0)
        self.assertEqual(ie._minutes("00:30"), 30)
        self.assertEqual(ie._minutes("00:30:00"), 30)

    def test_blank_break_prices_the_same_as_zero(self):
        """Booking 11763 returns "" for a break that was never keyed."""
        blank = self.price(_one_call(
            [{"on": "14:00:00", "off": "22:00:00", "break": "", "break_night": ""}],
            start_date=MONDAY))
        zero = self.price(_one_call(
            [{"on": "14:00:00", "off": "22:00:00", "break": "00:00:00",
              "break_night": "00:00:00"}], start_date=MONDAY))
        self.assertEqual(blank["calls"][1]["hours"], 8.0)
        self.assertEqual(blank["calls"][1]["expected"],
                         zero["calls"][1]["expected"])


class HasPay(unittest.TestCase):
    """No live rules needed: this is a reading rule, not a pricing one."""

    FIGURES = {
        "calls": [{"call_id": 37675, "call_locked": 0},
                  {"call_id": 37679, "call_locked": 1}],
        "crew": [],
        "pay": {"37675": {"rows": 4, "pay": 812.5},
                "37679": {"rows": 0, "pay": 0.0}},
        "invoiced": {},
    }

    def test_pay_is_read_from_the_pay_map_not_call_locked(self):
        """37675/37679/37683 on booking 11763 are UNLOCKED and have pay."""
        self.assertTrue(ie.has_pay(self.FIGURES, 37675))    # unlocked, paid
        self.assertFalse(ie.has_pay(self.FIGURES, 37679))   # locked, no rows
        self.assertFalse(ie.has_pay(self.FIGURES, 99999))   # not in the map


class RateCutover(LiveRules):

    def test_cutover_is_read_from_the_rules_where_they_say(self):
        """Every MOPT row carries 2026-07-01, so the cut-over comes off the
        card. The standard card carries only the 2000-01-01 sentinel, so it
        falls back to the constant — which is the same date."""
        self.assertEqual(ie.rates_from(self.rules, pricing_rules.MOPT),
                         "2026-07-01")
        self.assertEqual(ie.rates_from(self.rules, pricing_rules.STANDARD),
                         ie.CURRENT_RATES_FROM)
        self.assertEqual(ie.CURRENT_RATES_FROM, "2026-07-01")

    def test_a_call_before_the_cutover_is_not_priced(self):
        """Booking 11763, Sunday 24 May 2026, was charged 93.60/h against
        today's 99.95. Pricing it on today's card would invent a difference."""
        res = self.price(_one_call([{"on": "08:00:00", "off": "16:00:00"}],
                                   start_date=MAY_SUNDAY))
        call = res["calls"][1]
        self.assertEqual(call["status"], "before_rates")
        self.assertIsNone(call["expected"])
        self.assertIn("before current rates", call["reason"])
        self.assertEqual(call["hours"], 8.0)   # the hours are still real

    @_needs(BEFORE_RATES_FIXTURE)
    def test_a_booking_with_nothing_priced_has_NO_total(self):
        """#11770 and #11760: every call predates the current card.

        The money block read "Expected invoice $0.00" while every call row
        correctly showed "—". A booking where nothing was priced has no total;
        it does not have a total of zero, and 0.0 here is a number someone
        will act on.
        """
        with open(BEFORE_RATES_FIXTURE) as fh:
            fixture = json.load(fh)
        res = self.price(fixture)

        self.assertEqual(res["priced_calls"], 0)
        self.assertEqual(res["total_calls"], 1)
        self.assertIsNone(res["total"], "a booking with nothing priced has no total")
        self.assertIn("before current rates", res["total_reason"])
        for cid, entry in res["calls"].items():
            self.assertEqual(entry["status"], "before_rates", "call %s" % cid)
            self.assertIsNone(entry["expected"])
        # The hours are still real and still shown — only the money is withheld.
        self.assertGreater(sum(c["hours"] for c in res["calls"].values()), 0)

    def test_a_partly_priced_booking_reports_k_of_n(self):
        """One call before the cut-over, one after: a real total, and the tab
        is told it covers 1 of 2 calls so it can say so rather than imply the
        figure is the whole booking."""
        fig = _one_call([{"on": "08:00:00", "off": "16:00:00"}], start_date=MONDAY)
        old = _one_call([{"on": "08:00:00", "off": "16:00:00"}],
                        start_date=MAY_SUNDAY, call_id=2)
        fig["calls"] += old["calls"]
        fig["crew"] += old["crew"]

        res = self.price(fig)
        self.assertEqual(res["priced_calls"], 1)
        self.assertEqual(res["total_calls"], 2)
        self.assertIsNotNone(res["total"])
        self.assertGreater(res["total"], 0)
        self.assertEqual(res["calls"][1]["status"], "ok")
        self.assertEqual(res["calls"][2]["status"], "before_rates")

    def test_a_fully_priced_booking_reports_all_of_them(self, ):
        """The ordinary case still says n of n, so the tab's "k of n" line
        only appears when something really is missing."""
        res = self.price(_one_call([{"on": "08:00:00", "off": "16:00:00"}],
                                   start_date=MONDAY))
        self.assertEqual(res["priced_calls"], 1)
        self.assertEqual(res["total_calls"], 1)
        self.assertEqual(res["total_reason"], "")

    def test_the_cutover_date_itself_is_priced(self):
        ts = int(dt.datetime(2026, 7, 1, 12, 0).timestamp())
        res = self.price(_one_call([{"on": "08:00:00", "off": "16:00:00"}],
                                   start_date=ts))
        self.assertEqual(res["calls"][1]["status"], "ok")
        self.assertIsNotNone(res["calls"][1]["expected"])


if __name__ == "__main__":
    unittest.main()

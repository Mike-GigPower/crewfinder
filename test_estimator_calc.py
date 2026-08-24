"""
test_estimator_calc.py — the acceptance gate for the Estimator port.

    python3 test_estimator_calc.py

No pytest: this repo's venv does not carry it, and a module that has to be
runnable on the build machine and on cPanel should not need one.

The splitter cases come from estimator_split_fixtures.json, the SAME table
smartstaff/test-perf-split.php runs against the PHP implementation. That shared
table is what stops the two from drifting; a rule saying they must not would
not have.
"""

import json
import os
import unittest

from datetime import datetime, timedelta

import estimator_calc as ec


HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURES = os.path.join(HERE, "estimator_split_fixtures.json")


# The LIVE rate card, read from Supabase rate_cards on 24 Aug 2026. Deliberately
# not config.ts's defaultConfig, which still carries Standard Crew at
# 60.10/76.20/93.60 — a pre-load placeholder the app overwrites, and the exact
# thing a port must never quietly price against.
LIVE_RATES = [
    {"role": "Standard Crew", "day": 63.00, "night": 79.90, "sunday": 99.95,
     "publicHoliday": 99.95, "over8": 79.90, "over10": 99.95,
     "effectiveFrom": "2000-01-01"},
    {"role": "Show Crew", "day": 84.50, "night": 84.50, "sunday": 98.10,
     "publicHoliday": 98.10, "over8": 84.50, "over10": 84.50,
     "effectiveFrom": "2000-01-01"},
    {"role": "Crew Boss", "day": 89.95, "night": 105.30, "sunday": 109.40,
     "publicHoliday": 109.40, "over8": 105.30, "over10": 109.40,
     "effectiveFrom": "2000-01-01"},
]

# app_settings.value, same read.
LIVE_CFG = {
    "currency": "AUD",
    "gstRate": 0.1,
    "minBillableHours": 4,
    "dayStart": "08:00",
    "nightStart": "20:00",
    "rates": LIVE_RATES,
    "publicHolidays": [
        {"date": "2026-11-03", "label": "Melbourne Cup Day"},
        {"date": "2026-12-25", "label": "Christmas Day"},
    ],
}


def cfg(**overrides):
    c = dict(LIVE_CFG)
    c.update(overrides)
    return c


def line(**overrides):
    ln = {
        "id": "L1",
        "role": "Standard Crew",
        "qty": 1,
        "shiftDate": "2026-06-20",
        "startTime": "16:00",
        "durationHours": 12,
    }
    ln.update(overrides)
    return ln


def seg_tuple(s):
    return (s["tier"], s["period"], s["dateISO"], s["hours"], s["rate"])


# ──────────────────────────────────────────────────────────────────────────────

class SplitterFixtureTests(unittest.TestCase):
    """The shared table. Every case, both encodings."""

    @classmethod
    def setUpClass(cls):
        with open(FIXTURES) as fh:
            cls.cases = json.load(fh)["cases"]

    def test_every_fixture_case(self):
        ran = 0

        for case in self.cases:
            name = case["name"]
            start = datetime.strptime(case["start"], "%Y-%m-%d %H:%M")
            end = datetime.strptime(case["end"], "%Y-%m-%d %H:%M")
            start_date = datetime.strptime(case["startDate"], "%Y-%m-%d")

            c = cfg(dayStart=_hhmm(case["dayStartSec"]),
                    nightStart=_hhmm(case["nightStartSec"]))

            got = ec.split_day_night(start, end, c, case["mode"])

            expected = [
                {
                    "dateISO": (start_date + timedelta(days=row[0])).strftime("%Y-%m-%d"),
                    "dayHrs": row[1],
                    "nightHrs": row[2],
                }
                for row in case["expected"]
            ]

            self.assertEqual(len(got), len(expected), "%s: segment count" % name)

            for i, (g, e) in enumerate(zip(got, expected)):
                self.assertEqual(g["dateISO"], e["dateISO"], "%s: seg %d date" % (name, i))
                self.assertAlmostEqual(g["dayHrs"], e["dayHrs"], places=9,
                                       msg="%s: seg %d day hours" % (name, i))
                self.assertAlmostEqual(g["nightHrs"], e["nightHrs"], places=9,
                                       msg="%s: seg %d night hours" % (name, i))

            ran += 1

        self.assertEqual(ran, len(self.cases))

    def test_seconds_encoding_matches_the_datetime_encoding(self):
        """
        The PHP harness reads startSec/endSec; Python reads start/end. If the
        two ever describe different spans the shared table stops proving
        anything, so assert they agree wherever both are given.
        """
        checked = 0

        for case in self.cases:
            if case.get("startSec") is None:
                self.assertEqual(case["mode"], "duration",
                                 "%s: only duration cases may omit seconds" % case["name"])
                continue

            start_date = datetime.strptime(case["startDate"], "%Y-%m-%d")
            start = datetime.strptime(case["start"], "%Y-%m-%d %H:%M")
            end = datetime.strptime(case["end"], "%Y-%m-%d %H:%M")

            self.assertEqual((start - start_date).total_seconds(), case["startSec"],
                             "%s: startSec" % case["name"])
            self.assertEqual((end - start_date).total_seconds(), case["endSec"],
                             "%s: endSec" % case["name"])
            checked += 1

        self.assertTrue(checked >= 10, "expected the PHP-runnable subset to be substantial")


def _hhmm(seconds):
    self_check = seconds % 60
    assert self_check == 0, "boundary seconds must be a whole minute"
    return "%02d:%02d" % (seconds // 3600, (seconds % 3600) // 60)


# ──────────────────────────────────────────────────────────────────────────────

class AcceptanceTests(unittest.TestCase):
    """BRIEF §9 — the number the port exists to reproduce."""

    EXPECTED_SEGMENTS = [
        ("base", "day",   "2026-06-20", 4.0, 63.00),
        ("base", "night", "2026-06-20", 4.0, 79.90),
        ("ot8",  "night", "2026-06-21", 2.0, 99.95),
        ("ot10", "night", "2026-06-21", 2.0, 99.95),
    ]

    def _assert_s9(self, mode):
        r = ec.calc_line(line(), LIVE_CFG, mode)

        self.assertTrue(r["ok"], r["errors"])
        self.assertEqual(r["errors"], [])
        self.assertEqual(r["billableHours"], 12)
        self.assertEqual(r["costExGst"], 971.40)
        self.assertEqual([seg_tuple(s) for s in r["segments"]], self.EXPECTED_SEGMENTS)

        self.assertEqual(r["breakdown"], {
            "baseDayHrs": 4.0, "baseNightHrs": 4.0,
            "ot8DayHrs": 0.0, "ot8NightHrs": 2.0,
            "ot10DayHrs": 0.0, "ot10NightHrs": 2.0,
        })

    def test_section_9_duration_mode(self):
        self._assert_s9(ec.MODE_DURATION)

    def test_section_9_wallclock_mode(self):
        """June is nowhere near a transition, so both clocks must agree here."""
        self._assert_s9(ec.MODE_WALLCLOCK)


# ──────────────────────────────────────────────────────────────────────────────

class ModeTests(unittest.TestCase):
    """Decision 1 — two clocks, chosen explicitly."""

    OCT = line(shiftDate="2026-10-03", startTime="22:00", durationHours=11)
    APR = line(shiftDate="2026-04-04", startTime="22:00", durationHours=11)

    def test_mode_has_no_default(self):
        with self.assertRaises(TypeError):
            ec.calc_line(line(), LIVE_CFG)          # missing positional

    def test_unknown_mode_raises(self):
        for bad in ("local", "", None, "DURATION"):
            with self.assertRaises(ValueError):
                ec.calc_line(line(), LIVE_CFG, bad)

    def test_split_day_night_rejects_a_missing_mode(self):
        with self.assertRaises(ValueError):
            ec.split_day_night(datetime(2026, 6, 20, 16, 0),
                               datetime(2026, 6, 20, 20, 0), LIVE_CFG, "naive")

    def test_october_transition_moves_an_hour_across_the_day_boundary(self):
        """
        22:00 Sat 3 Oct + 11h. Absolute arithmetic lands at 10:00 Sunday, wall
        clock at 09:00, so the two disagree about which hour is day and which
        is night — the divergence the mode argument exists for.
        """
        dur = ec.calc_line(self.OCT, LIVE_CFG, ec.MODE_DURATION)
        wall = ec.calc_line(self.OCT, LIVE_CFG, ec.MODE_WALLCLOCK)

        self.assertEqual(dur["breakdown"], {
            "baseDayHrs": 0.0, "baseNightHrs": 8.0,
            "ot8DayHrs": 1.0, "ot8NightHrs": 1.0,      # 07:00-08:00 n, 08:00-09:00 d
            "ot10DayHrs": 1.0, "ot10NightHrs": 0.0,    # 09:00-10:00
        })

        self.assertEqual(wall["breakdown"], {
            "baseDayHrs": 0.0, "baseNightHrs": 8.0,
            "ot8DayHrs": 0.0, "ot8NightHrs": 2.0,      # 06:00-08:00
            "ot10DayHrs": 1.0, "ot10NightHrs": 0.0,    # 08:00-09:00
        })

        # Both bill eleven hours. They disagree about WHICH hours, not how many.
        for r in (dur, wall):
            self.assertAlmostEqual(sum(r["breakdown"].values()), 11.0, places=9)

    def test_april_transition_moves_the_hour_the_other_way(self):
        """22:00 Sat 4 Apr + 11h: absolute lands at 08:00, wall clock at 09:00."""
        dur = ec.calc_line(self.APR, LIVE_CFG, ec.MODE_DURATION)
        wall = ec.calc_line(self.APR, LIVE_CFG, ec.MODE_WALLCLOCK)

        # Absolute: the shift ends exactly at dayStart, so none of it is day.
        self.assertEqual(dur["breakdown"], {
            "baseDayHrs": 0.0, "baseNightHrs": 8.0,
            "ot8DayHrs": 0.0, "ot8NightHrs": 2.0,
            "ot10DayHrs": 0.0, "ot10NightHrs": 1.0,
        })

        # Wall clock: it runs an hour past dayStart, and that hour is day.
        self.assertEqual(wall["breakdown"]["ot10DayHrs"], 1.0)
        self.assertEqual(wall["breakdown"]["ot10NightHrs"], 0.0)

    def test_on_the_live_card_the_mode_moves_hours_but_not_money(self):
        """
        BRIEF §1.1 SAYS THE DIVERGENT HOUR IS WORTH 63.00 AGAINST 79.90, "and
        more where a Sunday penalty is also in play". Against the live card it
        is worth NOTHING, and the Sunday penalty is the reason.

        Every Australian DST transition is a Sunday, at 2am/3am — so every hour
        the two modes label differently falls on a Sunday. Every role on the
        live card has sunday >= day, night, over8 and over10, and every rate is
        a max-of, so all of those hours price at the Sunday rate under EITHER
        label. Billable hours are fixed by durationHours, so the totals match to
        the cent.

        Decision 1 still stands — the hours series diverges, the breakdown and
        the segment table diverge, and the next test shows the money diverges
        too the moment a rate card stops having sunday on top. But the forward
        PRICING exposure on today's card is zero, which is worth knowing before
        anyone treats this as urgent.
        """
        hours_differed = False

        for role in ("Standard Crew", "Show Crew", "Crew Boss"):
            for shift_date in ("2026-10-03", "2026-10-04", "2026-04-04", "2026-04-05"):
                for hh in range(0, 24, 2):
                    for duration in (4, 8, 11, 14):
                        ln = line(role=role, shiftDate=shift_date,
                                  startTime="%02d:00" % hh, durationHours=duration)

                        dur = ec.calc_line(ln, LIVE_CFG, ec.MODE_DURATION)
                        wall = ec.calc_line(ln, LIVE_CFG, ec.MODE_WALLCLOCK)

                        self.assertEqual(
                            dur["costExGst"], wall["costExGst"],
                            "%s %s %02d:00 %sh" % (role, shift_date, hh, duration))

                        if dur["breakdown"] != wall["breakdown"]:
                            hours_differed = True

        self.assertTrue(hours_differed,
                        "the sweep must actually exercise the divergence")

    def test_money_does_diverge_once_sunday_stops_dominating(self):
        """A card whose night rate beats its Sunday rate — then the mode pays."""
        odd = cfg(rates=[{"role": "Odd Card", "day": 50.0, "night": 120.0,
                          "sunday": 60.0, "publicHoliday": 60.0,
                          "over8": 120.0, "over10": 120.0}])

        # Starting at midnight ON the transition day puts the 08:00 boundary
        # inside the BASE tier, where day and night still differ. Inside an
        # overtime tier they would not: ot8 is max(over8, ...), and over8 here
        # is 120.00, which swallows the difference.
        ln = line(role="Odd Card", shiftDate="2026-10-04",
                  startTime="00:00", durationHours=11)

        dur = ec.calc_line(ln, odd, ec.MODE_DURATION)
        wall = ec.calc_line(ln, odd, ec.MODE_WALLCLOCK)

        # Absolute time reaches 08:00 an hour early, so an hour of base moves
        # from night (120.00) to Sunday-day (60.00).
        self.assertEqual(dur["breakdown"]["baseNightHrs"], 7.0)
        self.assertEqual(dur["breakdown"]["baseDayHrs"], 1.0)
        self.assertEqual(wall["breakdown"]["baseNightHrs"], 8.0)
        self.assertEqual(wall["breakdown"]["baseDayHrs"], 0.0)

        self.assertEqual(round(wall["costExGst"] - dur["costExGst"], 2), 60.00)


class BoundaryTests(unittest.TestCase):

    def test_start_exactly_at_day_start(self):
        r = ec.calc_line(line(startTime="08:00", durationHours=8),
                         LIVE_CFG, ec.MODE_DURATION)
        self.assertEqual(r["breakdown"]["baseDayHrs"], 8.0)
        self.assertEqual(r["breakdown"]["baseNightHrs"], 0.0)
        self.assertEqual(len(r["segments"]), 1)

    def test_start_exactly_at_night_start(self):
        r = ec.calc_line(line(startTime="20:00", durationHours=8),
                         LIVE_CFG, ec.MODE_DURATION)
        self.assertEqual(r["breakdown"]["baseNightHrs"], 8.0)
        self.assertEqual(r["breakdown"]["baseDayHrs"], 0.0)
        # 20:00 -> midnight, midnight -> 04:00: one cut, two segments.
        self.assertEqual(len(r["segments"]), 2)

    def test_guard_raises_rather_than_truncating(self):
        with self.assertRaises(ec.EstimatorError):
            ec.calc_line(line(durationHours=24 * 40), LIVE_CFG, ec.MODE_DURATION)


class MinimumBillableTests(unittest.TestCase):

    def test_below_minimum_bills_the_minimum_and_still_prices(self):
        r = ec.calc_line(line(startTime="09:00", durationHours=2),
                         LIVE_CFG, ec.MODE_DURATION)

        self.assertTrue(r["ok"])
        self.assertEqual(r["errors"], [])                  # calc.ts parity
        self.assertEqual(r["billableHours"], 4)
        self.assertEqual(r["costExGst"], 252.00)           # 4h x 63.00
        self.assertEqual(
            r["advisories"],
            ["Duration is below minimum (4h) for line L1. (Will bill minimum)"]
        )

    def test_minimum_comes_from_config_not_from_a_constant(self):
        r = ec.calc_line(line(startTime="09:00", durationHours=2),
                         cfg(minBillableHours=6), ec.MODE_DURATION)
        self.assertEqual(r["billableHours"], 6)
        self.assertIn("below minimum (6h)", r["advisories"][0])


class PenaltyTests(unittest.TestCase):

    def test_sunday_crossing_into_monday(self):
        """The penalty follows the DATE of each segment, not the shift's date."""
        r = ec.calc_line(line(shiftDate="2026-06-21", startTime="20:00",
                              durationHours=8),
                         LIVE_CFG, ec.MODE_DURATION)

        self.assertEqual([seg_tuple(s) for s in r["segments"]], [
            ("base", "night", "2026-06-21", 4.0, 99.95),   # Sunday penalty
            ("base", "night", "2026-06-22", 4.0, 79.90),   # Monday night
        ])
        self.assertEqual(r["costExGst"], round(4 * 99.95 + 4 * 79.90, 2))

    def test_public_holiday_beats_the_day_rate(self):
        r = ec.calc_line(line(shiftDate="2026-11-03", startTime="09:00",
                              durationHours=8),
                         LIVE_CFG, ec.MODE_DURATION)
        self.assertEqual(r["segments"][0]["rate"], 99.95)
        self.assertEqual(r["costExGst"], 799.60)

    def test_public_holidays_come_from_config(self):
        """An empty holiday list must price the same day at the day rate."""
        r = ec.calc_line(line(shiftDate="2026-11-03", startTime="09:00",
                              durationHours=8),
                         cfg(publicHolidays=[]), ec.MODE_DURATION)
        self.assertEqual(r["segments"][0]["rate"], 63.00)

    def test_overtime_is_max_of_never_a_sum(self):
        """ot8 on a Sunday is the highest single rate, not night plus penalty."""
        r = ec.calc_line(line(shiftDate="2026-06-21", startTime="09:00",
                              durationHours=11),
                         LIVE_CFG, ec.MODE_DURATION)
        rates = set(s["rate"] for s in r["segments"])
        self.assertEqual(rates, set([99.95]))              # Sunday dominates all tiers


class ValidationTests(unittest.TestCase):

    def test_unmapped_role_is_unpriced(self):
        r = ec.calc_line(line(role="GC Spot"), LIVE_CFG, ec.MODE_DURATION)
        self.assertFalse(r["ok"])
        self.assertEqual(r["errors"], ["Role is invalid for line L1."])
        self.assertEqual(r["costExGst"], 0.0)
        self.assertEqual(r["segments"], [])

    def test_blank_start_time(self):
        for blank in ("", "   ", None):
            r = ec.calc_line(line(startTime=blank), LIVE_CFG, ec.MODE_DURATION)
            self.assertFalse(r["ok"])
            self.assertIn("Start time must be HH:MM for line L1.", r["errors"])

    def test_bad_qty_and_bad_date(self):
        r = ec.calc_line(line(qty=0, shiftDate="20/06/2026"),
                         LIVE_CFG, ec.MODE_DURATION)
        self.assertFalse(r["ok"])
        self.assertIn("Crew qty must be > 0 for line L1.", r["errors"])
        self.assertIn("Shift date is invalid for line L1.", r["errors"])


class ParseTimeTests(unittest.TestCase):
    """calc.ts parseTimeHHMM, quirks included."""

    def test_decimal_fraction_of_a_day(self):
        self.assertEqual(ec.parse_time_hhmm("0.5"), (12, 0))
        self.assertEqual(ec.parse_time_hhmm("0.75"), (18, 0))

    def test_a_decimal_start_time_actually_prices(self):
        r = ec.calc_line(line(startTime="0.5", durationHours=8),
                         LIVE_CFG, ec.MODE_DURATION)
        self.assertTrue(r["ok"])
        self.assertEqual(r["breakdown"]["baseDayHrs"], 8.0)   # noon to 20:00

    def test_bare_integer_is_midnight_not_that_hour(self):
        """Number("8") % 1 === 0, so "8" is 00:00. Surprising, and reproduced."""
        self.assertEqual(ec.parse_time_hhmm("8"), (0, 0))

    def test_single_digit_hour_is_rejected(self):
        """The regex wants a two-digit hour and Number("8:00") is NaN."""
        self.assertIsNone(ec.parse_time_hhmm("8:00"))

    def test_wellformed_and_malformed(self):
        self.assertEqual(ec.parse_time_hhmm("00:00"), (0, 0))
        self.assertEqual(ec.parse_time_hhmm("23:59"), (23, 59))
        self.assertEqual(ec.parse_time_hhmm(" 16:30 "), (16, 30))
        self.assertEqual(ec.parse_time_hhmm("16:30:45"), (16, 30))
        self.assertIsNone(ec.parse_time_hhmm("24:00"))
        self.assertIsNone(ec.parse_time_hhmm("16:60"))
        self.assertIsNone(ec.parse_time_hhmm(""))
        self.assertIsNone(ec.parse_time_hhmm("   "))
        self.assertIsNone(ec.parse_time_hhmm("noon"))

    def test_python_only_numeric_forms_are_not_accepted(self):
        """float() takes these; Number() does not, so neither do we."""
        self.assertIsNone(ec.parse_time_hhmm("0_5"))
        self.assertIsNone(ec.parse_time_hhmm("nan"))
        self.assertIsNone(ec.parse_time_hhmm("infinity"))


class RoundingTests(unittest.TestCase):

    def test_round2_matches_js_not_bankers(self):
        self.assertEqual(ec.round2(2.675), 2.68)           # round(2.675, 2) is 2.67
        self.assertEqual(ec.round2(1.005), 1.01)
        self.assertEqual(ec.round2(0.125), 0.13)

    def test_qty_is_applied_before_the_line_rounds(self):
        """
        round2(qty * cost), never qty * round2(cost). A rate chosen so the two
        differ: 4h at 0.1666667 is 0.6666668 per crew; three crew is 2.0000004,
        which rounds to 2.00. Rounding first would give 3 x 0.67 = 2.01.
        """
        c = cfg(rates=[{"role": "Cent Test", "day": 0.1666667, "night": 0.1666667,
                        "sunday": 0.1666667, "publicHoliday": 0.1666667,
                        "over8": 0.1666667, "over10": 0.1666667}])
        r = ec.calc_line(line(role="Cent Test", startTime="09:00",
                              durationHours=4, qty=3),
                         c, ec.MODE_DURATION)

        self.assertEqual(r["costExGst"], 2.00)

        # And the segments round independently, so they do NOT reconcile to the
        # cent. calc.ts does this too; the port reproduces it rather than
        # quietly disagreeing with a quote the customer is holding.
        seg_total = sum(s["costExGst"] for s in r["segments"]) * 3
        self.assertEqual(ec.round2(seg_total), 2.01)


class RateVersionTests(unittest.TestCase):

    VERSIONED = [
        dict(LIVE_RATES[0]),
        {"role": "Standard Crew", "day": 70.00, "night": 90.00, "sunday": 110.00,
         "publicHoliday": 110.00, "over8": 90.00, "over10": 110.00,
         "effectiveFrom": "2026-07-01"},
    ]

    def test_picks_the_version_in_force_on_the_shift_date(self):
        c = cfg(rates=self.VERSIONED)
        self.assertEqual(ec.get_rate_row("Standard Crew", "2026-06-30", c)["day"], 63.00)
        self.assertEqual(ec.get_rate_row("Standard Crew", "2026-07-01", c)["day"], 70.00)
        self.assertEqual(ec.get_rate_row("Standard Crew", "2027-01-01", c)["day"], 70.00)

    def test_a_shift_before_every_version_falls_back_to_the_earliest(self):
        c = cfg(rates=[self.VERSIONED[1]])
        self.assertEqual(ec.get_rate_row("Standard Crew", "1999-01-01", c)["day"], 70.00)

    def test_unknown_role(self):
        self.assertIsNone(ec.get_rate_row("Traffic", "2026-06-20", LIVE_CFG))

    def test_a_caller_supplied_rate_row_wins(self):
        """Release 2's learned ladder prices against a rate it resolved itself."""
        learned = {"role": "Standard Crew", "day": 100.0, "night": 100.0,
                   "sunday": 100.0, "publicHoliday": 100.0,
                   "over8": 100.0, "over10": 100.0}
        r = ec.calc_line(line(startTime="09:00", durationHours=4),
                         LIVE_CFG, ec.MODE_DURATION, rate_row=learned)
        self.assertEqual(r["costExGst"], 400.00)

    def test_string_rates_from_the_rest_layer_are_coerced(self):
        """Supabase REST hands numerics back as strings; the JS client does not."""
        c = cfg(rates=[{"role": "Standard Crew", "day": "63.00", "night": "79.90",
                        "sunday": "99.95", "publicHoliday": "99.95",
                        "over8": "79.90", "over10": "99.95",
                        "effectiveFrom": "2000-01-01"}])
        self.assertEqual(ec.calc_line(line(), c, ec.MODE_DURATION)["costExGst"], 971.40)


class CallNameTests(unittest.TestCase):
    """Decision 4 — an unmapped call name is unpriced, never Standard Crew."""

    def test_mapped_names(self):
        self.assertEqual(ec.role_for_call_name("Load In"), "Standard Crew")
        self.assertEqual(ec.role_for_call_name("Show Call"), "Show Crew")
        self.assertEqual(ec.role_for_call_name("Wardrobe"), "Seamstress")

    def test_the_two_names_production_actually_carries(self):
        self.assertIsNone(ec.role_for_call_name("GC Spot"))
        self.assertIsNone(ec.role_for_call_name("Traffic"))

    def test_blank(self):
        self.assertIsNone(ec.role_for_call_name(""))
        self.assertIsNone(ec.role_for_call_name(None))

    def test_the_map_is_still_the_twenty_from_types_ts(self):
        self.assertEqual(len(ec.CALL_NAME_TO_ROLE), 20)


class QuoteTotalTests(unittest.TestCase):

    def test_totals_and_gst(self):
        quote = {
            "labour": [line()],
            "nonLabour": [{"id": "N1", "title": "Truck hire", "description": "",
                           "qty": 2, "amountExGst": 150.0}],
        }
        r = ec.calc_quote_totals(quote, LIVE_CFG, ec.MODE_DURATION)

        self.assertTrue(r["isValid"])
        self.assertEqual(r["totals"]["labourExGst"], 971.40)
        self.assertEqual(r["totals"]["nonLabourExGst"], 300.00)
        self.assertEqual(r["totals"]["subTotalExGst"], 1271.40)
        self.assertEqual(r["totals"]["gst"], 127.14)
        self.assertEqual(r["totals"]["grandTotalIncGst"], 1398.54)
        self.assertEqual(r["nonLabourLines"][0]["description"], "Non-labour item")

    def test_an_invalid_line_makes_the_quote_invalid(self):
        quote = {"labour": [line(), line(id="L2", role="Traffic")], "nonLabour": []}
        r = ec.calc_quote_totals(quote, LIVE_CFG, ec.MODE_DURATION)
        self.assertFalse(r["isValid"])
        self.assertEqual(r["validationErrors"], ["Role is invalid for line L2."])


if __name__ == "__main__":
    unittest.main(verbosity=2)

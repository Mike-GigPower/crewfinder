"""
Tests for derivation.py — Phase 2a slice 3.

No database, no fixtures, no network. Every case is built FROM THE RULES, not
from a spreadsheet.

WHY THE LAUFEY WORKBOOK IS NOT AN ORACLE HERE. It is the closest thing to real
data, and it is still wrong in two ways for this purpose. Its CEILING disagrees
with the grace rule at both ends (CORRECTION-2 §3), so it is simply incorrect
there. And, confirmed 26 Aug 2026, historic break values were HAND-ADJUSTED at
payroll time: call_crew_map.break holds a corrected number while whatever was
originally typed is gone. A row that looks like clean data may be a manual fix.
The workbook is used for realistic SHAPES — overnight finishes, two breaks, a
22-crew call — never for expected outputs.

Run:  python3 test_derivation.py
"""

import unittest

from datetime import datetime, timedelta

from derivation import (
    DerivationError,
    MAX_ROLL_GAP_MINS,
    clock_off,
    clock_on,
    continuous_block,
    derive_times,
    is_continuous,
    minimum_top_up,
    round_to_quarter,
)

CFG = {"dayStart": "08:00", "nightStart": "20:00"}

DAY = "2026-06-10"          # an ordinary Wednesday, no DST anywhere near it
OCT_DST = "2026-10-04"      # clock jumps 02:00 -> 03:00; the day is 23 hours
APR_DST = "2026-04-05"      # 02:00 -> 03:00 happens twice; the day is 25 hours


def call(cid, start="11:00", hours=4.0, day=DAY):
    return {"id": cid, "start_date": day, "start_time": start, "est_length": hours}


def sub(on, off, next_day=False):
    return {"on_time": on, "off_time": off, "off_next_day": 1 if next_day else 0}


def brk(start, mins, next_day=False):
    return {"start_time": start, "duration_mins": mins,
            "start_next_day": 1 if next_day else 0}


def locked(a, b):
    return [{"source_call": a, "target_call": b, "mode": "locked"}]


def recommended(a, b):
    return [{"source_call": a, "target_call": b, "mode": "recommended"}]


class TestRounding(unittest.TestCase):
    """
    CORRECTION-2 §1, asserted AT THE SECOND.

    The whole point of these cases is the inclusive grace boundary. A suite
    written in whole minutes passes whether the comparison is < or <=, and that
    off-by-one is fifteen minutes of someone's pay.
    """

    def _on(self, hhmmss, sched="11:00:00"):
        d = datetime(2026, 6, 10)
        return clock_on(_t(d, hhmmss), _t(d, sched))

    def test_early_arrival_clocks_at_scheduled_start(self):
        self.assertEqual(self._on("10:42:00"), datetime(2026, 6, 10, 11, 0))

    def test_exactly_on_time(self):
        self.assertEqual(self._on("11:00:00"), datetime(2026, 6, 10, 11, 0))

    def test_one_second_late_is_inside_grace(self):
        self.assertEqual(self._on("11:00:01"), datetime(2026, 6, 10, 11, 0))

    def test_grace_boundary_exact_rounds_back(self):
        """11:05:00 — the inclusive edge. Rounds BACK."""
        self.assertEqual(self._on("11:05:00"), datetime(2026, 6, 10, 11, 0))

    def test_one_second_past_grace_rounds_forward(self):
        """11:05:01 — one second later, and fifteen minutes different."""
        self.assertEqual(self._on("11:05:01"), datetime(2026, 6, 10, 11, 15))

    def test_well_past_grace_rounds_back_to_quarter(self):
        self.assertEqual(self._on("11:18:00"), datetime(2026, 6, 10, 11, 15))

    def test_finish_grace_boundary_exact(self):
        d = datetime(2026, 6, 10)
        self.assertEqual(clock_off(_t(d, "14:50:00")), datetime(2026, 6, 10, 14, 45))

    def test_finish_one_second_past_grace(self):
        d = datetime(2026, 6, 10)
        self.assertEqual(clock_off(_t(d, "14:50:01")), datetime(2026, 6, 10, 15, 0))

    def test_late_flag_tracks_the_rounded_value_not_the_raw_one(self):
        """
        11:05:00 is five minutes after the scheduled start and is NOT late,
        because the grace pulls it back to 11:00. Late is decided on the
        clocked value, never on the raw one.
        """
        r = derive_times(sub("11:05:00", "15:00"), [], call(1, "11:00"), [], [], CFG)
        self.assertFalse(r["late"])

        r = derive_times(sub("11:05:01", "15:00"), [], call(1, "11:00"), [], [], CFG)
        self.assertTrue(r["late"])


def _t(day, hhmmss):
    h, m, s = (int(x) for x in hhmmss.split(":"))
    return day.replace(hour=h, minute=m, second=s)


class TestContinuity(unittest.TestCase):
    """Decision 17: a LOCKED edge AND a gap of at most 60 minutes. Both."""

    def test_locked_zero_gap(self):
        a = call(1, "08:00", 4.0)     # 08:00 - 12:00
        b = call(2, "12:00", 4.0)     # 12:00 - 16:00
        self.assertTrue(is_continuous(a, b, locked(1, 2)))

    def test_locked_sixty_minute_gap_is_inclusive(self):
        a = call(1, "08:00", 4.0)     # ends 12:00
        b = call(2, "13:00", 4.0)     # starts 13:00 — exactly 60
        self.assertTrue(is_continuous(a, b, locked(1, 2)))

    def test_locked_sixty_one_minute_gap_is_not(self):
        a = call(1, "08:00", 4.0)     # ends 12:00
        b = call(2, "13:01", 4.0)     # 61 minutes
        self.assertFalse(is_continuous(a, b, locked(1, 2)))

    def test_locked_five_hour_gap_is_not(self):
        a = call(1, "08:00", 4.0)
        b = call(2, "17:00", 4.0)
        self.assertFalse(is_continuous(a, b, locked(1, 2)))

    def test_recommended_edge_is_never_continuity(self):
        """
        Decision 20. A recommended edge is an offer suggestion nobody may have
        acted on; treating it as continuity would cost someone a four-hour
        minimum on the strength of a suggestion.
        """
        a = call(1, "08:00", 4.0)
        b = call(2, "12:00", 4.0)
        self.assertFalse(is_continuous(a, b, recommended(1, 2)))

    def test_no_edge_is_not_continuity_even_back_to_back(self):
        a = call(1, "08:00", 4.0)
        b = call(2, "12:00", 4.0)
        self.assertFalse(is_continuous(a, b, []))

    def test_edge_with_no_mode_is_not_locked(self):
        """A malformed edge must not silently grant continuity."""
        a = call(1, "08:00", 4.0)
        b = call(2, "12:00", 4.0)
        edges = [{"source_call": 1, "target_call": 2}]
        self.assertFalse(is_continuous(a, b, edges))

    def test_block_is_transitive(self):
        a = call(1, "08:00", 2.0)     # 08:00 - 10:00
        b = call(2, "10:00", 2.0)     # 10:00 - 12:00
        c = call(3, "12:00", 2.0)     # 12:00 - 14:00
        edges = locked(1, 2) + locked(2, 3)
        block = continuous_block(a, [b, c], edges)
        self.assertEqual([x["id"] for x in block], [1, 2, 3])

    def test_block_orders_by_scheduled_start(self):
        a = call(1, "12:00", 2.0)
        b = call(2, "08:00", 2.0)     # earlier, listed second
        edges = locked(1, 2)
        # 08:00-10:00 then 12:00-14:00 is a 120-minute gap: NOT one block
        self.assertEqual([x["id"] for x in continuous_block(a, [b], edges)], [1])


class TestMinimum(unittest.TestCase):
    """Decision 24 (Mike, 22 Nov 2026): the minimum follows the call's BOOKED
    length, with no continuity of any kind. Replaces CORRECTION-3's one minimum
    per engagement. Tests that were about the top-up itself now book the call
    for four hours; tests that were about short calls keep their inputs and
    show the new answer."""

    def test_single_two_hour_call_tops_up_to_four(self):
        # Was booked 2.0. Booked 4.0 now, so the call still gets the minimum.
        r = derive_times(sub("11:00", "13:00"), [], call(1, "11:00", 4.0), [], [], CFG)
        self.assertAlmostEqual(r["billable_total"], 4.0)
        self.assertAlmostEqual(r["actual_total"], 2.0)
        self.assertTrue(r["minimum_applied"])
        self.assertEqual(r["minimum_reason"], "topped")

    def test_call_booked_under_four_hours_is_paid_as_worked(self):
        """The deliberate effect of decision 24: booked 3, worked 2, paid 2."""
        r = derive_times(sub("11:00", "13:00"), [], call(1, "11:00", 3.0), [], [], CFG)
        self.assertFalse(r["minimum_applied"])
        self.assertEqual(r["minimum_reason"], "booked_short")
        self.assertAlmostEqual(r["billable_total"], 2.0)
        self.assertEqual(r["billable_end"], datetime(2026, 6, 10, 13, 0))

    def test_two_unconnected_two_hour_calls_each_top_up(self):
        # Were booked 2.0 each. Booked 4.0 now.
        a = call(1, "08:00", 4.0)
        b = call(2, "14:00", 4.0)
        ra = derive_times(sub("08:00", "10:00"), [], a, [b], [], CFG)
        rb = derive_times(sub("14:00", "16:00"), [], b, [a], [], CFG)
        self.assertAlmostEqual(ra["billable_total"], 4.0)
        self.assertAlmostEqual(rb["billable_total"], 4.0)
        self.assertAlmostEqual(ra["billable_total"] + rb["billable_total"], 8.0)

    def test_continuous_block_bills_sum_of_actuals_with_no_gap_deduction(self):
        """
        A 2-hour show and a 4-hour load out an hour apart bills SIX, with NO
        deduction for the gap. Values unchanged by decision 24, reached
        differently: the show is booked under four and paid as worked, and the
        load out is already at four. The edge no longer bears on it.
        """
        show = call(1, "18:00", 2.0)          # 18:00 - 20:00
        load = call(2, "21:00", 4.0)          # 21:00 - 01:00, a 60-minute gap
        edges = locked(1, 2)

        r_show = derive_times(sub("18:00", "20:00"), [],
                              show, [dict(load, actual_hours=4.0)], edges, CFG)
        r_load = derive_times(sub("21:00", "01:00", next_day=True), [],
                              load, [dict(show, actual_hours=2.0)], edges, CFG)

        self.assertEqual(r_show["block_call_ids"], [1, 2])
        self.assertFalse(r_show["minimum_applied"])
        self.assertFalse(r_load["minimum_applied"])
        self.assertAlmostEqual(r_show["billable_total"], 2.0)
        self.assertAlmostEqual(r_load["billable_total"], 4.0)
        self.assertAlmostEqual(
            r_show["billable_total"] + r_load["billable_total"], 6.0)

    def test_short_calls_in_a_block_are_each_paid_as_worked(self):
        """
        Was test_short_continuous_block_tops_up_on_the_last_call_only (Q32,
        CORRECTION-3): the load out carried the block's top-up, billable 2.0,
        pair total 4.0. Under decision 24 both calls are booked under four, so
        both are paid as worked: load out billable 1.0, pair total 3.0.
        """
        show = call(1, "18:00", 2.0)          # 18:00 - 20:00
        load = call(2, "20:00", 1.0)          # 20:00 - 21:00
        edges = locked(1, 2)

        r_show = derive_times(sub("18:00", "20:00"), [], show, [load], edges, CFG)
        r_load = derive_times(sub("20:00", "21:00"), [], load, [show], edges, CFG)

        self.assertEqual(r_load["block_call_ids"], [1, 2])     # still reported
        self.assertFalse(r_show["minimum_applied"])
        self.assertFalse(r_load["minimum_applied"])
        self.assertAlmostEqual(r_show["billable_total"], 2.0)
        self.assertAlmostEqual(r_load["billable_total"], 1.0)
        self.assertAlmostEqual(
            r_show["billable_total"] + r_load["billable_total"], 3.0)

    def test_three_hour_call_then_a_four_hour_call_no_continuity(self):
        """The 12312 shape: 3 h as worked, plus the 4 h call topped. Seven."""
        show = call(1, "19:00", 3.0)
        load = call(2, "22:00", 4.0)
        for edges in ([], locked(1, 2)):       # an edge makes no difference
            r_show = derive_times(sub("19:00", "22:00"), [], show, [load], edges, CFG)
            r_load = derive_times(sub("22:00", "00:30", next_day=True), [],
                                  load, [show], edges, CFG)
            self.assertAlmostEqual(r_show["billable_total"], 3.0)
            self.assertEqual(r_load["billable_end"], datetime(2026, 6, 11, 2, 0))
            self.assertAlmostEqual(
                r_show["billable_total"] + r_load["billable_total"], 7.0)

    def test_top_up_extends_the_finish_and_the_clock_sets_the_rate(self):
        """
        Q31. A 22:00-00:00 call topped to four notionally runs to 02:00, and
        those two extra hours are NIGHT because split_day_night() says so.
        """
        c = call(1, "22:00", 4.0)             # was booked 2.0
        r = derive_times(sub("22:00", "00:00", next_day=True), [], c, [], [], CFG)

        self.assertTrue(r["minimum_applied"])
        self.assertEqual(r["billable_end"], datetime(2026, 6, 11, 2, 0))
        self.assertAlmostEqual(r["billable_night"], 4.0)
        self.assertAlmostEqual(r["billable_day"], 0.0)

    def test_call_already_over_four_hours_is_untouched(self):
        r = derive_times(sub("08:00", "17:00"), [], call(1, "08:00", 9.0), [], [], CFG)
        self.assertFalse(r["minimum_applied"])
        self.assertAlmostEqual(r["billable_total"], 9.0)

    def test_sibling_hours_are_no_longer_an_input_or_an_output(self):
        """
        Replaces test_sibling_without_actual_hours_is_flagged_as_estimated
        (flag was True) and test_sibling_with_actual_hours_is_not_flagged (flag
        was False). The minimum is decided by the call's own booked length, so
        there is no block total to estimate and the flag is gone.
        """
        show = call(1, "18:00", 2.0)
        load = call(2, "20:00", 1.0)
        plain = derive_times(sub("20:00", "21:00"), [], load, [show], locked(1, 2), CFG)
        given = derive_times(sub("20:00", "21:00"), [], load,
                             [dict(show, actual_hours=9.0)], locked(1, 2), CFG)
        self.assertNotIn("sibling_hours_estimated", plain)
        self.assertEqual(plain, given)


class TestMinimumRule(unittest.TestCase):
    """minimum_top_up(): the one copy of decisions 24 and 23."""

    SCHED = datetime(2026, 6, 10, 22, 0)

    def _top(self, on, off, booked=4.0, late=False, sched=None):
        return minimum_top_up(on, off, booked, sched or self.SCHED, late, CFG)

    def test_booked_four_paid_two_and_a_half_is_written_to_start_plus_four(self):
        r = self._top(self.SCHED, datetime(2026, 6, 11, 0, 30))
        self.assertEqual((r["reason"], r["minimum_applies"], r["minimum_applied"]),
                         ("topped", True, True))
        self.assertAlmostEqual(r["span_hours"], 2.5)
        self.assertEqual(r["billable_end"], datetime(2026, 6, 11, 2, 0))

    def test_booked_three_paid_three_is_untouched(self):
        on, off = datetime(2026, 6, 10, 19, 0), datetime(2026, 6, 10, 22, 0)
        r = minimum_top_up(on, off, 3.0, on, False, CFG)
        self.assertEqual((r["reason"], r["minimum_applies"], r["minimum_applied"]),
                         ("booked_short", False, False))
        self.assertEqual(r["billable_end"], off)

    def test_booked_three_worked_two_stays_two(self):
        on, off = datetime(2026, 6, 10, 19, 0), datetime(2026, 6, 10, 21, 0)
        r = minimum_top_up(on, off, 3.0, on, False, CFG)
        self.assertEqual(r["reason"], "booked_short")
        self.assertEqual(r["billable_end"], off)

    def test_booked_just_under_four_is_short_and_exactly_four_is_not(self):
        on, off = self.SCHED, datetime(2026, 6, 11, 0, 30)
        self.assertEqual(self._top(on, off, booked=3.75)["reason"], "booked_short")
        self.assertEqual(self._top(on, off, booked=4.0)["reason"], "topped")
        self.assertEqual(self._top(on, off, booked="4")["reason"], "topped")   # as SmartStaff sends it

    def test_booked_four_worked_four_and_a_quarter_is_untouched(self):
        off = datetime(2026, 6, 11, 2, 15)
        r = self._top(self.SCHED, off)
        self.assertEqual((r["reason"], r["minimum_applied"]), ("four_hours_or_more", False))
        self.assertEqual(r["billable_end"], off)

    def test_no_booked_length_is_never_topped(self):
        off = datetime(2026, 6, 11, 0, 30)
        for booked in (0, "0", None, "", "abc", -1):
            r = self._top(self.SCHED, off, booked=booked)
            self.assertEqual((r["reason"], r["minimum_applies"], r["minimum_applied"]),
                             ("no_booked_length", False, False), booked)
            self.assertEqual(r["billable_end"], off)

    # ---- decision 23: a late start gets the call's finish, not four hours ----

    def test_late_paid_from_2300_is_written_to_0200(self):
        r = self._top(datetime(2026, 6, 10, 23, 0), datetime(2026, 6, 11, 0, 30), late=True)
        self.assertEqual((r["reason"], r["late_start"]), ("topped", True))
        self.assertEqual(r["billable_end"], datetime(2026, 6, 11, 2, 0))       # 3 h

    def test_late_paid_from_2215_is_written_to_0200_not_0215(self):
        r = self._top(datetime(2026, 6, 10, 22, 15), datetime(2026, 6, 11, 0, 30), late=True)
        self.assertEqual(r["billable_end"], datetime(2026, 6, 11, 2, 0))       # 3.75 h

    def test_late_with_a_finish_past_start_plus_four_keeps_its_own_finish(self):
        # 22:15 - 02:30 is 4.25 h: nothing to decide.
        off = datetime(2026, 6, 11, 2, 30)
        r = self._top(datetime(2026, 6, 10, 22, 15), off, late=True)
        self.assertEqual((r["reason"], r["billable_end"]), ("four_hours_or_more", off))
        # 23:30 - 02:30 is 3 h, but already past 02:00: own finish stands.
        r = self._top(datetime(2026, 6, 10, 23, 30), off, late=True)
        self.assertEqual((r["reason"], r["minimum_applied"], r["billable_end"]),
                         ("own_finish_later", False, off))

    def test_on_time_is_exactly_four_hours(self):
        r = self._top(self.SCHED, datetime(2026, 6, 11, 0, 30), late=False)
        self.assertFalse(r["late_start"])
        self.assertEqual(r["billable_end"], datetime(2026, 6, 11, 2, 0))

    def test_a_top_up_with_no_scheduled_start_raises(self):
        with self.assertRaises(DerivationError):
            minimum_top_up(self.SCHED, datetime(2026, 6, 11, 0, 30), 4.0, None, False, CFG)

    def test_october_changeover_finish_is_four_real_hours_after_the_start(self):
        # Booked 4 h from 00:00 on Sun 4 Oct 2026; 02:00 - 03:00 never happens,
        # so four real hours after 00:00 is 05:00 by the clock.
        sched = datetime(2026, 10, 4, 0, 0)
        r = minimum_top_up(sched, datetime(2026, 10, 4, 1, 0), 4.0, sched, False, CFG)
        self.assertEqual(r["billable_end"], datetime(2026, 10, 4, 5, 0))

    def test_derive_times_applies_decision_23_to_a_late_arrival(self):
        # 22:00 call booked 4 h, arrived 22:20 (rounds to 22:15), finished 00:30.
        c = call(1, "22:00", 4.0)
        r = derive_times(sub("22:20", "00:30", next_day=True), [], c, [], [], CFG)
        self.assertTrue(r["late"])
        self.assertEqual(r["billable_end"], datetime(2026, 6, 11, 2, 0))
        self.assertAlmostEqual(r["billable_total"], 3.75)


class TestBreaks(unittest.TestCase):

    def test_thirty_minute_break_at_noon_comes_off_day(self):
        r = derive_times(sub("08:00", "17:00"), [brk("12:00", 30)],
                         call(1, "08:00", 9.0), [], [], CFG)
        self.assertAlmostEqual(r["break_day"], 0.5)
        self.assertAlmostEqual(r["break_night"], 0.0)
        self.assertAlmostEqual(r["actual_total"], 8.5)

    def test_break_spanning_night_start_is_split_by_the_same_clock(self):
        """
        19:50 for 30 minutes crosses nightStart at 20:00: ten minutes off day,
        twenty off night. Classifying the whole break by its start time would
        be simpler and would disagree with how the surrounding hours are
        computed — the same clock giving two answers.
        """
        r = derive_times(sub("17:00", "23:00"), [brk("19:50", 30)],
                         call(1, "17:00", 6.0), [], [], CFG)
        self.assertAlmostEqual(r["break_day"], 10 / 60.0)
        self.assertAlmostEqual(r["break_night"], 20 / 60.0)

    def test_two_breaks_are_both_deducted_and_not_merged(self):
        r = derive_times(sub("08:00", "18:00"),
                         [brk("11:00", 45), brk("15:00", 45)],
                         call(1, "08:00", 10.0), [], [], CFG)
        self.assertAlmostEqual(r["break_day"], 1.5)
        self.assertAlmostEqual(r["actual_total"], 8.5)

    def test_break_before_the_shift_raises(self):
        """Q37: by the time a value reaches here it is a bug, not a case."""
        with self.assertRaises(DerivationError):
            derive_times(sub("17:00", "23:00"), [brk("08:00", 30)],
                         call(1, "17:00", 6.0), [], [], CFG)

    def test_break_running_past_knock_off_raises(self):
        with self.assertRaises(DerivationError):
            derive_times(sub("08:00", "15:00"), [brk("14:45", 30)],
                         call(1, "08:00", 7.0), [], [], CFG)

    def test_break_ending_exactly_at_knock_off_is_inside(self):
        r = derive_times(sub("08:00", "15:00"), [brk("14:30", 30)],
                         call(1, "08:00", 7.0), [], [], CFG)
        self.assertAlmostEqual(r["break_day"], 0.5)

    def test_overnight_break_after_midnight_is_accepted(self):
        r = derive_times(sub("17:00", "02:00", next_day=True),
                         [brk("00:10", 30, next_day=True)],
                         call(1, "17:00", 9.0), [], [], CFG)
        self.assertAlmostEqual(r["break_night"], 0.5)

    def test_break_deducted_from_billable_as_well_as_actual(self):
        """A top-up must not quietly hand back the break."""
        r = derive_times(sub("11:00", "13:00"), [brk("12:00", 30)],
                         call(1, "11:00", 4.0), [], [], CFG)    # was booked 2.0
        self.assertTrue(r["minimum_applied"])
        self.assertAlmostEqual(r["actual_total"], 1.5)
        self.assertAlmostEqual(r["billable_total"], 3.5)


class TestDaylightSaving(unittest.TestCase):
    """
    Q36, closed 26 Aug 2026: mode="duration". Both transitions, not just one.
    """

    def test_october_transition_pays_seven_hours_for_midnight_to_eight(self):
        """
        The clock jumps 02:00 -> 03:00. The crew member was physically present
        for SEVEN hours and is paid seven. This is the case Q36 settled.
        """
        c = call(1, "00:00", 8.0, day=OCT_DST)
        r = derive_times(sub("00:00", "08:00"), [], c, [], [], CFG)
        self.assertAlmostEqual(r["actual_total"], 7.0)

    def test_april_transition_pays_nine_hours_for_midnight_to_eight(self):
        """
        The mirror case: 02:30 happens twice, so eight wall-clock hours are
        NINE real ones. Testing only October would miss a whole direction.
        """
        c = call(1, "00:00", 8.0, day=APR_DST)
        r = derive_times(sub("00:00", "08:00"), [], c, [], [], CFG)
        self.assertAlmostEqual(r["actual_total"], 9.0)

    def test_october_short_call_tops_up_on_real_hours_not_clock_hours(self):
        """
        00:00-04:00 across the October jump is three real hours, so the minimum
        still applies. Reading the clock instead would see four and skip it.
        """
        c = call(1, "00:00", 4.0, day=OCT_DST)
        r = derive_times(sub("00:00", "04:00"), [], c, [], [], CFG)
        self.assertAlmostEqual(r["actual_total"], 3.0)
        self.assertTrue(r["minimum_applied"])


    def test_top_up_across_the_october_jump_advances_in_real_hours(self):
        """
        REGRESSION. A 1-hour call finishing at 01:00 on the October Sunday tops
        up by three REAL hours. The clock skips 02:00-03:00, so the notional
        finish is 05:00 by the clock, not 04:00.

        The first cut of derive_times() added the deficit with naive datetime
        arithmetic and produced 04:00 — two real hours, billing three instead
        of four. The bug was invisible on every non-transition day.
        """
        c = call(1, "00:00", 4.0, day=OCT_DST)    # was booked 1.0
        r = derive_times(sub("00:00", "01:00"), [], c, [], [], CFG)

        self.assertTrue(r["minimum_applied"])
        self.assertAlmostEqual(r["billable_total"], 4.0)
        self.assertEqual(r["billable_end"], datetime(2026, 10, 4, 5, 0))

    def test_top_up_across_the_april_repeat_advances_in_real_hours(self):
        """
        The mirror: 02:00-03:00 happens twice, so three real hours from 01:00
        reach only 03:00 on the clock.
        """
        c = call(1, "00:00", 4.0, day=APR_DST)    # was booked 1.0
        r = derive_times(sub("00:00", "01:00"), [], c, [], [], CFG)

        self.assertTrue(r["minimum_applied"])
        self.assertAlmostEqual(r["billable_total"], 4.0)
        self.assertEqual(r["billable_end"], datetime(2026, 4, 5, 3, 0))


class TestGuards(unittest.TestCase):

    def test_off_before_on_raises(self):
        with self.assertRaises(DerivationError):
            derive_times(sub("17:00", "09:00"), [], call(1, "17:00", 6.0), [], [], CFG)

    def test_malformed_time_raises(self):
        with self.assertRaises(DerivationError):
            derive_times(sub("5pm", "23:00"), [], call(1, "17:00", 6.0), [], [], CFG)

    def test_zero_duration_break_raises(self):
        with self.assertRaises(DerivationError):
            derive_times(sub("08:00", "17:00"), [brk("12:00", 0)],
                         call(1, "08:00", 9.0), [], [], CFG)


if __name__ == "__main__":
    unittest.main(verbosity=2)

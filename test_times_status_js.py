#!/usr/bin/env python3
"""goatTimesState() — which shade and which label a call row gets
(BRIEF-times-navigation §1.3, D4). Runs the page's own function under node.

Every row of the order is covered, plus the three cases the brief calls out
because they are the ones that would otherwise read as an all-clear:

  * confirmed = 0 on a finished call      — unstaffed, not "no times"
  * times_filled = 1 with with_times null — the tick wins; the endpoint sends
    null there on purpose (the shared helper drops ticked calls) and nothing
    may turn that into a count
  * promo_pending > 0 with everything else in — NOT "all in", because an
    unanswered promotion can hold no times at all

It also asserts the rename left no literal 'Enter Times' comparison behind:
index.html:26854 used to decide whether to draw Prev/Next by comparing the
dialog title with that exact string, and renaming the title without it would
have lost the nav silently on a grid opened from the Times outstanding lane.

Run:
    python3 test_times_status_js.py
"""

import re
import sys
import unittest

import js_harness as H

FUNCS = H.extract("goatTimesState", "goatTimesLabelHtml") if H.NODE else ""


def state(s):
    """goatTimesState(s) under node."""
    js = H.DOM_PRELUDE + FUNCS + """
var v = goatTimesState(%s);
v.html = goatTimesLabelHtml(%s);
console.log(JSON.stringify(v));
""" % (s, s)
    return H.run(js)


def call(**kw):
    """One entry of the endpoint's calls{}, with the all-clear defaults."""
    d = {"ended": True, "cancelled": False, "confirmed": 0, "promo_pending": 0,
         "with_times": 0, "times_filled": 0, "call_locked": 0, "no_show_open": 0}
    d.update(kw)
    import json
    return json.dumps(d)


@unittest.skipUnless(H.NODE, "node not installed")
class TimesState(unittest.TestCase):

    # ── The order, row by row (§1.3) ────────────────────────────────────────

    def test_1_cancelled_is_plain_and_silent(self):
        # Its own CANCELLED chip already says so, and a paid cancellation keeps
        # the TIMES TO CHECK chip it has always had.
        v = state(call(cancelled=True, confirmed=6, with_times=0))
        self.assertEqual(v["state"], "cancelled")
        self.assertEqual(v["shade"], "")
        self.assertEqual(v["html"], "")

    def test_2_not_finished_is_plain(self):
        v = state(call(ended=False, confirmed=6, with_times=0))
        self.assertEqual(v["state"], "running")
        self.assertEqual(v["shade"], "")
        self.assertEqual(v["label"], "")

    def test_3_locked_is_green(self):
        v = state(call(call_locked=1, times_filled=1, confirmed=6, with_times=6))
        self.assertEqual(v["state"], "locked")
        self.assertEqual(v["shade"], "locked")
        self.assertEqual(v["tone"], "locked")
        self.assertIn("LOCKED", v["label"].upper())

    def test_4_times_filled_is_lavender(self):
        v = state(call(times_filled=1, confirmed=6, with_times=6))
        self.assertEqual(v["state"], "filled")
        self.assertEqual(v["shade"], "filled")
        self.assertEqual(v["tone"], "filled")
        self.assertIn("TIMES FILLED", v["label"].upper())

    def test_5_nobody_confirmed_is_plain_and_silent(self):
        # A finished call nobody was booked on is not outstanding work.
        v = state(call(confirmed=0, with_times=0))
        self.assertEqual(v["state"], "unstaffed")
        self.assertEqual(v["shade"], "")
        self.assertEqual(v["html"], "")

    def test_6_no_show_leads(self):
        # Accept is REFUSED until Ops resolve it, so it outranks every count --
        # including a call where everyone otherwise has times.
        v = state(call(confirmed=6, with_times=6, no_show_open=1))
        self.assertEqual(v["state"], "no_show")
        self.assertEqual(v["shade"], "")
        self.assertEqual(v["tone"], "warn")
        self.assertIn("NO-SHOW", v["label"].upper())

    def test_7_promo_pending_blocks_all_in(self):
        v = state(call(confirmed=6, with_times=6, promo_pending=2))
        self.assertEqual(v["state"], "promo")
        self.assertNotIn("ALL IN", v["label"].upper())
        self.assertIn("2", v["label"])
        self.assertIn("PROMO", v["label"].upper())

    def test_8_all_in(self):
        v = state(call(confirmed=24, with_times=24))
        self.assertEqual(v["state"], "all_in")
        self.assertEqual(v["shade"], "")
        self.assertIn("ALL IN", v["label"].upper())

    def test_9_some(self):
        v = state(call(confirmed=24, with_times=12))
        self.assertEqual(v["state"], "some")
        self.assertEqual(v["shade"], "")
        self.assertEqual(v["tone"], "warn")
        self.assertIn("12/24", v["label"])

    def test_9_none(self):
        v = state(call(confirmed=24, with_times=0))
        self.assertEqual(v["state"], "none")
        self.assertEqual(v["shade"], "")
        self.assertEqual(v["tone"], "bad")          # var(--conflict)
        self.assertIn("0/24", v["label"])

    # ── The cases that must not read as an all-clear ────────────────────────

    def test_ticked_call_with_null_with_times(self):
        # What the endpoint actually sends for a ticked call. The tick wins at
        # step 4, so the null is never reached.
        v = state(call(times_filled=1, confirmed=24, with_times=None))
        self.assertEqual(v["state"], "filled")
        self.assertEqual(v["shade"], "filled")

    def test_ticked_call_with_fewer_times_than_confirmed(self):
        # times_filled is an Ops REVIEW flag: a call can be accepted with rows
        # that have no hours (somebody genuinely worked none). The tick still
        # wins -- it is the authoritative done-tick.
        v = state(call(times_filled=1, confirmed=24, with_times=3))
        self.assertEqual(v["state"], "filled")
        self.assertEqual(v["shade"], "filled")

    def test_null_with_times_without_the_tick_says_nothing(self):
        # Can't happen today. If the endpoint ever changed, inventing "0/24"
        # here would be a false alarm and "24/24" a false all-clear, so the
        # answer is silence.
        v = state(call(confirmed=24, with_times=None))
        self.assertEqual(v["state"], "unknown")
        self.assertEqual(v["label"], "")

    def test_missing_entry_says_nothing(self):
        v = state("null")
        self.assertEqual(v["state"], "unknown")
        self.assertEqual(v["shade"], "")
        self.assertEqual(v["html"], "")

    def test_promo_shows_beside_a_count_not_instead_of_it(self):
        # 18 of 20 in and 1 promo-pending: the count is the useful number, and
        # the promotion is worth saying too. Both chips.
        v = state(call(confirmed=20, with_times=18, promo_pending=1))
        self.assertEqual(v["state"], "some")
        self.assertIn("18/20", v["label"])
        self.assertIn("PROMO", v["note"].upper())
        self.assertIn("18/20", v["html"])
        self.assertIn("promo", v["html"].lower())

    def test_promo_on_an_unstaffed_call_is_still_said(self):
        # Every person on the call is an unanswered promotion, so `confirmed`
        # (which excludes them) is 0. "Nothing here" would be wrong.
        v = state(call(confirmed=0, with_times=0, promo_pending=3))
        self.assertEqual(v["state"], "unstaffed")
        self.assertIn("3", v["note"])
        self.assertNotEqual(v["html"], "")

    def test_with_times_over_confirmed_is_all_in_not_negative(self):
        # An unbooked submission can put a person in the submitted set who is
        # not on the roster. The endpoint clamps; this never prints "25/24".
        v = state(call(confirmed=24, with_times=24))
        self.assertEqual(v["state"], "all_in")

    def test_shade_is_only_ever_locked_or_filled(self):
        # The brief gives two shades. Every other state is a label on a plain
        # row -- amber everywhere would make the list unreadable.
        for s in [call(confirmed=6, with_times=0), call(confirmed=6, with_times=3),
                  call(confirmed=6, with_times=6), call(confirmed=6, with_times=6, no_show_open=2),
                  call(confirmed=6, with_times=6, promo_pending=1), call(confirmed=0),
                  call(ended=False), call(cancelled=True)]:
            self.assertEqual(state(s)["shade"], "", s)

    def test_html_carries_the_tone_class(self):
        self.assertIn("ts-lbl-filled", state(call(times_filled=1, confirmed=2, with_times=2))["html"])
        self.assertIn("ts-lbl-locked", state(call(call_locked=1, confirmed=2, with_times=2))["html"])
        self.assertIn("ts-lbl-bad", state(call(confirmed=2, with_times=0))["html"])


class TheRename(unittest.TestCase):
    """D2: the title is one constant, and no literal of it is compared."""

    def test_no_literal_enter_times_comparison(self):
        src = H.page()
        hits = re.findall(r"==\s*['\"]⏱?\s?Enter Times['\"]", src)
        self.assertEqual(hits, [], "compare with CT_TITLE, not a literal: %r" % hits)

    def test_the_title_constant_exists_and_is_the_new_name(self):
        src = H.page()
        m = re.search(r"const CT_TITLE = '([^']+)'", src)
        self.assertIsNotNone(m, "CT_TITLE not found in index.html")
        self.assertEqual(m.group(1), "View / Enter Times")

    def test_the_prev_next_check_uses_the_constant(self):
        src = H.page()
        self.assertIn("t.textContent === CT_TITLE", src)

    def test_the_grid_sets_its_title_from_the_constant(self):
        src = H.page()
        self.assertIn("'entity-modal-title').textContent = CT_TITLE", src)

    def test_no_button_still_reads_plain_enter_times(self):
        src = H.page()
        self.assertNotIn("entityBtn('⏱ Enter Times'", src)


if __name__ == "__main__":
    unittest.main(verbosity=2)

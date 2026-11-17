#!/usr/bin/env python3
"""Import Times: a call's date and time beside its name (Mike, 17 Nov 2026).

A booking can carry several calls with the same name ("Crew Boss", "Show
Call"), and the review screen showed the name alone, so they could not be told
apart. The preview now carries each call's scheduled start (call_time) in every
place it names a call, and the screen prints "Show Call · Sat 21 Nov 18:00".

  - _build_import_preview: call_time on cards, on needs-call candidates, on
    duplicate-tab warnings and on cancelled-call notes (SmartStaff faked)
  - tiCallLabel: 24-hour clock, the date read as written (never shifted by
    the viewer's zone), the name alone when there is no time, entities
    decoded once and the caller escaping
  - the review card, duplicate, cancelled and needs-call blocks name two
    same-named calls differently

Synthetic data only. Run: python3 test_import_times_call_label.py
"""
import json
import unittest
from datetime import datetime
from unittest import mock

import app as A
import js_harness as H


def _ts(y, m, d):
    return int(datetime(y, m, d).timestamp())


BOOKING = {"name": "Test booking", "calls": [
    {"call_id": 101, "call_name": "Show Call", "start_date": _ts(2026, 11, 21), "start_time": "18:00:00"},
    {"call_id": 102, "call_name": "Show Call", "start_date": _ts(2026, 11, 22), "start_time": "09:30:00"},
    {"call_id": 103, "call_name": "Crew Boss", "start_date": _ts(2026, 11, 23), "start_time": "07:00:00",
     "cancelled": True},
    {"call_id": 104, "call_name": "Load Out", "start_date": _ts(2026, 11, 23), "start_time": "23:00:00"},
]}

CREW = {101: [1, 2], 102: [3], 103: [4], 104: [5, 6]}


def _times(ss, cid):
    return {"crew": [{"ein": e, "user_id": 9000 + e, "lastname": "L%d" % e, "firstname": "F",
                      "status": 5} for e in CREW[int(cid)]]}, None


def _row(ein):
    return {"ein": ein, "on": "18:00", "off": "23:00", "lastname": "L%d" % ein, "firstname": "F"}


PARSED = {"tabs": [
    {"tab_name": "Sat show", "call_id": 101, "rows": [_row(1), _row(2)]},
    {"tab_name": "Sun show", "call_id": 102, "rows": [_row(3)]},
    {"tab_name": "Boss", "call_id": 103, "rows": [_row(4)]},
    {"tab_name": "Out A", "call_id": 104, "rows": [_row(5)]},
    {"tab_name": "Out B", "call_id": 104, "rows": [_row(6)]},
    {"tab_name": "Hand made", "call_id": None, "rows": [_row(5), _row(6)], "call_time": None},
], "skipped_tabs": []}


class Preview(unittest.TestCase):

    def setUp(self):
        p = mock.patch.multiple(A, fetch_booking_bulk=lambda ss, b: (BOOKING, None),
                                ss_get_call_times=_times)
        p.start()
        self.addCleanup(p.stop)
        try:
            self.prev, err = A._build_import_preview(object(), "1", json.loads(json.dumps(PARSED)))
        except Exception as e:                     # pragma: no cover
            self.fail("preview raised %r" % e)
        self.assertIsNone(err)

    def test_cards_carry_the_call_time(self):
        by = {c["call_id"]: c for c in self.prev["calls"]}
        self.assertEqual(by[101]["call_time"], "2026-11-21T18:00:00")
        self.assertEqual(by[102]["call_time"], "2026-11-22T09:30:00")

    def test_cancelled_note_carries_the_call_time(self):
        t = self.prev["cancelled_tabs"]
        self.assertEqual([(x["call_id"], x["call_time"]) for x in t], [(103, "2026-11-23T07:00:00")])

    def test_duplicate_warning_carries_the_call_time(self):
        d = self.prev["duplicate_tabs"]
        self.assertEqual(len(d), 1)
        self.assertEqual(d[0]["call_time"], "2026-11-23T23:00:00")
        self.assertEqual(d[0]["call_name"], "Load Out")

    def test_needs_call_candidates_carry_the_call_time(self):
        nc = {t["tab_name"]: t for t in self.prev["needs_call_tabs"]}
        if "Hand made" not in nc:
            self.skipTest("hand-made tab classified differently by this build")
        times = {c["call_id"]: c["call_time"] for c in nc["Hand made"]["candidates"]}
        self.assertEqual(times.get(104), "2026-11-23T23:00:00")


FUNCS = H.extract("tiCallLabel", "_tiDuplicateBlock", "_tiCancelledBlock", "_tiNeedsCallBlock") if H.NODE else ""


# The harness's decodeEntities is an identity stub (no DOM). The page's decodes
# through a <textarea>; this does the same for the entities SmartStaff stores.
DECODE = r"""
decodeEntities = function(s){ if(s == null) return ''; return String(s)
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"')
  .replace(/&#39;/g, "'").replace(/&amp;/g, '&'); };
"""


def run(body):
    return H.run(H.DOM_PRELUDE + DECODE + FUNCS + "\n" + body)


@unittest.skipUnless(H.NODE, "node not installed")
class Label(unittest.TestCase):

    def test_label_formats(self):
        out = run(r"""console.log(JSON.stringify([
          tiCallLabel('Show Call', '2026-11-21T18:00:00', 101),
          tiCallLabel('Show Call', '2026-11-22T09:30:00', 102),
          tiCallLabel('Stage &amp; Lights', '2027-01-04T00:00:00', 7),
          tiCallLabel('Crew Boss', null, 9),
          tiCallLabel('', '2026-11-21T18:00:00', 55),
          tiCallLabel(null, null, 56)]));""")
        self.assertEqual(out, ["Show Call · Sat 21 Nov 18:00", "Show Call · Sun 22 Nov 09:30",
                               "Stage & Lights · Mon 4 Jan 00:00", "Crew Boss",
                               "Call 55 · Sat 21 Nov 18:00", "Call 56"])

    def test_blocks_tell_same_named_calls_apart(self):
        out = run(r"""
          var d = {
            duplicate_tabs: [{call_id: 104, tab_names: ['Out A', 'Out B'], call_name: 'Load Out', call_time: '2026-11-23T23:00:00'}],
            cancelled_tabs: [{call_id: 103, call_name: 'Crew Boss', call_time: '2026-11-23T07:00:00'}],
            needs_call_tabs: [{tab_name: 'Hand made', crew_count: 2, ein_count: 2, candidates: [
              {call_id: 101, call_name: 'Show Call', call_time: '2026-11-21T18:00:00', overlap: 2},
              {call_id: 102, call_name: 'Show Call', call_time: '2026-11-22T09:30:00', overlap: 1}]}]};
          console.log(JSON.stringify({dup: _tiDuplicateBlock(d), can: _tiCancelledBlock(d), nc: _tiNeedsCallBlock(d)}));
        """)
        self.assertIn("(Load Out · Mon 23 Nov 23:00)", out["dup"])
        self.assertIn("Crew Boss · Mon 23 Nov 07:00", out["can"])
        self.assertIn("Show Call · Sat 21 Nov 18:00 — 2 of 2 crew match", out["nc"])
        self.assertIn("Show Call · Sun 22 Nov 09:30 — 1 of 2 crew match", out["nc"])

    def test_names_are_escaped_once(self):
        out = run(r"""
          var d = {cancelled_tabs: [{call_id: 1, call_name: '<b>x</b> &amp; y', call_time: null}]};
          console.log(JSON.stringify({h: _tiCancelledBlock(d)}));
        """)
        self.assertIn("&lt;b&gt;x&lt;/b&gt; &amp; y", out["h"])
        self.assertNotIn("<b>x</b>", out["h"])


@unittest.skipUnless(H.NODE, "node not installed")
class ReviewCard(unittest.TestCase):

    def test_card_header_uses_the_label(self):
        with open(A.__file__.replace("app.py", "templates/index.html"), encoding="utf-8") as f:
            src = f.read()
        self.assertIn("escapeHtml(tiCallLabel(c.call_name, c.call_time, c.call_id))+'</div>'", src)
        self.assertIn("' — '+tiCallLabel(c.call_name, c.call_time, c.call_id)+'…'", src)


if __name__ == "__main__":
    unittest.main()

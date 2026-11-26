#!/usr/bin/env python3
"""Import from check-in — crew ADDED ON THE DAY (claude/PLAN-added-crew-on-the-day.md,
slice 3; Mike, 25-26 Nov 2026).

  - attach_flags lifts added people out of `unmatched` into card.checkin_added
    (writable only with a sign-on AND sign-off; no off-roster warning), and moved
    people out of `skipped` into card.checkin_moved; neither is repeated in the
    card notes
  - app.checkin_add_crew: moved 5 -> 14 first, Add & Confirm on ONE call, read
    back, roll the old call back to 5 on failure, then the times; every
    refusal and half-way state (SmartStaff faked)
  - the review screen: added rows start UNTICKED; ticking includes the call

Made-up booking 90001, calls 9001x, users 8000x, invented names only.
Run: python3 test_import_added_crew.py
"""
import unittest
from unittest import mock

import app as A
import checkin_import as C
import js_harness as H

D = "2026-11-23T"
Z = ":00+11:00"
NOW = C.parse_iso("2026-11-24T07:30:00+11:00")
LI, SX = 90011, 90012


def _row(uid, ein, last, state, **kw):
    r = {"user_id": uid, "ein": ein, "lastname": last, "firstname": "X", "unbooked": False,
         "added": None, "moved_to_call_id": None, "on_roster": True, "state": state,
         "tapped_on": None, "tapped_off": None, "on_corrected": False, "off_corrected": False,
         "late": False, "late_source": None, "paid_on": None, "paid_off": None, "paid_hours": None,
         "breaks": [], "under_4h": False, "note": "", "left_early": None}
    r.update(kw)
    return r


ADDED = {"by_user_id": 80900, "by_name": "Boss, Bo", "reason": "moved", "moved_from_call_id": LI,
         "covering_for_user_id": 80003, "covering_for_name": "Charlie, Cy"}


def _feed():
    return {"booking_id": 90001, "mode": "live", "generated_at": D + "18:00" + Z, "unknown_call_ids": [],
            "calls": [
                {"call_id": LI, "call_name": "Load In", "scheduled_start": D + "10:00" + Z, "rows": [
                    _row(80001, 90001, "Alpha", "moved", moved_to_call_id=SX),
                    _row(80004, 90004, "Delta", "off", tapped_on=D + "10:00" + Z,
                         paid_on=D + "10:00" + Z, paid_off=D + "15:00" + Z)]},
                {"call_id": SX, "call_name": "SX", "scheduled_start": D + "10:00" + Z, "rows": [
                    _row(80001, 90001, "Alpha", "off", unbooked=True, on_roster=False, added=ADDED,
                         tapped_on=D + "10:05" + Z, paid_on=D + "10:00" + Z, paid_off=D + "15:00" + Z),
                    _row(80007, 90007, "Golf", "not_on", unbooked=True, on_roster=False,
                         added=dict(ADDED, reason="called_in", moved_from_call_id=None,
                                    covering_for_user_id=None, covering_for_name="")),
                    _row(80002, 90002, "Bravo", "off", tapped_on=D + "10:00" + Z,
                         paid_on=D + "10:00" + Z, paid_off=D + "15:00" + Z)]}]}


def _preview(feed):
    """What _build_import_preview leaves for this feed: Alpha/Golf unmatched on
    SX (not on its roster), Alpha skipped on LI (no times)."""
    return {"calls": [
        {"call_id": LI, "matched": [{"ein": 90004, "user_id": 80004, "status": 5}],
         "unmatched": [], "skipped": [{"ein": 90001, "name": "Alpha, X", "reason": "no times in sheet"}],
         "roster_only": []},
        {"call_id": SX, "matched": [{"ein": 90002, "user_id": 80002, "status": 5}],
         "unmatched": [{"ein": 90001, "name": "Alpha, X"}, {"ein": 90007, "name": "Golf, X"}],
         "skipped": [], "roster_only": []}]}


class Preview(unittest.TestCase):

    def setUp(self):
        self.feed = _feed()
        self.prev = C.attach_flags(_preview(self.feed), self.feed, now=NOW)
        self.li, self.sx = self.prev["calls"]

    def test_added_people_are_lifted_out_of_unmatched(self):
        self.assertEqual(self.sx["unmatched"], [])
        names = [a["name"] for a in self.sx["checkin_added"]]
        self.assertEqual(names, ["Alpha, X", "Golf, X"])

    def test_added_row_carries_times_reason_and_is_writable_only_with_times(self):
        a, g = self.sx["checkin_added"]
        self.assertEqual((a["on"], a["off"], a["writable"]), ("10:00", "15:00", True))
        self.assertEqual((a["reason"], a["moved_from_call_id"], a["moved_from_call_name"]), ("moved", LI, "Load In"))
        self.assertEqual((a["covering_for_name"], a["by_name"]), ("Charlie, Cy", "Boss, Bo"))
        self.assertEqual((g["reason"], g["writable"], g["on"]), ("called_in", False, None))

    def test_no_off_roster_warning_for_an_added_person(self):
        self.assertNotIn("off_roster", [f["code"] for a in self.sx["checkin_added"] for f in a["flags"]])
        self.assertNotIn("off_roster", [n["code"] for n in self.sx["checkin_notes"]])

    def test_moved_person_leaves_skipped_and_is_listed_as_moved(self):
        self.assertEqual(self.li["skipped"], [])
        self.assertEqual(self.li["checkin_moved"],
                         [{"user_id": 80001, "ein": 90001, "name": "Alpha, X", "to_call_id": SX, "to_call_name": "SX"}])

    def test_neither_is_repeated_in_the_card_notes(self):
        for c in (self.li, self.sx):
            self.assertFalse([n for n in c["checkin_notes"] if n["ein"] in (90001, 90007)])

    def test_cards_without_added_people_get_empty_lists(self):
        self.assertEqual(self.li["checkin_added"], [])
        self.assertEqual(self.sx["checkin_moved"], [])


class FakeSS(object):
    """add-call.php confcrew: answers with the failed-id list."""
    def __init__(self, refuse=False):
        self.refuse, self.urls = refuse, []

    def get(self, url, **kw):
        self.urls.append(url)
        r = mock.Mock()
        r.status_code = 200
        r.text = str(SX) if self.refuse else ""
        return r


class AddCrew(unittest.TestCase):
    """checkin_add_crew with SmartStaff's three reads/writes faked."""

    def run_add(self, statuses, from_id=LI, refuse=False, status_ok=True, revert_ok=True,
                times_ok=True, confirm_takes=True):
        st = dict(statuses)          # {(call, user): status}
        calls = []

        def call_times(ss, cid):
            return {"crew": [{"user_id": u, "status": s} for (c, u), s in st.items() if c == int(cid)]}, None

        def update_status(ss, cid, uid, status):
            calls.append(("status", cid, status))
            if status == 14 and not status_ok:
                return None, "HTTP 409: moved_needs_confirmed"
            if status == 5 and not revert_ok:
                return None, "HTTP 500"
            st[(int(cid), int(uid))] = status
            return {"ok": True}, None

        def update_times(ss, cid, rows):
            calls.append(("times", cid, rows))
            return ({"rows": [{"user_id": rows[0]["user_id"], "ok": True}]}, None) if times_ok else (None, "HTTP 500")

        ss = FakeSS(refuse)
        real_get = ss.get

        def get(url, **kw):
            r = real_get(url, **kw)
            if not refuse and confirm_takes:
                st[(SX, 80001)] = 5
            return r
        ss.get = get
        with mock.patch.multiple(A, ss_get_call_times=call_times, ss_update_crew_status=update_status,
                                 ss_update_call_times=update_times):
            out = A.checkin_add_crew(ss, SX, 80001, from_id, {"on": "10:00", "off": "15:00"})
        return out, calls, ss.urls, st

    def test_moved_happy_path_14_then_confirm_then_times(self):
        out, calls, urls, st = self.run_add({(LI, 80001): 5})
        self.assertEqual((out["ok"], out["booked"], out["moved"], out["times_written"]), (True, True, True, True))
        self.assertEqual(calls[0], ("status", LI, 14))
        self.assertEqual(calls[1][0], "times")
        self.assertEqual(calls[1][2], [{"on": "10:00", "off": "15:00", "user_id": 80001}])
        self.assertEqual(len(urls), 1)
        self.assertIn("action=confcrew&id=%d" % SX, urls[0])
        self.assertIn("addtocalls=%d" % SX, urls[0])           # this call only
        self.assertNotIn(",", urls[0].split("addtocalls=")[1])
        self.assertEqual(st[(LI, 80001)], 14)

    def test_called_in_happy_path_touches_no_other_call(self):
        out, calls, urls, st = self.run_add({}, from_id=None)
        self.assertTrue(out["ok"])
        self.assertFalse(out["moved"])
        self.assertEqual([c[0] for c in calls], ["times"])

    def test_already_confirmed_here_is_not_re_added_but_the_old_call_still_moves(self):
        out, calls, urls, st = self.run_add({(SX, 80001): 5, (LI, 80001): 5})
        self.assertTrue(out["ok"])
        self.assertEqual(urls, [])                              # addToCall would wipe the row
        self.assertEqual([(c[0], c[1], c[2]) for c in calls[:1]], [("status", LI, 14)])
        self.assertEqual([c[0] for c in calls], ["status", "times"])
        self.assertEqual(st[(LI, 80001)], 14)                   # never Confirmed on both

    def test_refused_booking_puts_the_old_call_back(self):
        out, calls, urls, st = self.run_add({(LI, 80001): 5}, refuse=True)
        self.assertEqual((out["ok"], out["booked"], out["moved"], out["step"]), (False, False, False, "book"))
        self.assertIn("clash", out["error"])
        self.assertEqual([(c[0], c[2]) for c in calls], [("status", 14), ("status", 5)])
        self.assertEqual(st[(LI, 80001)], 5)

    def test_a_failed_put_back_says_so_and_leaves_moved_true(self):
        out, calls, urls, st = self.run_add({(LI, 80001): 5}, refuse=True, revert_ok=False)
        self.assertFalse(out["ok"])
        self.assertTrue(out["moved"])
        self.assertIn("by hand", out["error"])

    def test_read_back_not_confirmed_is_a_failure_and_rolls_back(self):
        out, calls, urls, st = self.run_add({(LI, 80001): 5}, confirm_takes=False)
        self.assertEqual((out["ok"], out["step"]), (False, "book"))
        self.assertIn("not Confirmed", out["error"])
        self.assertEqual(st[(LI, 80001)], 5)

    def test_not_confirmed_on_the_old_call_changes_nothing(self):
        out, calls, urls, st = self.run_add({(LI, 80001): 6})
        self.assertEqual((out["ok"], out["step"]), (False, "move"))
        self.assertEqual(calls, [])
        self.assertEqual(urls, [])

    def test_old_call_already_moved_is_a_retry_and_carries_on(self):
        out, calls, urls, st = self.run_add({(LI, 80001): 14})
        self.assertTrue(out["ok"])
        self.assertFalse(out["moved"])                          # this run didn't move them
        self.assertEqual([c[0] for c in calls], ["times"])

    def test_move_refused_by_smartstaff_stops_before_booking(self):
        out, calls, urls, st = self.run_add({(LI, 80001): 5}, status_ok=False)
        self.assertEqual((out["ok"], out["step"]), (False, "move"))
        self.assertEqual(urls, [])

    def test_times_failure_leaves_them_booked_and_says_so(self):
        out, calls, urls, st = self.run_add({(LI, 80001): 5}, times_ok=False)
        self.assertEqual((out["ok"], out["booked"], out["moved"], out["step"]), (False, True, True, "times"))
        self.assertIn("import again", out["error"])
        self.assertEqual(st[(LI, 80001)], 14)                   # NOT rolled back: they are booked here


class ReviewScreen(unittest.TestCase):

    JS = H.extract("_tiAddedOn", "_tiAddedRows", "tiToggleAdded")

    def test_added_rows_start_unticked_and_ticking_includes_the_call(self):
        out = H.run(self.JS + """
var _tiState = {include:{}, rowOnAdded:{}};
function renderTimesheetImportPreview(){}
const c = {call_id: 9, checkin_added: [{user_id:1, writable:true}, {user_id:2, writable:false}]};
const a = [_tiAddedRows(_tiState, c).length];
tiToggleAdded(9, 1, {checked:true});
tiToggleAdded(9, 2, {checked:true});
a.push(_tiAddedRows(_tiState, c).map(function(x){ return x.user_id; }));
a.push(_tiState.include[9] === true);
tiToggleAdded(9, 1, {checked:false});
a.push(_tiAddedRows(_tiState, c).length);
console.log(JSON.stringify(a));
""")
        self.assertEqual(out, [0, [1], True, 0])


if __name__ == "__main__":
    unittest.main()

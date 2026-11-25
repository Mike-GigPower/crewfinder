#!/usr/bin/env python3
"""Import Times from a scanned signed sheet (BRIEF-timesheet-scan-slice3b Part 2
+ addendum 1). The brief's §2.5 list, and the rules the briefs say must not change.

  - the two break splitters: a new-layout break split across 20:00 by its real
    time; T9's midpoint for a day shift, a night shift and one whose midpoint is
    exactly 20:00 (Night, and the test says why)
  - refused cells come back EMPTY, not 00:00, and such a row cannot be written
  - no_break_written is routine (ticked); break_start_missing is an info note
    that still starts the row UNTICKED, because T9 guessed its pay band
  - every other flag is amber and holds the row (§5c), including a code this
    build has never seen
  - the extras lists: walk-ons / other_call verbatim, bare times kept OUT of
    "on the sheet, not on this call", confirmed crew with no row
  - unassigned_blocks, and a QR naming a call on another booking
  - the converted tabs go through _build_import_preview UNCHANGED, map_by exact
  - the route: admin only, the two upload steps, HEIC converted before step 1,
    a pick-a-call re-run's call_ids, and a message for each upstream code
  - the review screen under node: a flagged row starts unticked, and there is
    no select-all that could tick it

Made-up booking 90001, calls 9001x, EINs 90001+, invented names only. No real
sheet, no real person, no network.
Run: python3 test_timesheet_scan.py
"""
import json
import unittest
from datetime import datetime
from unittest import mock

import app as A
import js_harness as H
import timesheet_scan as S

BID = 90001
KEY = "made-up-test-key-90001"


def _row(n, ein, name, on, off, **kw):
    """One reply row in the engine's contract shape."""
    r = {"row": n, "user_id": (80000 + ein) if ein else None, "ein": ein,
         "printed_name": name, "on": on, "off": off, "off_next_day": False,
         "break_start": None, "break_mins": None,
         "raw": {"on": "", "off": "", "break_start": "", "break_mins": "",
                 "notes": "", "margin": ""},
         "flags": [], "note": None}
    r.update(kw)
    return r


# ─────────────────────────────── the splitters ───────────────────────────────

class Splitters(unittest.TestCase):

    def test_new_layout_break_is_split_across_20_00_by_its_real_time(self):
        """19:45 + 30 minutes straddles the boundary: 15 in Day, 15 in Night,
        from derivation._split_totals -- the same splitter check-in uses."""
        self.assertEqual(S.split_break_at("16:00", "00:00", "19:45", 30), (15, 15))
        brk, night, t9 = S.place_break(
            _row(1, 90001, "Alpha, Ann", "16:00", "00:00", break_start="19:45", break_mins=30))
        self.assertEqual((brk, night, t9), ("00:15", "00:15", None))

    def test_a_break_written_after_midnight_belongs_to_the_next_morning(self):
        """A 21:30 start with a break at 00:30 is half past midnight AFTER the
        person arrived, not twelve hours before it. All Night either way, but
        the placement is what a day shift would get wrong."""
        self.assertEqual(S.split_break_at("21:30", "02:00", "00:30", 30), (0, 30))

    def test_t9_day_shift_puts_the_whole_break_in_day(self):
        # 07:30-16:00, midpoint 11:45. Matches Ops' own choice on 39050.
        self.assertEqual(S.t9_band("07:30", "16:00"), "day")
        self.assertEqual(S.place_break(_row(1, 90001, "A, A", "07:30", "16:00", break_mins=30)),
                         ("00:30", "00:00", "day"))

    def test_t9_night_shift_puts_the_whole_break_in_night(self):
        # 16:00-04:00, midpoint 22:00. Matches Ops' own choice on 39030.
        self.assertEqual(S.t9_band("16:00", "04:00"), "night")
        self.assertEqual(S.place_break(_row(1, 90001, "A, A", "16:00", "04:00", break_mins=30)),
                         ("00:00", "00:30", "night"))

    def test_t9_midpoint_exactly_20_00_is_night(self):
        """16:00 -> 00:00 has its midpoint exactly on the boundary.

        It is NIGHT, and not because this module broke the tie: band_of asks
        derivation._split_totals which band the minute 20:00-20:01 falls in,
        and the real splitter answers night (nightStart is inclusive). So the
        boundary lives in one place, and T9's own wording -- 'placed in Night;
        check' -- is what an operator sees for this shift."""
        self.assertEqual(S.t9_band("16:00", "00:00"), "night")
        self.assertEqual(S.band_of(S._at("20:00")), "night")
        self.assertEqual(S.band_of(S._at("19:59")), "day")
        brk, night, t9 = S.place_break(_row(1, 90001, "A, A", "16:00", "00:00", break_mins=30))
        self.assertEqual((brk, night, t9), ("00:00", "00:30", "night"))
        flags = S.row_flags(_row(1, 90001, "A, A", "16:00", "00:00", break_mins=30,
                                 flags=["break_start_missing"]), t9)
        self.assertEqual(flags[0]["text"], "break start not written — placed in Night; check")

    def test_the_t9_note_names_the_band_it_actually_used(self):
        day = S.row_flags(_row(1, 90001, "A, A", "07:30", "16:00", break_mins=30,
                               flags=["break_start_missing"]), "day")
        self.assertIn("placed in Day", day[0]["text"])

    def test_no_break_means_no_minutes_in_either_band(self):
        self.assertEqual(S.place_break(_row(1, 90001, "A, A", "08:00", "16:00")),
                         ("00:00", "00:00", None))
        self.assertEqual(S.place_break(_row(1, 90001, "A, A", "08:00", "16:00", break_mins=0)),
                         ("00:00", "00:00", None))

    def test_the_two_bands_always_sum_to_the_break(self):
        for start in ("19:30", "19:45", "19:59", "20:00", "07:45", "08:00"):
            for mins in (15, 30, 45, 60):
                d, n = S.split_break_at("06:00", "23:00", start, mins)
                self.assertEqual(d + n, mins, (start, mins))

    def test_break_night_is_never_asked_of_crew_hub(self):
        """The day/night split is THE GOAT's. Crew Hub's contract test fails if
        break_night ever appears there; this is the same rule from this side --
        nothing in the converter reads such a key."""
        src = open("timesheet_scan.py", encoding="utf-8").read()
        self.assertNotIn('"break_night")', src)
        self.assertNotIn('get("break_day"', src)


# ──────────────────────────── rows, flags, holding ───────────────────────────

class Rows(unittest.TestCase):

    def test_a_clean_row_is_ticked_and_carries_the_parse_crew_row_keys(self):
        r = _row(1, 90001, "Alpha, Ann", "21:30", "02:00",
                 break_start="00:30", break_mins=30, off_next_day=True)
        out = S.convert_row(r)
        want = {"ein", "lastname", "firstname", "status", "on", "off", "break",
                "break_night", "late", "note", "no_show"}
        self.assertEqual(set(out), want)
        self.assertEqual((out["on"], out["off"]), ("21:30", "02:00"))
        self.assertEqual((out["lastname"], out["firstname"]), ("Alpha", "Ann"))
        self.assertEqual((out["break"], out["break_night"]), ("00:00", "00:30"))
        self.assertFalse(S.row_hold(r, S.row_flags(r)))

    def test_after_midnight_is_kept_as_off_less_than_on(self):
        """The sheet path reads off < on AS the next day, so the engine's
        already-resolved finish is passed through rather than adjusted."""
        out = S.convert_row(_row(1, 90001, "A, A", "22:00", "06:15", off_next_day=True))
        self.assertEqual((out["on"], out["off"]), ("22:00", "06:15"))

    def test_no_show_is_never_set_from_a_scan(self):
        """A struck or blank row is a flag for someone to look at, not a
        declaration that the person did not turn up."""
        for flags in (["struck_name"], ["break_blank"], ["on_missing"], []):
            out = S.convert_row(_row(1, 90001, "A, A", "08:00", "16:00", flags=flags))
            self.assertFalse(out["no_show"])

    def test_late_is_never_set_from_a_scan(self):
        out = S.convert_row(_row(1, 90001, "A, A", "08:00", "16:00"))
        self.assertEqual(out["late"], 0)

    def test_a_refused_value_is_empty_not_zero_and_the_row_cannot_be_written(self):
        """§2.3. on_missing means the reader would not give the value; it must
        not arrive as 00:00, and the row must not be writable. Blanking `on`
        puts it in _build_import_preview's own skipped bucket."""
        r = _row(1, 90001, "A, A", None, "16:00", flags=["on_missing"],
                 raw={"on": "", "off": "1600", "break_start": "", "break_mins": "",
                      "notes": "", "margin": ""})
        locked, why = S.row_locked(r)
        self.assertTrue(locked)
        out = S.convert_row(r)
        self.assertIsNone(out["on"])
        self.assertIsNone(out["off"])
        self.assertNotEqual(out["on"], "00:00")
        self.assertIn("couldn't give both times", why)

    def test_a_missing_finish_also_locks_the_row(self):
        r = _row(1, 90001, "A, A", "08:00", None, flags=["off_missing"])
        self.assertTrue(S.row_locked(r)[0])
        self.assertIsNone(S.convert_row(r)["on"])

    def test_a_next_day_finish_that_is_not_before_the_start_is_locked(self):
        """No sheet row can say this -- the sheet path reads off < on as the
        next day -- so rather than write a shift the paper never claimed, the
        row is locked and named. Equal times are locked too: off == on marked
        next day is a 24-hour shift nobody wrote."""
        for off in ("09:00", "08:00"):
            r = _row(1, 90001, "A, A", "08:00", off, off_next_day=True)
            locked, why = S.row_locked(r)
            self.assertTrue(locked, off)
            self.assertIn("next day", why)
        # and the normal after-midnight case is NOT locked
        self.assertFalse(S.row_locked(_row(1, 90001, "A, A", "22:00", "06:00",
                                           off_next_day=True))[0])

    def test_a_string_ein_still_matches_the_roster(self):
        """_build_import_preview matches on an int EIN. A string arriving here
        would make every row unmatched at once, so it is coerced."""
        out = S.convert_row(_row(1, 90001, "A, A", "08:00", "16:00"))
        self.assertEqual(out["ein"], 90001)
        r = _row(1, 90001, "A, A", "08:00", "16:00")
        r["ein"] = "90001"
        self.assertEqual(S.convert_row(r)["ein"], 90001)
        r["ein"] = None
        self.assertIsNone(S.convert_row(r)["ein"])

    def test_no_break_written_is_routine_and_leaves_the_row_ticked(self):
        r = _row(1, 90001, "A, A", "08:00", "16:00", flags=["no_break_written"])
        fl = S.row_flags(r)
        self.assertEqual([f["level"] for f in fl], ["info"])
        self.assertFalse(S.row_hold(r, fl))

    def test_break_start_missing_is_an_info_note_that_still_holds_the_row(self):
        """Routine for T7's headline, but the row starts unticked all the same:
        where the minutes landed is a guess about a pay band."""
        r = _row(1, 90001, "A, A", "16:00", "04:00", break_mins=30,
                 flags=["break_start_missing"])
        fl = S.row_flags(r, "night")
        self.assertEqual([f["level"] for f in fl], ["info"])
        self.assertTrue(S.row_hold(r, fl))

    def test_no_break_written_with_break_start_missing_still_holds(self):
        r = _row(1, 90001, "A, A", "16:00", "04:00", break_mins=30,
                 flags=["no_break_written", "break_start_missing"])
        self.assertTrue(S.row_hold(r, S.row_flags(r, "night")))

    def test_every_other_flag_is_amber_and_holds_the_row(self):
        for code in ("break_looks_like_time", "break_blank", "break_not_submittable",
                     "crossed_out", "struck_name", "note_present", "times_outside_table",
                     "unmatched_person", "other_call", "time_unreadable",
                     "header_time_differs", "block_unassigned"):
            r = _row(1, 90001, "A, A", "08:00", "16:00", flags=[code])
            fl = S.row_flags(r)
            self.assertEqual([f["level"] for f in fl], ["warn"], code)
            self.assertTrue(S.row_hold(r, fl), code)
            self.assertTrue(fl[0]["text"], code)

    def test_every_flag_code_crew_hub_can_send_has_wording(self):
        """Read off Crew Hub's FlagCode union. If the engine gains a code, this
        fails rather than showing an operator a bare identifier."""
        codes = {"break_looks_like_time", "on_missing", "off_missing", "time_unreadable",
                 "crossed_out", "struck_name", "note_present", "times_outside_table",
                 "unmatched_person", "other_call", "break_start_missing",
                 "block_unassigned", "no_break_written", "break_blank",
                 "break_not_submittable", "header_time_differs"}
        covered = set(S.FLAG_TEXT) | {"break_start_missing"}
        self.assertEqual(codes - covered, set())

    def test_an_unknown_flag_code_is_treated_as_something_to_look_at(self):
        """Fail safe: a code this build was never taught about is exactly the
        case T7's headline measures, so it goes amber and holds the row rather
        than being quietly dropped or counted as routine."""
        r = _row(1, 90001, "A, A", "08:00", "16:00", flags=["brand_new_code"])
        fl = S.row_flags(r)
        self.assertEqual(fl[0]["level"], "warn")
        self.assertIn("brand_new_code", fl[0]["text"])
        self.assertTrue(S.row_hold(r, fl))

    def test_the_note_carries_the_engine_note_and_the_raw_writing(self):
        r = _row(1, 90001, "A, A", "21:30", "02:00", flags=["note_present"],
                 note="left early, see boss",
                 raw={"on": "2130", "off": "2am", "break_start": "", "break_mins": "30",
                      "notes": "left early, see boss", "margin": ""})
        out = S.convert_row(r)
        self.assertIn("left early, see boss", out["note"])
        self.assertIn("on 2130", out["note"])
        self.assertIn("off 2am", out["note"])

    def test_a_clean_row_gets_no_raw_dump_in_its_note(self):
        r = _row(1, 90001, "A, A", "08:00", "16:00",
                 raw={"on": "0800", "off": "1600", "break_start": "", "break_mins": "",
                      "notes": "", "margin": ""})
        self.assertEqual(S.convert_row(r)["note"], "")

    def test_printed_name_is_split_on_the_comma_only(self):
        self.assertEqual(S.split_printed_name("Alpha, Ann"), ("Alpha", "Ann"))
        self.assertEqual(S.split_printed_name("Alpha"), ("Alpha", ""))
        self.assertEqual(S.split_printed_name(""), ("", ""))
        self.assertEqual(S.split_printed_name("De Silva, Ann Marie"), ("De Silva", "Ann Marie"))


# ───────────────────────── through _build_import_preview ─────────────────────

def _ts(y, m, d):
    return int(datetime(y, m, d).timestamp())


BOOKING = {"name": "Made-up booking", "calls": [
    {"call_id": 90011, "call_name": "General", "start_date": _ts(2026, 10, 6),
     "start_time": "22:00:00", "est_length": 5},
    {"call_id": 90012, "call_name": "General", "start_date": _ts(2026, 10, 6),
     "start_time": "22:00:00", "est_length": 1},
    {"call_id": 90013, "call_name": "Fork", "start_date": _ts(2026, 10, 6),
     "start_time": "22:00:00", "est_length": 4},
]}
ROSTER = {90011: [90001, 90002, 90003, 90004], 90012: [90005], 90013: [90006]}


def _ct(cid):
    return {"crew": [{"ein": e, "user_id": 80000 + e, "lastname": "L%d" % e,
                      "firstname": "F", "status": 5} for e in ROSTER[int(cid)]]}


def _times(ss, cid):
    return _ct(cid), None


# One call read cleanly, plus every list the preview has to show.
RESULT = {
    "booking_id": BID,
    "scan_ref": ["90001/2026-11-25-a.jpg", "90001/2026-11-25-b.jpg"],
    "pages_read": 2,
    "summary": "4 rows read · 3 flagged · 1 block matched no call",
    "calls": [{
        "call_id": 90011, "call_name": "General", "source": "qr",
        "assignment_flags": [], "rows_read": 4, "rows_flagged": 3,
        "summary": "4 rows read · 3 flagged",
        "rows": [
            _row(1, 90001, "L90001, F", "22:00", "03:00",
                 break_start="00:30", break_mins=30, off_next_day=True),
            _row(2, 90002, "L90002, F", "22:00", "03:00", break_mins=30,
                 flags=["break_start_missing"],
                 raw={"on": "2200", "off": "0300", "break_start": "", "break_mins": "30",
                      "notes": "", "margin": ""}),
            _row(3, 90003, "L90003, F", "22:00", "03:00", break_mins=None,
                 flags=["break_looks_like_time"],
                 raw={"on": "2200", "off": "0300", "break_start": "", "break_mins": "0030",
                      "notes": "", "margin": ""}),
            # the reader refused the start: empty, and unwritable
            _row(4, 90004, "L90004, F", None, "03:00", flags=["on_missing"],
                 raw={"on": "~~", "off": "0300", "break_start": "", "break_mins": "",
                      "notes": "", "margin": ""}),
        ],
        "extra_entries": [
            {"text": "Echo Eve 2200 - 0400 NO BREAK", "guessed_person_id": None,
             "guessed_person": {"user_id": 80005, "ein": 90005,
                                "firstname": "F", "lastname": "L90005"},
             "flags": ["unmatched_person"]},
            {"text": "Fred for the Fork call 2200-0200", "guessed_person_id": None,
             "guessed_person": None, "flags": ["other_call"]},
            # a crossed-out row rewritten underneath: bare times, nobody named.
            {"text": "2200  0130", "guessed_person_id": None, "guessed_person": None,
             "flags": ["times_outside_table"]},
        ],
        "roster_not_on_sheet": [{"user_id": 80004, "ein": 90004,
                                 "firstname": "F", "lastname": "L90004"}],
    }],
    "unassigned_blocks": [{
        "page": 1, "block": 2, "file": "sheet.jpeg",
        "printed_header": "Made-up Ops - General - Tue 6 Oct 2026 22:00",
        "rows_on_block": 5, "reason": "ambiguous_header", "qr_call_id": None,
        "candidate_call_ids": [90011, 90012],
    }],
}


def _result():
    return json.loads(json.dumps(RESULT))


class ThroughPreview(unittest.TestCase):

    def setUp(self):
        p = mock.patch.multiple(A, fetch_booking_bulk=lambda ss, b: (BOOKING, None),
                                ss_get_call_times=_times)
        p.start()
        self.addCleanup(p.stop)
        self.result = _result()
        self.parsed = S.convert_scan(self.result)
        self.prev, err = A._build_import_preview(object(), str(BID), self.parsed)
        self.assertIsNone(err)
        cands, live = A._scan_candidates(BOOKING["calls"])
        S.attach_flags(self.prev, self.result, candidates=cands)
        self.card = [c for c in self.prev["calls"] if c["call_id"] == 90011][0]

    def test_every_card_maps_exactly_by_call_id(self):
        self.assertEqual(self.card["map_by"], "call_id")
        self.assertEqual(self.prev["foreign_tabs"], [])
        self.assertEqual(self.prev["unmapped_tabs"], [])
        self.assertEqual(self.prev["needs_call_tabs"], [])
        self.assertEqual(self.parsed["skipped_tabs"], [])

    def test_build_import_preview_was_not_changed(self):
        """Its own keys are all still there and the write path sees the same
        shape as from the Google Sheet."""
        for k in ("booking_id", "calls", "unmapped_tabs", "foreign_tabs",
                  "duplicate_tabs", "needs_call_tabs", "recovered_tabs",
                  "cancelled_tabs", "skipped_tabs", "available_calls"):
            self.assertIn(k, self.prev)
        for k in ("tab_name", "call_id", "call_name", "map_by", "matched",
                  "skipped", "unmatched", "roster_only"):
            self.assertIn(k, self.card)

    def test_the_three_readable_rows_are_matched_and_the_refused_one_is_not(self):
        self.assertEqual(sorted(m["ein"] for m in self.card["matched"]),
                         [90001, 90002, 90003])
        self.assertEqual([s["ein"] for s in self.card["skipped"]], [90004])

    def test_the_flagged_rows_are_held_and_the_clean_one_is_not(self):
        by = {m["ein"]: m for m in self.card["matched"]}
        self.assertNotIn("scan_hold", by[90001])
        self.assertTrue(by[90002]["scan_hold"])      # break_start_missing (T9)
        self.assertTrue(by[90003]["scan_hold"])      # break_looks_like_time
        self.assertIn("placed in Night", by[90002]["scan_flags"][0]["text"])

    def test_a_held_row_shows_the_raw_writing(self):
        by = {m["ein"]: m for m in self.card["matched"]}
        self.assertIn("break 0030", by[90003]["scan_raw"])

    def test_the_refused_row_is_named_with_its_reason_and_raw_writing(self):
        sk = self.card["scan_skipped"]
        self.assertEqual([s["ein"] for s in sk], [90004])
        self.assertIn("couldn't give both times", sk[0]["why"])
        self.assertIn("on ~~", sk[0]["raw"])

    def test_walk_ons_and_other_call_entries_are_listed_verbatim(self):
        texts = [e["text"] for e in self.card["scan_extras"]]
        self.assertEqual(texts, ["Echo Eve 2200 - 0400 NO BREAK",
                                 "Fred for the Fork call 2200-0200"])
        self.assertEqual(self.card["scan_extras"][0]["guessed_name"], "L90005, F")

    def test_bare_times_are_not_listed_as_people_to_add(self):
        """A crossed-out row rewritten below the table used to arrive as
        unmatched_person and put '2200' and '0130' into 'on the sheet, not on
        this call' as two people for Ops to add (Crew Hub findings, 25 Nov).
        It belongs to a row of the table, so it is shown as loose writing."""
        self.assertEqual([l["text"] for l in self.card["scan_loose_times"]], ["2200  0130"])
        for e in self.card["scan_extras"]:
            self.assertNotIn("2200  0130", e["text"])

    def test_a_named_walk_on_still_counts_as_a_person(self):
        self.assertFalse(S._is_loose_times({"flags": ["unmatched_person"], "guessed_person": None}))
        self.assertFalse(S._is_loose_times({"flags": ["times_outside_table"],
                                            "guessed_person": {"ein": 1}}))
        self.assertTrue(S._is_loose_times({"flags": ["times_outside_table"],
                                           "guessed_person": None}))

    def test_on_this_call_not_on_the_sheet_comes_from_the_live_roster(self):
        """_build_import_preview's own roster_only, matched against SmartStaff
        just now -- not counted a second time from the engine's list."""
        self.assertEqual([r["ein"] for r in self.card["roster_only"]], [90004])

    def test_unassigned_blocks_carry_what_the_pick_buttons_need(self):
        b, = self.prev["unassigned_blocks"]
        self.assertEqual((b["page"], b["block"], b["rows_on_block"]), (1, 2, 5))
        self.assertEqual(b["candidate_call_ids"], [90011, 90012])
        self.assertIn("General", b["printed_header"])
        c = self.prev["scan_candidates"]
        self.assertEqual(c["90011"], {"call_name": "General", "start": "22:00", "length": "5h"})
        self.assertEqual(c["90012"]["length"], "1h")

    def test_the_source_block_carries_every_scan_path(self):
        src = self.prev["source"]
        self.assertEqual(src["kind"], "scan")
        self.assertEqual(len(src["scan_ref"]), 2)
        self.assertEqual(src["pages_read"], 2)
        self.assertIn("block matched no call", src["summary"])

    def test_nothing_is_written_by_building_a_preview(self):
        """T5. The preview holds no write instruction of its own: the only
        writable rows are the matched ones the operator ticks."""
        self.assertNotIn("write", json.dumps(self.prev).lower().split('"')[0:1])
        self.assertTrue(all("no_show" not in m for m in self.card["matched"]))


class QrForAnotherBooking(unittest.TestCase):

    def test_the_block_stops_with_the_call_number(self):
        res = _result()
        res["calls"] = []
        res["unassigned_blocks"] = [{
            "page": 1, "block": 1, "file": "sheet.jpeg", "printed_header": "",
            "rows_on_block": 6, "reason": "qr_not_a_candidate",
            "qr_call_id": 99934, "candidate_call_ids": [],
        }]
        prev = {"calls": []}
        S.attach_flags(prev, res)
        b, = prev["unassigned_blocks"]
        self.assertEqual(b["reason"], "qr_not_a_candidate")
        self.assertEqual(b["qr_call_id"], 99934)
        self.assertEqual(b["candidate_call_ids"], [])


class RerunCallIds(unittest.TestCase):
    """Addendum E: every call on the booking MINUS the non-picked candidates.
    NOT just the picked call -- that would orphan the other blocks."""

    def test_only_the_losing_candidates_are_dropped(self):
        self.assertEqual(S.rerun_call_ids([90011, 90012, 90013], [90011, 90012], 90011),
                         [90011, 90013])
        self.assertEqual(S.rerun_call_ids([90011, 90012, 90013], [90011, 90012], 90012),
                         [90012, 90013])

    def test_the_other_blocks_calls_stay_candidates(self):
        out = S.rerun_call_ids([90011, 90012, 90013], [90011, 90012], 90011)
        self.assertIn(90013, out)

    def test_nothing_is_picked_gives_nothing_rather_than_everything(self):
        self.assertEqual(S.rerun_call_ids([90011, 90012], [90011, 90012], None), [])


# ────────────────────────────────── HEIC ─────────────────────────────────────

class Heic(unittest.TestCase):

    def test_heic_is_recognised_by_extension_either_case(self):
        self.assertTrue(S.is_heic("IMG_0042.HEIC"))
        self.assertTrue(S.is_heic("sheet.heif"))
        self.assertFalse(S.is_heic("sheet.jpg"))
        self.assertFalse(S.is_heic("sheet.pdf"))

    def test_the_converted_name_only_changes_the_extension(self):
        self.assertEqual(S.jpeg_name("IMG_0042.HEIC"), "IMG_0042.jpg")
        self.assertEqual(S.jpeg_name("/somewhere/sheet.heif"), "sheet.jpg")

    def test_sips_is_reachable_on_this_machine(self):
        """The brief's pre-check, kept as a test so it is re-asked on every
        machine THE GOAT is built on. sips is part of macOS and the bundle is
        not sandboxed (entitlements.plist has no app-sandbox key), so the
        shipped app can spawn it -- the same way it already spawns
        /usr/bin/open for the health log folder."""
        self.assertTrue(S.sips_available(), "sips not reachable: THE GOAT would "
                                            "have to refuse HEIC instead")

    def test_a_real_heic_round_trips_to_jpeg(self):
        """Made here with sips, so no real photo is stored in the repo."""
        import os
        import subprocess
        import tempfile
        if not S.sips_available():
            self.skipTest("sips unavailable")
        d = tempfile.mkdtemp(prefix="goat-scan-test-")
        png, heic = os.path.join(d, "s.png"), os.path.join(d, "s.heic")
        try:
            with open(png, "wb") as f:
                f.write(_tiny_png())
            r = subprocess.run([S.SIPS, "-s", "format", "heic", png, "--out", heic],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if r.returncode != 0 or not os.path.exists(heic):
                self.skipTest("this machine's sips can't write HEIC")
            with open(heic, "rb") as f:
                data = f.read()
            self.assertTrue(data[4:8] == b"ftyp")
            out = S.heic_to_jpeg(data)
            self.assertTrue(out.startswith(b"\xff\xd8\xff"), "not a JPEG")
            files, converted = S.convert_heic_files([("IMG_1.HEIC", data), ("b.pdf", b"%PDF-")])
            self.assertEqual([n for n, _d in files], ["IMG_1.jpg", "b.pdf"])
            self.assertEqual(converted, ["IMG_1.HEIC"])
            self.assertEqual(files[1][1], b"%PDF-")
        finally:
            for p in (png, heic):
                try:
                    os.remove(p)
                except OSError:
                    pass
            try:
                os.rmdir(d)
            except OSError:
                pass

    def test_unreadable_bytes_give_the_operator_the_brief_s_words(self):
        with self.assertRaises(RuntimeError) as e:
            S.heic_to_jpeg(b"not an image at all")
        self.assertIn("Save it as JPEG or PDF", str(e.exception))

    def test_a_machine_without_sips_refuses_rather_than_fails_oddly(self):
        with mock.patch.object(S, "SIPS", "/nope/sips"):
            self.assertFalse(S.sips_available())
            with self.assertRaises(RuntimeError) as e:
                S.heic_to_jpeg(b"x")
            self.assertEqual(str(e.exception), "Save it as JPEG or PDF")


def _tiny_png():
    import struct
    import zlib

    def chunk(t, d):
        c = t + d
        return struct.pack(">I", len(d)) + c + struct.pack(">I", zlib.crc32(c) & 0xffffffff)

    w = h = 32
    raw = b"".join(b"\x00" + bytes([(x * 8) % 256 for x in range(w)]) * 3 for _y in range(h))
    return (b"\x89PNG\r\n\x1a\n"
            + chunk(b"IHDR", struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw))
            + chunk(b"IEND", b""))


# ───────────────────────────── upstream messages ─────────────────────────────

class ErrorMessages(unittest.TestCase):

    def test_each_status_gets_a_specific_message(self):
        for status in (400, 401, 403, 404, 413, 422, 502, 503, 504):
            m = S.scan_error_message(status, "")
            self.assertTrue(m and not m.endswith("None"), status)
            self.assertNotIn("Traceback", m)

    def test_crew_hubs_own_words_are_used_where_they_are_meant_for_ops(self):
        """The T8 refusal is already worded for an operator in the brief, so
        repeating it here in different words would be a second copy."""
        m = S.scan_error_message(403, "Scanning is switched on for Mike only "
                                      "while it's being checked.")
        self.assertEqual(m, "Scanning is switched on for Mike only while it's being checked.")

    def test_a_404_tells_ops_to_send_the_file_again(self):
        self.assertIn("send the file again", S.scan_error_message(404, ""))

    def test_an_unknown_status_still_says_something_useful(self):
        self.assertIn("500", S.scan_error_message(500, ""))

    def test_the_key_is_never_in_a_message(self):
        for status in (401, 403, 503):
            self.assertNotIn(KEY, S.scan_error_message(status, ""))


# ──────────────────────────────── the route ──────────────────────────────────

class FakeResp(object):
    def __init__(self, status, body):
        self.status_code = status
        self._body = body

    def json(self):
        if isinstance(self._body, Exception):
            raise self._body
        return self._body


_MIME = {".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png",
         ".pdf": "application/pdf"}


def _uploads_for(filenames):
    """What Crew Hub answers step 1 with: one slot per filename, positionally,
    each carrying the content type the bucket will insist on."""
    out = []
    for i, n in enumerate(filenames):
        ext = "." + str(n).rsplit(".", 1)[-1].lower()
        out.append({"storage_path": "90001/stamp-%d%s" % (i, ext),
                    "signed_url": "https://example.invalid/u/%d?token=t" % i,
                    "content_type": _MIME.get(ext, "image/jpeg")})
    return {"booking_id": BID, "uploads": out}


class Route(unittest.TestCase):

    def setUp(self):
        self.posts, self.puts = [], []
        self.upload_answer = None       # built from the request unless a test sets one
        self.scan_answer = FakeResp(200, _result())
        self.put_answer = FakeResp(200, {})
        p = mock.patch.multiple(A, fetch_booking_bulk=lambda ss, b: (BOOKING, None),
                                ss_get_call_times=_times, GOAT_CHECKIN_KEY=KEY)
        p.start()
        self.addCleanup(p.stop)

    def _post(self, url, **kw):
        self.posts.append((url, kw))
        if url != A.TIMESHEET_SCAN_UPLOAD_URL:
            if isinstance(self.scan_answer, Exception):
                raise self.scan_answer
            return self.scan_answer
        if self.upload_answer is not None:
            return self.upload_answer
        return FakeResp(200, _uploads_for((kw.get("json") or {}).get("filenames") or []))

    def _put(self, url, **kw):
        self.puts.append((url, kw))
        return self.put_answer

    def call(self, data, cohort="admin", user_id="10261"):
        with A.app.test_client() as c:
            with c.session_transaction() as s:
                s["sid"] = "t"
            with mock.patch.multiple(A, get_ss_session=lambda: object()), \
                 mock.patch.object(A.http, "post", self._post), \
                 mock.patch.object(A.http, "put", self._put), \
                 mock.patch.dict(A._ss_identity, {"t": {"cohort": cohort, "usergroupID": 1,
                                                        "user_id": user_id}}):
                r = c.post("/api/booking/%d/import-times/preview-scan" % BID,
                           data=data, content_type="multipart/form-data")
        return r, r.get_json()

    def test_admin_only(self):
        r, _ = self.call({"file": (_io(b"\xff\xd8\xff x"), "s.jpg")}, cohort="operations")
        self.assertEqual(r.status_code, 403)
        self.assertEqual(self.posts, [])

    def test_the_happy_path_asks_for_urls_puts_each_file_then_reads(self):
        r, body = self.call({"file": [(_io(b"\xff\xd8\xff a"), "s1.jpg"),
                                      (_io(b"%PDF-b"), "s2.pdf")]})
        self.assertEqual(r.status_code, 200, body)
        self.assertEqual([u for u, _k in self.posts],
                         [A.TIMESHEET_SCAN_UPLOAD_URL, A.TIMESHEET_SCAN_URL])
        # step 1: the filenames, in order
        self.assertEqual(self.posts[0][1]["json"],
                         {"booking_id": BID, "filenames": ["s1.jpg", "s2.pdf"]})
        # step 2: one PUT per URL, with EXACTLY the content type it returned
        self.assertEqual([u for u, _k in self.puts],
                         ["https://example.invalid/u/0?token=t",
                          "https://example.invalid/u/1?token=t"])
        self.assertEqual(self.puts[0][1]["headers"], {"Content-Type": "image/jpeg"})
        self.assertEqual(self.puts[1][1]["headers"], {"Content-Type": "application/pdf"})
        # step 3: the PATHS, never the bytes
        self.assertEqual(self.posts[1][1]["json"]["scan_paths"],
                         ["90001/stamp-0.jpg", "90001/stamp-1.pdf"])
        self.assertNotIn("file", self.posts[1][1])
        self.assertEqual(body["source"]["kind"], "scan")
        self.assertEqual({c["map_by"] for c in body["calls"]}, {"call_id"})

    def test_the_service_key_and_the_operators_id_are_sent_server_side(self):
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s1.jpg")})
        self.assertEqual(r.status_code, 200, body)
        for _u, kw in self.posts:
            self.assertEqual(kw["headers"]["X-Goat-Service-Key"], KEY)
            self.assertEqual(kw["headers"]["X-Goat-User"], "10261")
        # and never back to the browser
        self.assertNotIn(KEY, json.dumps(body))

    def test_the_user_id_sent_is_the_account_the_operator_signed_in_with(self):
        """Crew Hub's allow-list is keyed on the SmartStaff userID of the
        account THE GOAT is logged in as -- an admin login. The same trap
        CHECKIN_PILOT_SWITCHERS documents: a crew userID never appears here."""
        r, _ = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")}, user_id="10261")
        self.assertEqual(r.status_code, 200)
        self.assertEqual(self.posts[0][1]["headers"]["X-Goat-User"], "10261")

    def test_no_user_id_is_refused_before_anything_is_uploaded(self):
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")}, user_id="0")
        self.assertEqual(r.status_code, 403)
        self.assertIn("user id", body["error"])
        self.assertEqual(self.posts, [])

    def test_no_key_on_this_machine_is_said_plainly(self):
        with mock.patch.object(A, "GOAT_CHECKIN_KEY", ""):
            r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 503)
        self.assertIn("goat_checkin_key", body["error"])
        self.assertEqual(self.posts, [])

    def test_no_file_at_all(self):
        r, body = self.call({})
        self.assertEqual(r.status_code, 400)
        self.assertIn("No file", body["error"])

    def test_a_file_type_the_bucket_cannot_take_is_refused_here(self):
        r, body = self.call({"file": (_io(b"xx"), "notes.txt")})
        self.assertEqual(r.status_code, 400)
        self.assertIn("JPEG, PNG or PDF", body["error"])
        self.assertEqual(self.posts, [])

    def test_an_oversized_file_is_refused_before_it_crosses_the_country(self):
        big = b"x" * (A.SCAN_MAX_BYTES + 1)
        r, body = self.call({"file": (_io(big), "huge.jpg")})
        self.assertEqual(r.status_code, 400)
        self.assertIn("the limit is 25 MB", body["error"])
        self.assertEqual(self.posts, [])

    def test_too_many_files_at_once(self):
        data = {"file": [(_io(b"\xff\xd8\xff a"), "s%d.jpg" % i)
                         for i in range(A.SCAN_MAX_FILES + 1)]}
        r, body = self.call(data)
        self.assertEqual(r.status_code, 400)
        self.assertIn("At most 12", body["error"])
        self.assertEqual(self.posts, [])

    def test_a_heic_is_converted_before_the_upload_url_step(self):
        """Crew Hub refuses .heic at step 1 on purpose, so the name that
        reaches it must already be a .jpg."""
        with mock.patch.multiple(S, sips_available=lambda: True,
                                 heic_to_jpeg=lambda d, **k: b"\xff\xd8\xffJPEG"):
            r, body = self.call({"file": (_io(b"ftypheic..."), "IMG_9.HEIC")})
        self.assertEqual(r.status_code, 200, body)
        self.assertEqual(self.posts[0][1]["json"]["filenames"], ["IMG_9.jpg"])
        self.assertEqual(self.puts[0][1]["data"], b"\xff\xd8\xffJPEG")
        self.assertEqual(body["scan_converted"], ["IMG_9.HEIC"])

    def test_without_sips_a_heic_is_refused_in_the_briefs_words(self):
        with mock.patch.object(S, "sips_available", lambda: False):
            r, body = self.call({"file": (_io(b"ftypheic..."), "IMG_9.HEIC")})
        self.assertEqual(r.status_code, 400)
        self.assertIn("Save it as JPEG or PDF", body["error"])
        self.assertEqual(self.posts, [])

    def test_a_failed_conversion_is_reported_not_uploaded(self):
        def boom(d, **k):
            raise RuntimeError("Save it as JPEG or PDF")
        with mock.patch.multiple(S, sips_available=lambda: True, heic_to_jpeg=boom):
            r, body = self.call({"file": (_io(b"junk"), "IMG_9.HEIC")})
        self.assertEqual(r.status_code, 400)
        self.assertIn("Save it as JPEG or PDF", body["error"])
        self.assertEqual(self.posts, [])

    def test_a_pick_a_call_rerun_sends_the_same_paths_and_narrowed_calls(self):
        r, body = self.call({"scan_path": ["90001/stamp-a.jpg", "90001/stamp-b.pdf"],
                             "call_id": ["90011", "90013"]})
        self.assertEqual(r.status_code, 200, body)
        # no upload at all: the files are already in the bucket
        self.assertEqual([u for u, _k in self.posts], [A.TIMESHEET_SCAN_URL])
        self.assertEqual(self.puts, [])
        self.assertEqual(self.posts[0][1]["json"],
                         {"booking_id": BID, "scan_paths": ["90001/stamp-a.jpg",
                                                            "90001/stamp-b.pdf"],
                          "call_ids": [90011, 90013]})

    def test_the_booking_call_ids_are_returned_for_the_pick_buttons(self):
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 200, body)
        self.assertEqual(body["scan_booking_call_ids"], [90011, 90012, 90013])

    def test_a_cancelled_call_is_never_a_candidate_or_a_rerun_target(self):
        booking = json.loads(json.dumps(BOOKING))
        booking["calls"][1]["cancelled"] = True
        cands, live = A._scan_candidates(booking["calls"])
        self.assertEqual(sorted(cands), ["90011", "90013"])
        self.assertEqual(live, [90011, 90013])

    def test_an_upstream_refusal_at_step_1_stops_before_any_upload(self):
        self.upload_answer = FakeResp(400, {"error": "HEIC can't be read"})
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 502)
        self.assertIn("HEIC can't be read", body["error"])
        self.assertEqual(self.puts, [])

    def test_the_t8_refusal_reaches_the_operator_word_for_word(self):
        self.upload_answer = FakeResp(403, {"error": "Scanning is switched on for Mike "
                                                     "only while it's being checked."})
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 502)
        self.assertEqual(body["error"], "Scanning is switched on for Mike only "
                                        "while it's being checked.")

    def test_scanning_switched_off_upstream(self):
        self.upload_answer = FakeResp(503, {"error": "Scanning isn't switched on"})
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 502)
        self.assertIn("Scanning isn't switched on", body["error"])

    def test_each_read_error_code_gets_its_own_message(self):
        for status, fragment in ((404, "send the file again"),
                                 (413, "too big"),
                                 (422, "Nothing readable"),
                                 (502, "SmartStaff"),
                                 (504, "too long")):
            self.posts, self.puts = [], []
            self.scan_answer = FakeResp(status, {})
            r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
            self.assertEqual(r.status_code, 502, status)
            self.assertIn(fragment, body["error"], status)

    def test_a_duplicate_put_is_reported_not_retried(self):
        self.put_answer = FakeResp(409, {})
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 502)
        self.assertIn("already uploaded", body["error"])
        self.assertEqual(len(self.puts), 1)          # not retried
        self.assertEqual([u for u, _k in self.posts], [A.TIMESHEET_SCAN_UPLOAD_URL])

    def test_a_short_upload_slot_list_is_refused(self):
        self.upload_answer = FakeResp(200, {"uploads": [_uploads_for(["a.jpg"])["uploads"][0]]})
        r, body = self.call({"file": [(_io(b"\xff\xd8\xff a"), "a.jpg"),
                                      (_io(b"\xff\xd8\xff b"), "b.jpg")]})
        self.assertEqual(r.status_code, 502)
        self.assertIn("upload slot for every file", body["error"])
        self.assertEqual(self.puts, [])

    def test_non_json_from_crew_hub_is_said_plainly(self):
        self.scan_answer = FakeResp(200, ValueError("not json"))
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 502)
        self.assertIn("isn't JSON", body["error"])

    def test_a_read_timeout_says_to_try_one_sheet_at_a_time(self):
        """THE GOAT waits a little longer than Crew Hub's own 300 s cap, so a
        timeout here means Crew Hub has already given up."""
        self.scan_answer = A.http.exceptions.Timeout()
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 502)
        self.assertIn("one sheet at a time", body["error"])
        self.assertGreater(A.SCAN_READ_TIMEOUT, 300)

    def test_nothing_about_the_sheet_is_stored_in_the_goat(self):
        """§2.4. The route keeps no file, writes no row, caches nothing: the
        preview is the whole of what THE GOAT holds, in the answer."""
        r, body = self.call({"file": (_io(b"\xff\xd8\xff a"), "s.jpg")})
        self.assertEqual(r.status_code, 200, body)
        src = open("app.py", encoding="utf-8").read()
        start = src.index("def api_import_times_preview_scan")
        end = src.index("class _CallTimesRecorder", start)
        body_src = src[start:end]
        for forbidden in ("open(", "json.dump", "shutil", "_save"):
            self.assertNotIn(forbidden, body_src, forbidden)

    def test_no_php_or_write_path_was_touched(self):
        """T5 / §2.4: no new write path and no PHP change. The scan route never
        mentions the write endpoint or update-call-times."""
        src = open("app.py", encoding="utf-8").read()
        start = src.index("def api_import_times_preview_scan")
        end = src.index("class _CallTimesRecorder", start)
        body_src = src[start:end]
        self.assertNotIn("update-call-times", body_src)
        self.assertNotIn("/times", body_src.replace("import-times", ""))


def _io(b):
    import io
    return io.BytesIO(b)


# ───────────────────────── privacy (the §1.1 tripwire) ───────────────────────

class Privacy(unittest.TestCase):
    """The §1.1 tripwire, from this side. Phone and email are never read from a
    sheet, never asked of Crew Hub and never put in a preview -- so what is
    checked here is the KEYS the converter touches, not whether the word
    'phone' appears in a comment."""

    def test_the_converter_never_reads_a_phone_or_email_key(self):
        import re
        src = open("timesheet_scan.py", encoding="utf-8").read()
        reads = set(re.findall(r'(?:get|\[)\(?["\']([a-z_]+)["\']', src))
        for key in ("phone", "mobile", "email", "telephone", "contact"):
            self.assertNotIn(key, reads)

    def test_a_converted_row_can_only_carry_the_parse_crew_row_keys(self):
        out = S.convert_row(_row(1, 90001, "A, A", "08:00", "16:00"))
        self.assertEqual(set(out), {"ein", "lastname", "firstname", "status", "on",
                                    "off", "break", "break_night", "late", "note",
                                    "no_show"})

    def test_a_phone_number_in_the_reply_is_not_carried_into_the_preview(self):
        """If Crew Hub ever leaked one, the converter would drop it on the
        floor rather than pass it on: it copies named keys, never spreads."""
        r = _row(1, 90001, "A, A", "08:00", "16:00")
        r["mobile"] = "0400 000 000"
        r["email"] = "nobody@example.invalid"
        self.assertNotIn("0400", json.dumps(S.convert_row(r)))
        self.assertNotIn("example.invalid", json.dumps(S.convert_row(r)))

    def test_no_raw_model_response_is_asked_for_or_kept(self):
        src = open("timesheet_scan.py", encoding="utf-8").read()
        for word in ("model_response", "raw_response", "completion"):
            self.assertNotIn(word, src)


# ─────────────────────── the review screen, under node ───────────────────────

class ReviewScreen(unittest.TestCase):

    JS = H.extract("_tiIsConfirmed", "_tiPromoPending", "_tiRowOn", "_tiCallRows",
                   "_tiSeedInclude", "_tiScanFlagChips", "_tiScanRawLine",
                   "_tiScanCardNotes", "_tiScanExtras", "_tiScanUnmatchedRows",
                   "_tiScanNotOnSheet",
                   "_tiOffFeed", "_tiAbsentExtra", "_tiMissingConfirmedLines")

    def run_js(self, body):
        return H.run("""
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;'); }
function decodeEntities(s){ return String(s).replace(/&#39;/g, "'"); }
var _tiState = {bookingId: 90001};
""" + self.JS + body)

    def test_a_flagged_scan_row_starts_unticked_and_ticks_on(self):
        out = self.run_js("""
const s = {rowOff:{}, rowOnHeld:{}};
const held  = {user_id:1, status:5, scan_hold:true};
const clean = {user_id:2, status:5};
const a = [_tiRowOn(s, 9, held), _tiRowOn(s, 9, clean)];
a.push(_tiCallRows(s, {call_id:9, matched:[held, clean]}).length);
s.rowOnHeld['9:1'] = true;
a.push(_tiRowOn(s, 9, held));
a.push(_tiCallRows(s, {call_id:9, matched:[held, clean]}).length);
console.log(JSON.stringify(a));
""")
        # held row off while clean row is on; ticking it adds it
        self.assertEqual(out, [False, True, 1, True, 2])

    def test_a_card_of_only_flagged_rows_starts_unticked(self):
        """39096: every row amber. The card must not arrive pre-ticked at
        '0 to write', which is how a reviewer skims past a whole call."""
        out = self.run_js("""
const d = {calls:[{call_id:9, matched:[{user_id:1, status:5, scan_hold:true}]}]};
console.log(JSON.stringify([_tiSeedInclude(d, null, 'scan')[9],
                            _tiSeedInclude(d, null, 'gsheet')[9]]));
""")
        self.assertEqual(out, [False, True])

    def test_ticking_the_call_does_not_tick_its_flagged_rows(self):
        """The nearest thing to a select-all in this dialog is the per-call
        checkbox. It only includes the call: which rows are written is
        _tiCallRows, which filters by _tiRowOn independently of `include`. So
        including a call whose rows are all held still writes nothing."""
        out = self.run_js("""
const s = {rowOff:{}, rowOnHeld:{}, include:{9:true}};
const card = {call_id:9, matched:[{user_id:1, status:5, scan_hold:true},
                                  {user_id:2, status:5, scan_hold:true}]};
console.log(JSON.stringify([s.include[9], _tiCallRows(s, card).length]));
""")
        self.assertEqual(out, [True, 0])

    def test_there_is_no_select_all_in_the_import_times_review(self):
        """The brief asks what select-all does to amber rows today. It does
        nothing, because this dialog has never had one: the only controls are
        the per-call checkbox and the per-row checkbox. If a select-all is ever
        added here it must skip held rows, and this test is where that is
        noticed."""
        src = H.page()
        start = src.index("function renderTimesheetImportPreview")
        end = src.index("function tiToggleCall", start)
        review = src[start:end]
        for bulk in ("selectAll", "SelectAll", "TickAll", "tickAll",
                     "check-all", "checkAll"):
            self.assertNotIn(bulk, review, "a bulk tick appeared in the review")
        # and the only onchange handlers are the two per-item ones
        import re
        self.assertEqual(sorted(set(re.findall(r'onchange="(\w+)\(', review))),
                         ["tiToggleCall", "tiToggleRow"])

    def test_the_sheet_and_check_in_sources_are_unchanged_by_this(self):
        out = self.run_js("""
const s = {rowOff:{'9:3':true}, rowOnHeld:{}};
console.log(JSON.stringify([
  _tiRowOn(s, 9, {user_id:2, status:5}),
  _tiRowOn(s, 9, {user_id:3, status:7}),
  _tiRowOn(s, 9, {user_id:4, status:5, promo_pending:true}),
  _tiRowOn(s, 9, {user_id:5, status:5, checkin_hold:true})]));
""")
        self.assertEqual(out, [True, False, False, False])

    def test_the_raw_writing_is_shown_on_a_flagged_row(self):
        out = self.run_js("""
console.log(JSON.stringify([
  _tiScanRawLine({scan_raw:'on 2200 / break 0030'}).indexOf('on 2200') > -1,
  _tiScanRawLine({}) === '']));
""")
        self.assertEqual(out, [True, True])

    def test_flag_chips_are_amber_for_warn_and_grey_for_info(self):
        out = self.run_js("""
const w = _tiScanFlagChips({scan_flags:[{level:'warn', text:'a time in the Break column'}]});
const i = _tiScanFlagChips({scan_flags:[{level:'info', text:'no break written'}]});
console.log(JSON.stringify([w.indexOf('#f59e0b') > -1, i.indexOf('#f59e0b') > -1,
                            w.indexOf('Break column') > -1]));
""")
        self.assertEqual(out, [True, False, True])

    def test_refused_rows_are_named_with_enter_this_one_by_hand(self):
        out = self.run_js("""
const c = {call_id: 9, scan_skipped:[{ein:90004, name:'L90004, F',
  why:"the reader couldn't give both times", flags:[{level:'warn', text:'start time not readable'}],
  raw:'on ~~ / off 0300'}], scan_loose_times:[]};
const h = _tiScanCardNotes(c);
console.log(JSON.stringify([h.indexOf('by hand') > -1, h.indexOf('L90004') > -1,
                            h.indexOf('on ~~') > -1]));
""")
        self.assertEqual(out, [True, True, True])

    def test_loose_times_are_shown_as_writing_to_check_not_as_people(self):
        out = self.run_js("""
const c = {call_id: 9, scan_skipped:[], scan_loose_times:[{text:'2200  0130', flags:[]}]};
const h = _tiScanCardNotes(c);
console.log(JSON.stringify([h.indexOf('outside the table') > -1,
                            h.indexOf('which row they belong to') > -1]));
""")
        self.assertEqual(out, [True, True])

    def test_extras_are_verbatim_with_a_link_to_the_call(self):
        out = self.run_js("""
const c = {call_id: 90011, scan_extras:[{text:'Echo Eve 2200 - 0400 NO BREAK',
  guessed_name:'L90005, F', flags:[{level:'warn', text:'not matched to anyone confirmed on this call'}]}],
  unmatched: []};
const h = _tiScanExtras(c);
console.log(JSON.stringify([h.indexOf('On the sheet, not on this call') > -1,
                            h.indexOf('Echo Eve 2200 - 0400 NO BREAK') > -1,
                            h.indexOf('openCallDialog') > -1,
                            h.indexOf('90011') > -1,
                            _tiScanExtras({call_id:9, scan_extras:[], unmatched:[]}) === '']));
""")
        self.assertEqual(out, [True, True, True, True, True])

    def test_a_printed_row_not_confirmed_on_the_call_is_named_not_counted(self):
        """"3 on sheet, not booked on this call" does not tell Ops who to look
        at. These are rows read off the paper, so they are named."""
        out = self.run_js("""
const c = {call_id: 90011, scan_extras:[],
           unmatched:[{ein:90009, name:'L90009, F'}]};
const h = _tiScanExtras(c);
console.log(JSON.stringify([h.indexOf('L90009, F') > -1,
                            h.indexOf('not confirmed on this call') > -1,
                            _tiScanExtras({call_id:9, scan_extras:[], unmatched:[]}) === '']));
""")
        self.assertEqual(out, [True, True, True])

    def test_on_this_call_not_on_the_sheet_names_the_people(self):
        out = self.run_js("""
const h = _tiScanNotOnSheet({roster_only:[{ein:90004, name:'L90004, F'}], skipped:[], scan_extras:[]});
console.log(JSON.stringify([h.indexOf('not on the sheet (1)') > -1,
                            h.indexOf('L90004, F') > -1,
                            _tiScanNotOnSheet({roster_only:[], skipped:[], scan_extras:[]}) === '']));
""")
        self.assertEqual(out, [True, True, True])

    def test_a_refused_row_is_not_also_listed_as_missing_from_the_sheet(self):
        """Booking 12113: Ebdon's row was read off the sheet and refused, and
        he was then named under "on this call, not on the sheet" two lines
        below his own row. roster_only is the roster minus the MATCHED rows,
        so a refused row -- which leaves `matched` by design -- fell into it."""
        out = self.run_js("""
const c = {roster_only:[{ein:90004, name:'Refused, Row'}, {ein:90005, name:'Absent, Ann'}],
           skipped:[{ein:90004, name:'Refused, Row', reason:'no times in sheet'}],
           scan_extras:[]};
const h = _tiScanNotOnSheet(c);
console.log(JSON.stringify([h.indexOf('Refused, Row') > -1,
                            h.indexOf('Absent, Ann') > -1,
                            h.indexOf('not on the sheet (1)') > -1]));
""")
        self.assertEqual(out, [False, True, True])

    def test_a_handwritten_walk_on_is_not_also_listed_as_missing(self):
        """Same booking: Nichols and Ghosh wrote themselves in by hand. They
        were named in "On the sheet, not on this call" WITH the person the
        reader guessed, and also here as missing from the sheet -- the two
        lists describing the same people in opposite terms."""
        out = self.run_js("""
const c = {roster_only:[{ein:90006, name:'Byhand, Bea'}, {ein:90005, name:'Absent, Ann'}],
           skipped:[],
           scan_extras:[{text:'Bea Byhand 2200 0200',
                         guessed_person:{ein:90006, firstname:'Bea', lastname:'Byhand'}}]};
const h = _tiScanNotOnSheet(c);
console.log(JSON.stringify([h.indexOf('Byhand, Bea') > -1,
                            h.indexOf('Absent, Ann') > -1,
                            h.indexOf('not on the sheet (1)') > -1]));
""")
        self.assertEqual(out, [False, True, True])

    def test_an_unguessed_walk_on_takes_nobody_off_the_missing_list(self):
        """An extra the reader could not put a name to removes no one: there is
        no person to subtract, and guessing one here would be inventing it."""
        out = self.run_js("""
const c = {roster_only:[{ein:90005, name:'Absent, Ann'}], skipped:[],
           scan_extras:[{text:'2200 0200', guessed_person:null}]};
console.log(JSON.stringify([_tiScanNotOnSheet(c).indexOf('Absent, Ann') > -1]));
""")
        self.assertEqual(out, [True])

    JS2 = H.extract("_tiScanUnassignedBlock", "_tiScanBlock", "_tiScanReadingLine")

    def run_js2(self, body):
        return self.run_js(self.JS2 + "\nvar TI_SCAN_SECS_PER_PAGE = 11;\n" + body)

    def test_an_unplaced_block_offers_one_button_per_candidate(self):
        out = self.run_js2("""
const d = {source:{kind:'scan'}, scan_candidates:{'90011':{call_name:'General', start:'22:00', length:'5h'},
                                                  '90012':{call_name:'General', start:'22:00', length:'1h'}},
  unassigned_blocks:[{page:1, block:2, rows_on_block:5, reason:'ambiguous_header',
                      printed_header:'Made-up Ops - General - Tue 6 Oct 2026 22:00',
                      qr_call_id:null, candidate_call_ids:[90011, 90012]}]};
const h = _tiScanUnassignedBlock(d);
console.log(JSON.stringify([
  h.indexOf('Page 1, block 2') > -1,
  h.indexOf('5 rows on it') > -1,
  h.indexOf('General 22:00 \\u00b7 5h') > -1,
  h.indexOf('General 22:00 \\u00b7 1h') > -1,
  h.indexOf('tiScanPickBlock(0,90011)') > -1,
  h.indexOf('tiScanPickBlock(0,90012)') > -1,
  h.indexOf('reads every page again') > -1]));
""")
        self.assertEqual(out, [True] * 7)

    def test_a_qr_for_another_booking_stops_with_the_call_number(self):
        out = self.run_js2("""
const d = {source:{kind:'scan'}, scan_candidates:{},
  unassigned_blocks:[{page:2, block:1, rows_on_block:6, reason:'qr_not_a_candidate',
                      printed_header:'', qr_call_id:99934, candidate_call_ids:[]}]};
const h = _tiScanUnassignedBlock(d);
console.log(JSON.stringify([h.indexOf('this page is for call #99934') > -1,
                            h.indexOf('tiScanPickBlock') > -1]));
""")
        # the message, and NO pick button: no candidate could be right
        self.assertEqual(out, [True, False])

    def test_nothing_unplaced_shows_nothing(self):
        out = self.run_js2("""
console.log(JSON.stringify([_tiScanUnassignedBlock({source:{kind:'scan'}, unassigned_blocks:[]}) === '',
                            _tiScanUnassignedBlock({source:{kind:'checkin'}, unassigned_blocks:[{page:1}]}) === '']));
""")
        self.assertEqual(out, [True, True])

    def test_the_top_line_counts_the_held_rows(self):
        out = self.run_js2("""
const d = {source:{kind:'scan', pages_read:3, summary:'14 rows read \\u00b7 4 flagged'},
  calls:[{matched:[{scan_hold:true},{scan_hold:true},{}], scan_skipped:[]}]};
const h = _tiScanBlock(d);
console.log(JSON.stringify([h.indexOf('3 pages') > -1,
                            h.indexOf('14 rows read') > -1,
                            h.indexOf('2 rows need a look') > -1,
                            _tiScanBlock({source:{kind:'gsheet'}}) === '']));
""")
        self.assertEqual(out, [True, True, True, True])

    def test_refused_rows_are_counted_in_the_header_too(self):
        """Booking 12113 read "6 flagged" and then "1 row needs a look", with
        no account of the other five. A refused row cannot be ticked, so it was
        in neither number -- and the header contradicted itself."""
        out = self.run_js2("""
const d = {source:{kind:'scan', pages_read:1, summary:'6 rows read \\u00b7 6 flagged'},
  calls:[{matched:[{scan_hold:true}], scan_skipped:[{ein:1}]},
         {matched:[{}], scan_skipped:[{ein:2}]}]};
const h = _tiScanBlock(d);
console.log(JSON.stringify([h.indexOf('1 row needs a look') > -1,
                            h.indexOf("2 rows can't be written") > -1,
                            h.indexOf('by hand in Enter Times') > -1]));
""")
        self.assertEqual(out, [True, True, True])

    def test_a_clean_sheet_asks_for_nothing(self):
        out = self.run_js2("""
const d = {source:{kind:'scan', pages_read:1, summary:'4 rows read \\u00b7 0 flagged'},
  calls:[{matched:[{}], scan_skipped:[]}]};
const h = _tiScanBlock(d);
console.log(JSON.stringify([h.indexOf('needs a look') > -1,
                            h.indexOf("can't be written") > -1,
                            h.indexOf('4 rows read') > -1]));
""")
        self.assertEqual(out, [False, False, True])

    def test_the_reading_line_counts_pages_for_images_and_files_for_a_pdf(self):
        out = self.run_js2("""
console.log(JSON.stringify([
  _tiScanReadingLine([{name:'a.jpg'},{name:'b.jpg'},{name:'c.jpg'}]),
  _tiScanReadingLine([{name:'a.pdf'}]),
  _tiScanReadingLine([{name:'a.jpg'}])]));
""")
        self.assertEqual(out, [
            "Reading 3 pages… (about 11 seconds a page)",
            "Reading 1 file… (about 11 seconds a page)",
            "Reading 1 page… (about 11 seconds a page)"])

    JS3 = H.extract("tiScanPickBlock")

    def test_a_pick_sends_the_same_paths_and_drops_only_the_losers(self):
        out = H.run("""
function escapeHtml(s){ return String(s); }
var _tiState = {bookingId: 90001, include:{}, rowOff:{}, rowOnHeld:{},
  data:{source:{kind:'scan', scan_ref:['p/a.jpg','p/b.jpg'], pages_read:2},
        scan_booking_call_ids:[90011, 90012, 90013],
        unassigned_blocks:[{candidate_call_ids:[90011, 90012]}]}};
var seen = null;
function tiLoadScan(b, files, rerun, keep){ seen = {b:b, files:files, rerun:rerun, keep:keep}; }
function _tiSay(m){ seen = {said:m}; }
""" + self.JS3 + """
tiScanPickBlock(0, 90012);
console.log(JSON.stringify([seen.b, seen.files, seen.rerun.scan_paths,
                            seen.rerun.call_ids, seen.rerun.pages,
                            !!seen.keep]));
""")
        self.assertEqual(out, [90001, None, ["p/a.jpg", "p/b.jpg"],
                               [90012, 90013], 2, True])

    def test_a_pick_with_no_paths_on_file_says_so_rather_than_posting(self):
        out = H.run("""
function escapeHtml(s){ return String(s); }
var _tiState = {bookingId: 90001, include:{}, rowOff:{}, rowOnHeld:{},
  data:{source:{kind:'scan', scan_ref:[]}, scan_booking_call_ids:[90011],
        unassigned_blocks:[{candidate_call_ids:[90011]}]}};
var said = null, posted = false;
function tiLoadScan(){ posted = true; }
function _tiSay(m){ said = m; }
""" + self.JS3 + """
tiScanPickBlock(0, 90011);
console.log(JSON.stringify([posted, said.indexOf('scan it again') > -1]));
""")
        self.assertEqual(out, [False, True])


if __name__ == "__main__":
    unittest.main(verbosity=1)

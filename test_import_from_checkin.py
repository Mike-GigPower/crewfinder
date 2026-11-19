#!/usr/bin/env python3
"""Import Times from check-in (BRIEF-import-times-from-checkin, Part 2).

  - checkin_import.convert_feed: every feed state, both late_source values, a
    stranded sign-off, off-roster, no EIN, breaks split day/night by real time
    (19:50 +30 -> 10 day / 20 night), a finish after midnight, a call before
    08:00 crossing into day, an empty call
  - the converted tabs go through _build_import_preview UNCHANGED, every card
    mapped by Call ID (SmartStaff faked)
  - checkin_import.attach_flags: each review flag, warn rows held, info rows not
  - the preview-checkin route: success shape, unknown_call_ids, and a specific
    message for each feed error (Crew Hub faked); the key is never echoed
  - the review screen (index.html under node): a held row starts unticked even
    when Confirmed, ticks on, and sheet rows behave exactly as before

Made-up booking 90001, calls 9001x, EINs 90001+, invented names only.
Run: python3 test_import_from_checkin.py
"""
import json
import unittest
from datetime import datetime
from unittest import mock

import app as A
import checkin_import as C
import js_harness as H

BID = 90001
NOW = C.parse_iso("2026-11-24T07:30:00+11:00")


def _p(name, ein, state, **kw):
    last, first = name.split()
    row = {"user_id": 80000 + (ein or 999), "ein": ein, "lastname": last, "firstname": first,
           "unbooked": False, "on_roster": True, "state": state,
           "tapped_on": None, "tapped_off": None, "on_corrected": False, "off_corrected": False,
           "late": False, "late_source": None, "paid_on": None, "paid_off": None,
           "paid_hours": None, "breaks": [], "under_4h": False, "note": ""}
    row.update(kw)
    return row


D = "2026-11-23T"
Z = ":00+11:00"

CALL_A = {"call_id": 90011, "call_name": "Load In", "scheduled_start": D + "10:00" + Z, "rows": [
    _p("Alpha Ada", 90001, "off", tapped_on=D + "10:12" + Z, tapped_off=D + "15:02" + Z,
       paid_on=D + "10:00" + Z, paid_off=D + "15:00" + Z, paid_hours=4.5,
       breaks=[{"start": D + "12:00" + Z, "mins": 30}], note="ok"),
    _p("Bravo Ben", 90002, "off", tapped_on=D + "10:31" + Z, late=True, late_source="mark",
       paid_on=D + "10:30" + Z, paid_off=D + "16:00" + Z),
    _p("Charlie Cat", 90003, "off", tapped_on=D + "10:00" + Z, late=True, late_source="typed_start",
       paid_on=D + "10:45" + Z, paid_off=D + "16:00" + Z, on_corrected=True),
    _p("Delta Dee", 90004, "on", tapped_on=D + "09:55" + Z, paid_on=D + "10:00" + Z),
    _p("Echo Eve", 90005, "no_show"),
    _p("Foxtrot Fay", 90006, "not_on"),
    _p("Golf Gus", 90007, "off", tapped_off=D + "15:00" + Z, paid_off=D + "15:00" + Z),
    _p("Hotel Hal", 90008, "off", on_roster=False, tapped_on=D + "10:00" + Z,
       paid_on=D + "10:00" + Z, paid_off=D + "15:00" + Z),
    _p("India Ivy", None, "off", tapped_on=D + "10:00" + Z,
       paid_on=D + "10:00" + Z, paid_off=D + "15:00" + Z),
    _p("Juliet Jo", 90010, "off", tapped_on=D + "10:00" + Z, paid_on=D + "10:00" + Z,
       paid_off=D + "22:00" + Z, off_corrected=True,
       breaks=[{"start": D + "13:00" + Z, "mins": 30}, {"start": D + "19:50" + Z, "mins": 30}]),
    _p("Kilo Kim", 90011, "off", tapped_on=D + "11:30" + Z, paid_on=D + "10:00" + Z,
       paid_off=D + "15:00" + Z),
    _p("Lima Lou", 90012, "off", tapped_on=D + "10:00" + Z, paid_on=D + "10:00" + Z,
       paid_off=D + "15:00" + Z, breaks=[{"start": D + "09:45" + Z, "mins": 30}]),
    _p("Mango Mia", 90013, "off", tapped_on=D + "10:00" + Z, paid_on=D + "10:00" + Z,
       paid_off=D + "13:00" + Z, under_4h=True),
    _p("Nectar Ned", 90014, "off", tapped_on="2026-11-24T10:05" + Z, paid_on=D + "10:00" + Z,
       paid_off=D + "15:00" + Z),
]}
CALL_B = {"call_id": 90012, "call_name": "Load Out", "scheduled_start": "2026-12-04T22:00" + Z, "rows": [
    _p("Oscar Oli", 90015, "off", tapped_on="2026-12-04T22:00" + Z, paid_on="2026-12-04T22:00" + Z,
       paid_off="2026-12-05T03:00" + Z, breaks=[{"start": "2026-12-05T00:30" + Z, "mins": 30}]),
]}
CALL_C = {"call_id": 90013, "call_name": "Load In", "scheduled_start": D + "06:30" + Z, "rows": [
    _p("Papa Pat", 90016, "off", tapped_on=D + "06:30" + Z, paid_on=D + "06:30" + Z,
       paid_off=D + "12:00" + Z, breaks=[{"start": D + "07:50" + Z, "mins": 30}]),
]}
CALL_D = {"call_id": 90014, "call_name": "Crew Boss", "scheduled_start": D + "06:00" + Z, "rows": []}

FEED = {"booking_id": BID, "mode": "shadow", "generated_at": "2026-11-24T07:30:00+11:00",
        "unknown_call_ids": [90099], "calls": [CALL_A, CALL_B, CALL_C, CALL_D]}


def _feed():
    return json.loads(json.dumps(FEED))


def _rows(parsed, call_id):
    tab = [t for t in parsed["tabs"] if t["call_id"] == call_id][0]
    return {r["lastname"]: r for r in tab["rows"]}


class Converter(unittest.TestCase):

    def setUp(self):
        self.parsed = C.convert_feed(_feed())
        self.a = _rows(self.parsed, 90011)

    def test_one_tab_per_call_with_call_id_and_call_name(self):
        tabs = self.parsed["tabs"]
        self.assertEqual([t["call_id"] for t in tabs], [90011, 90012, 90013, 90014])
        self.assertEqual([t["tab_name"] for t in tabs], ["Load In", "Load Out", "Load In", "Crew Boss"])
        self.assertEqual(tabs[0]["call_time"], "2026-11-23T10:00:00")
        self.assertEqual(self.parsed["skipped_tabs"], [])

    def test_row_has_exactly_the_parse_crew_row_keys(self):
        want = {"ein", "lastname", "firstname", "status", "on", "off", "break",
                "break_night", "late", "note", "no_show"}
        for t in self.parsed["tabs"]:
            for r in t["rows"]:
                self.assertEqual(set(r), want)

    def test_off_not_late_uses_paid_times(self):
        r = self.a["Alpha"]
        self.assertEqual((r["on"], r["off"], r["break"], r["break_night"]), ("10:00", "15:00", "00:30", "00:00"))
        self.assertEqual((r["status"], r["late"], r["no_show"], r["note"]), ("", 0, False, "ok"))
        self.assertEqual(r["ein"], 90001)

    def test_late_mark_is_status_late(self):
        r = self.a["Bravo"]
        self.assertEqual((r["status"], r["late"], r["on"]), ("Late", 1, "10:30"))

    def test_typed_start_is_paid_from_typed_time_but_not_marked_late(self):
        r = self.a["Charlie"]
        self.assertEqual((r["status"], r["late"], r["on"]), ("", 0, "10:45"))

    def test_late_mapping_lives_in_one_function(self):
        self.assertEqual(C.status_and_late({"state": "off", "late_source": "mark"}), ("Late", 1))
        self.assertEqual(C.status_and_late({"state": "off", "late_source": "typed_start"}), ("", 0))
        self.assertEqual(C.status_and_late({"state": "off", "late_source": None}), ("", 0))
        self.assertEqual(C.status_and_late({"state": "no_show"}), ("No Show", 0))

    def test_still_on_has_no_off(self):
        r = self.a["Delta"]
        self.assertEqual((r["on"], r["off"]), ("10:00", None))

    def test_no_show(self):
        r = self.a["Echo"]
        self.assertEqual((r["no_show"], r["on"], r["off"], r["status"]), (True, None, None, "No Show"))

    def test_not_on_has_no_times(self):
        r = self.a["Foxtrot"]
        self.assertEqual((r["on"], r["off"], r["no_show"]), (None, None, False))

    def test_stranded_sign_off_has_off_only(self):
        r = self.a["Golf"]
        self.assertEqual((r["on"], r["off"]), (None, "15:00"))

    def test_null_ein_and_off_roster_still_converted(self):
        self.assertIsNone(self.a["India"]["ein"])
        self.assertEqual(self.a["Hotel"]["on"], "10:00")

    def test_two_breaks_one_across_20(self):
        # 13:00 +30 all day; 19:50 +30 -> 10 day, 20 night.
        r = self.a["Juliet"]
        self.assertEqual((r["break"], r["break_night"]), ("00:40", "00:20"))

    def test_finish_after_midnight(self):
        r = _rows(self.parsed, 90012)["Oscar"]
        self.assertEqual((r["on"], r["off"]), ("22:00", "03:00"))
        self.assertLess(r["off"], r["on"])
        self.assertEqual((r["break"], r["break_night"]), ("00:00", "00:30"))

    def test_call_before_0800_crossing_into_day(self):
        r = _rows(self.parsed, 90013)["Papa"]
        self.assertEqual((r["on"], r["off"]), ("06:30", "12:00"))
        self.assertEqual((r["break"], r["break_night"]), ("00:20", "00:10"))

    def test_empty_call_is_an_empty_tab(self):
        tab = [t for t in self.parsed["tabs"] if t["call_id"] == 90014][0]
        self.assertEqual(tab["rows"], [])

    def test_utc_input_is_shown_in_melbourne(self):
        self.assertEqual(C.melb_hhmm("2026-11-23T23:15:00Z"), "10:15")
        self.assertEqual(C.melb_hhmm("2026-06-01T00:00:00Z"), "10:00")   # AEST
        self.assertIsNone(C.melb_hhmm(None))


def _ts(y, m, d):
    return int(datetime(y, m, d).timestamp())


BOOKING = {"name": "Made-up booking", "calls": [
    {"call_id": 90011, "call_name": "Load In", "start_date": _ts(2026, 11, 23), "start_time": "10:00:00"},
    {"call_id": 90012, "call_name": "Load Out", "start_date": _ts(2026, 12, 4), "start_time": "22:00:00"},
    {"call_id": 90013, "call_name": "Load In", "start_date": _ts(2026, 11, 23), "start_time": "06:30:00"},
    {"call_id": 90014, "call_name": "Crew Boss", "start_date": _ts(2026, 11, 23), "start_time": "06:00:00"},
]}
# SmartStaff rosters. 90008 (off-roster) is not on any; India has no EIN.
# 90030 is Confirmed but missing from check-in; 90031 is Backup (7) and 90032
# SMS Sent (1) -- neither expected on check-in.
ROSTER = {90011: [90001, 90002, 90003, 90004, 90005, 90006, 90007, 90010, 90011, 90012, 90013, 90014,
                  90030, 90031, 90032],
          90012: [90015], 90013: [90016], 90014: []}
STATUS = {90031: 7, 90032: 1}


def _ct(cid):
    return {"crew": [{"ein": e, "user_id": 80000 + e, "lastname": "L%d" % e, "firstname": "F",
                      "status": STATUS.get(e, 5)} for e in ROSTER[int(cid)]]}


def _times(ss, cid):
    return _ct(cid), None


class ThroughPreview(unittest.TestCase):

    def setUp(self):
        p = mock.patch.multiple(A, fetch_booking_bulk=lambda ss, b: (BOOKING, None),
                                ss_get_call_times=_times)
        p.start()
        self.addCleanup(p.stop)
        self.feed = _feed()
        self.prev, err = A._build_import_preview(object(), str(BID), C.convert_feed(self.feed))
        self.assertIsNone(err)
        rs = C.roster_status_from_call_times({cid: _ct(cid) for cid in ROSTER})
        C.attach_flags(self.prev, self.feed, now=NOW, roster_status=rs)
        self.by = {c["call_id"]: c for c in self.prev["calls"]}

    def test_roster_only_carries_smartstaff_status(self):
        ro = {r["ein"]: r["status"] for r in self.by[90011]["roster_only"]}
        # the feed's no-times people (skipped) are roster_only too, as from the sheet
        self.assertEqual(ro, {90005: 5, 90006: 5, 90007: 5, 90030: 5, 90031: 7, 90032: 1})

    def test_status_is_none_when_not_known(self):
        prev, _ = A._build_import_preview(object(), str(BID), C.convert_feed(self.feed))
        C.attach_flags(prev, self.feed, now=NOW)
        card = [c for c in prev["calls"] if c["call_id"] == 90011][0]
        self.assertTrue(all(r["status"] is None for r in card["roster_only"]))

    def test_every_card_maps_exactly_by_call_id(self):
        self.assertEqual(sorted(self.by), [90011, 90012, 90013])
        for c in self.prev["calls"]:
            self.assertEqual(c["map_by"], "call_id")
        self.assertEqual(self.prev["foreign_tabs"], [])
        self.assertEqual(self.prev["unmapped_tabs"], [])
        self.assertEqual(self.prev["needs_call_tabs"], [])
        self.assertEqual(self.prev["skipped_tabs"], ["Crew Boss"])   # the empty call

    def test_two_same_named_calls_are_not_duplicates(self):
        self.assertEqual(self.prev["duplicate_tabs"], [])
        self.assertIn(90013, self.by)

    def test_rows_land_where_the_sheet_rows_would(self):
        c = self.by[90011]
        matched = {m["ein"] for m in c["matched"]}
        self.assertEqual(matched, {90001, 90002, 90003, 90004, 90010, 90011, 90012, 90013, 90014})
        unmatched = {u["ein"] for u in c["unmatched"]}
        self.assertEqual(unmatched, {90008, None})
        skipped = {s["ein"]: s["reason"] for s in c["skipped"]}
        self.assertEqual(skipped, {90005: "No Show", 90006: "no times in sheet",
                                   90007: "no times in sheet"})

    def test_existing_keys_are_untouched(self):
        m = [m for m in self.by[90011]["matched"] if m["ein"] == 90002][0]
        for k in ("user_id", "ein", "name", "on", "off", "break", "break_night",
                  "late", "note", "status", "promo_pending"):
            self.assertIn(k, m)
        self.assertEqual((m["on"], m["off"], m["late"]), ("10:30", "16:00", 1))

    def _m(self, ein, call=90011):
        return [m for m in self.by[call]["matched"] if m["ein"] == ein][0]

    def _codes(self, ein, call=90011):
        return [f["code"] for f in self._m(ein, call).get("checkin_flags", [])]

    def test_clean_rows_are_not_held(self):
        for ein in (90001, 90002):
            self.assertNotIn("checkin_hold", self._m(ein))

    def test_typed_start_is_info_and_ticked(self):
        m = self._m(90003)
        self.assertEqual(self._codes(90003), ["typed_start"])
        self.assertFalse(m["checkin_hold"])
        self.assertIn("late by typed sign-on 10:45", m["checkin_flags"][0]["text"])

    def test_warn_flags_hold_the_row(self):
        cases = {90004: "no_off", 90012: "break_outside", 90013: "under_4h",
                 90011: "late_tap", 90014: "late_tap"}
        for ein, code in cases.items():
            self.assertIn(code, self._codes(ein), ein)
            self.assertTrue(self._m(ein)["checkin_hold"], ein)

    def test_late_tap_text(self):
        self.assertIn("tapped 11:30, paid from call start", self._m(90011)["checkin_flags"][0]["text"])
        self.assertIn("Tue 24 Nov 10:05", self._m(90014)["checkin_flags"][0]["text"])

    def test_late_tap_threshold_is_named(self):
        self.assertEqual(C.LATE_TAP_GAP_MINS, 60)
        row = _p("Quebec Quin", 90020, "off", tapped_on=D + "11:00" + Z,
                 paid_on=D + "10:00" + Z, paid_off=D + "15:00" + Z)
        self.assertEqual(C.row_flags(row, C.parse_iso(D + "10:00" + Z), NOW), [])   # exactly 60
        row["tapped_on"] = D + "11:01" + Z
        self.assertEqual([f["code"] for f in C.row_flags(row, C.parse_iso(D + "10:00" + Z), NOW)],
                         ["late_tap"])
        row["late"] = True; row["late_source"] = "mark"
        self.assertEqual(C.row_flags(row, C.parse_iso(D + "10:00" + Z), NOW), [])

    def test_unwritable_people_are_named_on_the_card(self):
        notes = {(n["name"], n["code"]) for n in self.by[90011]["checkin_notes"]}
        for want in [("Foxtrot, Fay", "not_on"), ("Golf, Gus", "no_on"),
                     ("Hotel, Hal", "off_roster"), ("India, Ivy", "no_ein")]:
            self.assertIn(want, notes)
        self.assertNotIn("Echo, Eve", {n[0] for n in notes})   # a no-show is normal

    def test_future_call_holds_its_rows(self):
        self.assertTrue(self.by[90012]["checkin_future"])
        self.assertFalse(self.by[90011]["checkin_future"])
        self.assertIn("future_call", self._codes(90015, 90012))
        self.assertTrue(self._m(90015, 90012)["checkin_hold"])

    def test_source_and_unknown_ids(self):
        self.assertEqual(self.prev["source"], {"kind": "checkin",
                                               "generated_at": "2026-11-24T07:30:00+11:00",
                                               "mode": "shadow"})
        self.assertEqual(self.prev["unknown_call_ids"], [90099])

    def test_no_department_rating_or_helper_keys(self):
        blob = json.dumps(self.prev).lower()
        for k in ("department", "rating", "helper", "colour", "target"):
            self.assertNotIn('"%s' % k, blob)


class FakeResp(object):
    def __init__(self, status, body):
        self.status_code = status
        self._body = body

    def json(self):
        if isinstance(self._body, Exception):
            raise self._body
        return self._body


KEY = "made-up-test-key-90001"


class Route(unittest.TestCase):

    def setUp(self):
        self.sent = []
        self.answer = FakeResp(200, _feed())
        p = mock.patch.multiple(A, fetch_booking_bulk=lambda ss, b: (BOOKING, None),
                                ss_get_call_times=_times, GOAT_CHECKIN_KEY=KEY)
        p.start()
        self.addCleanup(p.stop)

    def _get(self, *a, **kw):
        self.sent.append((a, kw))
        return self.answer

    def call(self, path, cohort="admin"):
        with A.app.test_client() as c:
            with c.session_transaction() as s:
                s["sid"] = "t"
            with mock.patch.multiple(A, get_ss_session=lambda: object()), \
                 mock.patch.object(A.http, "get", self._get), \
                 mock.patch.dict(A._ss_identity, {"t": {"cohort": cohort, "usergroupID": 1,
                                                        "user_id": "90001"}}):
                r = c.get(path)
        return r, r.get_json()

    def test_admin_only(self):
        r, _ = self.call("/api/booking/90001/import-times/preview-checkin", cohort="operations")
        self.assertEqual(r.status_code, 403)
        self.assertEqual(self.sent, [])

    def test_success_shape_and_request(self):
        r, body = self.call("/api/booking/90001/import-times/preview-checkin?call_id=90011&call_id=90012")
        self.assertEqual(r.status_code, 200, body)
        self.assertEqual(body["source"]["kind"], "checkin")
        self.assertEqual(body["unknown_call_ids"], [90099])
        self.assertEqual({c["map_by"] for c in body["calls"]}, {"call_id"})
        (args, kw), = self.sent
        self.assertEqual(args[0], A.CHECKIN_SETTLEMENT_URL)
        self.assertEqual(kw["headers"], {"X-Goat-Service-Key": KEY})
        self.assertEqual(kw["params"], [("booking_id", 90001), ("call_id", 90011), ("call_id", 90012)])
        self.assertNotIn(KEY, json.dumps(body))

    def test_bad_call_id_is_refused_before_crew_hub(self):
        r, body = self.call("/api/booking/90001/import-times/preview-checkin?call_id=x")
        self.assertEqual(r.status_code, 400)
        self.assertEqual(self.sent, [])

    def test_each_feed_error_has_its_own_message(self):
        cases = {400: "refused the request", 401: "sent no check-in key",
                 403: "doesn't match", 502: "from SmartStaff"}
        for status, frag in cases.items():
            self.answer = FakeResp(status, {"error": "x"})
            r, body = self.call("/api/booking/90001/import-times/preview-checkin")
            self.assertEqual(r.status_code, 502, status)
            self.assertIn(frag, body["error"], status)
            self.assertNotIn(KEY, body["error"])

    def test_503_shows_crew_hubs_own_text(self):
        for upstream in ("GOAT_CHECKIN_KEY not configured", "Tap log unavailable"):
            self.answer = FakeResp(503, {"error": upstream})
            _, body = self.call("/api/booking/90001/import-times/preview-checkin")
            self.assertEqual(body["error"], "Check-in feed unavailable (503): " + upstream)
        self.answer = FakeResp(503, ValueError("no json"))
        _, body = self.call("/api/booking/90001/import-times/preview-checkin")
        self.assertEqual(body["error"], "Check-in feed unavailable (503)")

    def test_non_json_and_missing_local_key(self):
        self.answer = FakeResp(200, ValueError("no json"))
        _, body = self.call("/api/booking/90001/import-times/preview-checkin")
        self.assertIn("isn't JSON", body["error"])
        with mock.patch.object(A, "GOAT_CHECKIN_KEY", ""):
            r, body = self.call("/api/booking/90001/import-times/preview-checkin")
        self.assertIn("isn't configured", body["error"])
        self.assertEqual(len(self.sent), 1)   # the unset key never reached Crew Hub


class FakeSS(object):
    """A SmartStaff session answering get-call-times.php only."""
    cookies = "jar"

    def __init__(self):
        self.gets = []

    def get(self, url, params=None, **kw):
        self.gets.append((url, dict(params or {})))
        if "get-call-times.php" in url:
            return FakeResp(200, _ct(params["id"]))
        return FakeResp(404, {"error": "not here"})


class Recorder(unittest.TestCase):

    def test_records_call_times_and_passes_everything_through(self):
        ss = FakeSS()
        rec = A._CallTimesRecorder(ss)
        data, err = A.ss_get_call_times(rec, 90012)
        self.assertIsNone(err)
        self.assertEqual(data, _ct(90012))
        self.assertEqual(rec.answers, {90012: _ct(90012)})
        self.assertEqual(rec.cookies, "jar")
        rec.get("https://x/other.php", params={"id": 1})
        self.assertEqual(list(rec.answers), [90012])
        self.assertEqual(len(ss.gets), 2)       # one request each, never repeated

    def test_route_puts_status_on_roster_only_without_extra_requests(self):
        ss = FakeSS()
        with A.app.test_client() as c:
            with c.session_transaction() as s:
                s["sid"] = "t"
            with mock.patch.multiple(A, get_ss_session=lambda: ss, GOAT_CHECKIN_KEY=KEY,
                                     fetch_booking_bulk=lambda s_, b: (BOOKING, None)), \
                 mock.patch.object(A.http, "get", lambda *a, **k: FakeResp(200, _feed())), \
                 mock.patch.dict(A._ss_identity, {"t": {"cohort": "admin", "usergroupID": 1,
                                                        "user_id": "90001"}}):
                body = c.get("/api/booking/90001/import-times/preview-checkin").get_json()
        card = [x for x in body["calls"] if x["call_id"] == 90011][0]
        ro = {r["ein"]: r["status"] for r in card["roster_only"]}
        self.assertEqual((ro[90030], ro[90031], ro[90032]), (5, 7, 1))
        self.assertEqual(sorted(g[1]["id"] for g in ss.gets), [90011, 90012, 90013, 90014])


@unittest.skipUnless(H.NODE, "node not installed")
class ReviewScreen(unittest.TestCase):

    JS = H.extract("_tiIsConfirmed", "_tiPromoPending", "_tiRowOn", "_tiCallRows",
                   "_tiCheckinCardNotes", "_tiFlagChips", "_tiOffFeed", "_tiMissingConfirmedLines")

    def run_js(self, body):
        return H.run("""
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;'); }
function decodeEntities(s){ return String(s).replace(/&#39;/g, "'"); }
""" + self.JS + body)

    def test_held_rows_start_unticked_and_tick_on(self):
        out = self.run_js("""
const s = {rowOff:{}, rowOnHeld:{}};
const held = {user_id:1, status:5, checkin_hold:true};
const clean = {user_id:2, status:5};
const unconf = {user_id:3, status:7};
const a = [_tiRowOn(s, 9, held), _tiRowOn(s, 9, clean), _tiRowOn(s, 9, unconf)];
s.rowOnHeld['9:1'] = true;
a.push(_tiRowOn(s, 9, held));
a.push(_tiCallRows(s, {call_id:9, matched:[held, clean, unconf]}).length);
console.log(JSON.stringify(a));
""")
        self.assertEqual(out, [False, True, True, True, 3])

    def test_sheet_state_without_rowOnHeld_is_unchanged(self):
        out = self.run_js("""
const s = {rowOff:{'9:3':true}};
console.log(JSON.stringify([_tiRowOn(s, 9, {user_id:2, status:5}),
  _tiRowOn(s, 9, {user_id:3, status:7}), _tiRowOn(s, 9, {user_id:4, status:5, promo_pending:true})]));
""")
        self.assertEqual(out, [True, False, False])

    JS2 = H.extract("_tiSeedInclude", "_tiOffFeed", "_tiAbsentExtra",
                    "_tiMissingConfirmedLines", "tiToggleRow")

    def run_js2(self, body):
        return self.run_js(self.JS2 + "\nvar _tiState = null; function renderTimesheetImportPreview(){}\n" + body)

    CARD = """
const card = {call_id: 9, matched: [{user_id:1, ein:90001, status:5, checkin_hold:true}],
  skipped: [{ein:90006, name:'Foxtrot, Fay', reason:'no times in sheet'},
            {ein:90005, name:'Echo, Eve', reason:'No Show'}],
  roster_only: [{ein:90006, name:'Foxtrot, Fay', status:5}, {ein:90005, name:'Echo, Eve', status:8},
                {ein:90020, name:'Romeo, Rae', status:7}, {ein:90021, name:'Sierra, Sam', status:'1'},
                {ein:90022, name:'Tango, Tia', status:5}, {ein:90023, name:'Uniform, O&#39;Neil', status:'5'},
                {ein:90024, name:'Victor, Vi', status:null}]};
"""

    def test_not_confirmed_count_is_status_not_5_off_the_feed(self):
        # 90020 (7) and 90021 ('1'). Not 90005: status 8 but on the feed (a
        # skipped no-show), already named. Not 90024: status unknown.
        out = self.run_js2(self.CARD + "console.log(JSON.stringify(_tiAbsentExtra(card, 'checkin')));")
        self.assertEqual(out, "2 on SmartStaff but not Confirmed (not expected on check-in)")
        out = self.run_js2("console.log(JSON.stringify(_tiAbsentExtra({skipped:[], roster_only:[{ein:1, status:5}]}, 'checkin')));")
        self.assertEqual(out, "")

    def test_confirmed_but_missing_from_check_in_is_an_amber_line(self):
        out = self.run_js2(self.CARD + "console.log(JSON.stringify(_tiMissingConfirmedLines(card)));")
        self.assertEqual(out, [
            "<div>⚠ Confirmed in SmartStaff but missing from check-in (2): Tango, Tia; Uniform, O'Neil</div>",   # decoded, then escaped
            "<div>⚠ Not on check-in, SmartStaff status unknown (1): Victor, Vi</div>"])
        # Foxtrot (status 5, never tapped) is on the feed: listed under its flag, not here.
        self.assertNotIn("Foxtrot", "".join(out))

    def test_missing_confirmed_line_sits_in_the_card_notes(self):
        out = self.run_js(self.CARD + """
card.checkin_notes = [];
console.log(JSON.stringify(_tiCheckinCardNotes(card)));
""")
        self.assertIn("color:#f59e0b", out)
        self.assertIn("Confirmed in SmartStaff but missing from check-in (2)", out)

    def test_sheet_card_gets_no_check_in_notes(self):
        # A sheet card: no checkin_notes, roster_only without status.
        out = self.run_js("""
console.log(JSON.stringify(_tiCheckinCardNotes({matched:[], skipped:[],
  roster_only:[{ein:1, name:'Whiskey, Wu'}]})));
""")
        self.assertEqual(out, "")
        src = H.page()
        self.assertIn("(s.source === 'checkin' ? _tiCheckinCardNotes(c) : '')", src)

    def test_absent_count_sheet_and_xlsx_unchanged(self):
        for src in ("gsheet", "file"):
            out = self.run_js2(self.CARD + "console.log(JSON.stringify(_tiAbsentExtra(card, '%s')));" % src)
            self.assertEqual(out, "7 booked but absent from sheet")
        out = self.run_js2("console.log(JSON.stringify(_tiAbsentExtra({roster_only:[]}, 'gsheet')));")
        self.assertEqual(out, "")

    def test_zero_to_write_check_in_card_starts_unticked(self):
        out = self.run_js2(self.CARD + """
const clean = {call_id: 10, matched: [{user_id:2, ein:90002, status:5}]};
const empty = {call_id: 11, matched: []};
const d = {calls: [card, clean, empty]};
console.log(JSON.stringify([_tiSeedInclude(d, null, 'checkin'),
  _tiSeedInclude(d, {rowOnHeld: {'9:1': true}}, 'checkin'),
  _tiSeedInclude(d, {include: {9: true}}, 'checkin')]));
""")
        self.assertEqual(out, [{"9": False, "10": True, "11": False},
                               {"9": True, "10": True, "11": False},
                               {"9": True, "10": True, "11": False}])

    def test_sheet_card_seeding_unchanged(self):
        # Sheet rows never carry checkin_hold; a promo-locked-only card still
        # starts ticked, exactly as before.
        out = self.run_js2("""
const d = {calls: [{call_id: 9, matched: [{user_id:1, status:5, promo_pending:true}]},
                   {call_id: 10, matched: [{user_id:2, status:7}]},
                   {call_id: 11, matched: []}]};
console.log(JSON.stringify([_tiSeedInclude(d, null), _tiSeedInclude(d, null, 'gsheet'),
                            _tiSeedInclude(d, null, 'file')]));
""")
        want = {"9": True, "10": True, "11": False}
        self.assertEqual(out, [want, want, want])

    def test_ticking_a_held_row_ticks_its_card(self):
        out = self.run_js2(self.CARD + """
_tiState = {data: {calls: [card]}, include: {9: false}, rowOff: {}, rowOnHeld: {}};
tiToggleRow(9, 1, {checked: true});
const a = [_tiState.include[9], !!_tiState.rowOnHeld['9:1']];
tiToggleRow(9, 1, {checked: false});
a.push(_tiState.include[9], !!_tiState.rowOnHeld['9:1']);
console.log(JSON.stringify(a));
""")
        self.assertEqual(out, [True, True, True, False])

    def test_card_notes_name_unwritable_people_only(self):
        out = self.run_js("""
const c = {matched:[{ein:90004}], checkin_future:false, checkin_notes:[
  {name:'Delta, Dee', ein:90004, level:'warn', text:'signed on, no sign-off'},
  {name:'Foxtrot, Fay', ein:90006, level:'warn', text:'rostered, never tapped'},
  {name:'India, <Ivy>', ein:null, level:'warn', text:'no Crew Hub record'}]};
console.log(JSON.stringify(_tiCheckinCardNotes(c)));
""")
        self.assertIn("Foxtrot, Fay", out)
        self.assertIn("India, &lt;Ivy>", out)
        self.assertNotIn("Delta", out)
        self.assertEqual(self.run_js("console.log(JSON.stringify(_tiCheckinCardNotes({matched:[]})));"), "")


if __name__ == "__main__":
    unittest.main()

#!/usr/bin/env python3
"""Open the check-in sheet from THE GOAT (BRIEF-open-checkin-sheet-from-goat).

  - openCheckinSheet: the URL it builds, and the cohort gate INSIDE the helper
    (crew gets nothing; admin and the read-only cohorts get the window)
  - checkinSheetLinkHtml: the link for shadow/live only, nothing for off or an
    unreadable mode, nothing for crew, and one label constant behind all of it
  - the booking dialog, per mode, under the switch AND under the badge
  - the call dialog's loader: booking mode, async, nothing on failure
  - Import Times: the link under the check-in button, and the intro wording
  - the review screen header for a check-in import
  - /api/ops/checkin-today: shadow/live kept and off dropped, one read per
    BOOKING, the cap, a failed mode read dropping only that booking, the cache,
    the cohort gate and the soft-fail
  - the 'Check-in today' lane markup: the zero badge, the rows, the capped line

Made-up booking 90001 only. No network, no Crew Hub, no SmartStaff.
Run: python3 test_open_checkin_sheet.py
"""
import unittest
from datetime import datetime, timedelta
from unittest import mock

import app as A
import js_harness as H

LABEL = "✅ Open check-in sheet ↗"
HUB = "https://crew.gigpower.com"

# The page's own constants, read out of index.html rather than restated here, so
# a change to the label or the wording fails these tests instead of passing them.
CONSTS = "\n".join(
    l for l in H.page().splitlines()
    if l.startswith(("var CHECKIN_SHEET_LABEL", "var CHECKIN_SHEET_LINK_STYLE",
                     "var TI_INTRO_SHEET", "var TI_INTRO_CHECKIN",
                     "const TI_CHECKIN_SHADOW_LINE", "const TI_CHECKIN_LIVE_LINE"))
)

STUBS = r"""
var opened = [], fetched = [], modeAnswer = 'live', modeOk = true, modeBody = null;
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
function entityRow(label, value){ return '<row ' + label + '>' + value + '</row>'; }
var _els = {};
var document = {getElementById: function(id){
  return _els[id] || (_els[id] = {id: id, style: {}, innerHTML: '', textContent: '', isConnected: true});
}};
var window = {
  _cohort: 'admin',
  isReadOnlyCohort: function(c){ return ['leadership','operations'].includes(c || window._cohort); },
  open: function(url, target){ opened.push([url, target]); }
};
function hubUrl(path){ return (window._hubBase || 'https://crew.gigpower.com') + path; }
function tiLoadCheckin(){}
var _tiState = null;
function fetch(url, opts){
  fetched.push([url, (opts && opts.method) || 'GET']);
  if(/checkin-mode$/.test(url)){
    if(!modeOk) return Promise.reject(new Error('down'));
    var b = modeBody !== null ? modeBody : {mode: modeAnswer, can_switch: true};
    return Promise.resolve({ok: true, json: function(){ return Promise.resolve(b); }});
  }
  return new Promise(function(){});
}
"""

JS = H.extract("openCheckinSheet", "checkinSheetLinkHtml",
               "bkCheckinControl", "bkCheckinMarkup",
               "callLoadCheckinSheet",
               "_tiCheckinButtonHtml", "_tiCheckinModeLine",
               "_tiCheckinBlock", "_tiIsoHHMM",
               "tiLoadCheckinButton")


@unittest.skipUnless(H.NODE, "node not installed")
class Helper(unittest.TestCase):
    """openCheckinSheet and checkinSheetLinkHtml — the one gate, the one label."""

    def js(self, body):
        return H.run(STUBS + CONSTS + "\n" + JS + "\n" + body)

    def test_builds_the_right_url(self):
        out = self.js(r"""
openCheckinSheet('12312');
console.log(JSON.stringify({opened: opened}));
""")
        self.assertEqual(out["opened"], [[HUB + "/your-crew/checkin/12312", "_blank"]])

    def test_booking_id_is_encoded_and_a_missing_one_opens_nothing(self):
        out = self.js(r"""
openCheckinSheet('a b/c');
var withId = opened.slice();
opened = [];
openCheckinSheet('');
openCheckinSheet(null);
openCheckinSheet(undefined);
console.log(JSON.stringify({withId: withId, empty: opened}));
""")
        self.assertEqual(out["withId"], [[HUB + "/your-crew/checkin/a%20b%2Fc", "_blank"]])
        self.assertEqual(out["empty"], [])

    def test_the_gate_is_inside_the_helper(self):
        """Crew gets no window at all, however it was called."""
        out = self.js(r"""
var r = {};
['admin','operations','leadership','crew','boss',''].forEach(function(c){
  window._cohort = c; opened = [];
  openCheckinSheet('90001');
  r[c || 'blank'] = opened.length;
});
console.log(JSON.stringify(r));
""")
        self.assertEqual(out["admin"], 1)
        self.assertEqual(out["operations"], 1)
        self.assertEqual(out["leadership"], 1)
        self.assertEqual(out["crew"], 0)
        self.assertEqual(out["boss"], 0)
        self.assertEqual(out["blank"], 0)

    def test_link_markup_only_for_shadow_and_live(self):
        out = self.js(r"""
var r = {};
['off','shadow','live','unknown','',null,undefined].forEach(function(m){
  r[String(m)] = checkinSheetLinkHtml('90001', m, 'S');
});
console.log(JSON.stringify(r));
""")
        for mode in ("off", "unknown", "", "null", "undefined"):
            self.assertEqual(out[mode], "", mode)
        for mode in ("shadow", "live"):
            self.assertIn("openCheckinSheet('90001')", out[mode])
            self.assertIn(LABEL, out[mode])
            self.assertIn('style="S"', out[mode])

    def test_link_markup_hidden_from_crew(self):
        out = self.js(r"""
var r = {};
['admin','operations','leadership','crew'].forEach(function(c){
  window._cohort = c;
  r[c] = checkinSheetLinkHtml('90001', 'live', 'S');
});
console.log(JSON.stringify(r));
""")
        self.assertEqual(out["crew"], "")
        for c in ("admin", "operations", "leadership"):
            self.assertIn(LABEL, out[c])

    def test_the_label_lives_in_one_constant(self):
        self.assertIn("var CHECKIN_SHEET_LABEL", CONSTS)
        # Nothing else in the page may spell the label out for itself.
        self.assertEqual(H.page().count("Open check-in sheet"), 1)


@unittest.skipUnless(H.NODE, "node not installed")
class BookingDialog(unittest.TestCase):
    """The link under the switch (the switcher) and under the badge (everyone else)."""

    def js(self, body):
        return H.run(STUBS + CONSTS + "\n" + JS + "\n" + body)

    def test_link_under_the_switch_and_the_badge_per_mode(self):
        out = self.js(r"""
var r = {};
['off','shadow','live'].forEach(function(m){
  r['switch_' + m] = bkCheckinMarkup({mode: m, can_switch: true}, '90001', 'h');
  r['badge_' + m]  = bkCheckinMarkup({mode: m, can_switch: false}, '90001', 'h');
});
r.unknown_admin = bkCheckinMarkup('unknown', '90001', 'h');
console.log(JSON.stringify(r));
""")
        # Shadow and Live: the link appears on BOTH roads through the dialog.
        for mode in ("shadow", "live"):
            self.assertIn(LABEL, out["switch_" + mode], mode)
            self.assertIn(LABEL, out["badge_" + mode], mode)
        # Off: the switcher still gets the switch, but no sheet to open.
        self.assertNotIn(LABEL, out["switch_off"])
        self.assertIn("bkSetCheckinMode", out["switch_off"])
        # Off and not the switcher: the whole section stays empty (test 7).
        self.assertEqual(out["badge_off"], "")
        # Unknown: the retry line, and never a link.
        self.assertIn("Retry", out["unknown_admin"])
        self.assertNotIn(LABEL, out["unknown_admin"])

    def test_the_badge_still_says_what_it_said(self):
        """The link is an addition; the Shadow/Live wording is untouched."""
        out = self.js(r"""
console.log(JSON.stringify({
  shadow: bkCheckinMarkup({mode:'shadow', can_switch:false}, '90001', 'h'),
  live:   bkCheckinMarkup({mode:'live',   can_switch:false}, '90001', 'h')}));
""")
        self.assertIn("Live check-in pilot — the Google Sheet is still the record", out["shadow"])
        self.assertIn("Live check-in — the check-in sheet is the record", out["live"])

    def test_crew_never_sees_the_link_in_the_dialog(self):
        out = self.js(r"""
window._cohort = 'crew';
console.log(JSON.stringify({
  badge: bkCheckinMarkup({mode:'live', can_switch:false}, '90001', 'h')}));
""")
        self.assertNotIn(LABEL, out["badge"])


@unittest.skipUnless(H.NODE, "node not installed")
class CallDialog(unittest.TestCase):
    """callLoadCheckinSheet — the booking's mode, async, silent on failure."""

    def js(self, body):
        return H.run(STUBS + CONSTS + "\n" + JS + "\n" + body)

    def _load(self, setup):
        return self.js(setup + r"""
callLoadCheckinSheet('90001', '40029').then(function(){
  console.log(JSON.stringify({html: _els['call-checkin-wrap-40029'].innerHTML,
                              fetched: fetched}));
});
""")

    def test_link_for_shadow_and_live(self):
        for mode in ("shadow", "live"):
            out = self._load("modeAnswer = '%s';" % mode)
            self.assertIn(LABEL, out["html"], mode)
            self.assertIn("openCheckinSheet('90001')", out["html"], mode)
            # The BOOKING's mode, read once, at the booking route.
            self.assertEqual(out["fetched"],
                             [["/api/booking/90001/checkin-mode", "GET"]], mode)

    def test_nothing_for_off_or_an_unreadable_mode(self):
        for setup, why in (("modeAnswer = 'off';", "off"),
                           ("modeOk = false;", "fetch rejected"),
                           ("modeBody = {error: 'nope'};", "error body"),
                           ("modeBody = {};", "no mode field")):
            out = self._load(setup)
            self.assertEqual(out["html"], "", why)

    def test_a_superseded_load_writes_nothing(self):
        """The dialog was re-rendered (or closed) while the mode was in flight."""
        out = self.js(r"""
var host = document.getElementById('call-checkin-wrap-40029');
callLoadCheckinSheet('90001', '40029').then(function(){
  console.log(JSON.stringify({html: host.innerHTML}));
});
host.__loadToken = 99;          // a later render bumped it
""")
        self.assertEqual(out["html"], "")

    def test_a_detached_host_writes_nothing(self):
        out = self.js(r"""
var host = document.getElementById('call-checkin-wrap-40029');
callLoadCheckinSheet('90001', '40029').then(function(){
  console.log(JSON.stringify({html: host.innerHTML}));
});
host.isConnected = false;       // the node was replaced
""")
        self.assertEqual(out["html"], "")

    def test_no_host_means_no_request(self):
        """That view isn't on screen — don't go and ask Crew Hub about it."""
        out = self.js(r"""
document.getElementById = function(){ return null; };
callLoadCheckinSheet('90001', '40029').then(function(){
  console.log(JSON.stringify({fetched: fetched}));
});
""")
        self.assertEqual(out["fetched"], [])


@unittest.skipUnless(H.NODE, "node not installed")
class ImportTimes(unittest.TestCase):
    """The link under the check-in button, and the intro wording."""

    def js(self, body):
        return H.run(STUBS + CONSTS + "\n" + JS + "\n" + body)

    def test_link_under_the_check_in_button(self):
        out = self.js(r"""
console.log(JSON.stringify({live: _tiCheckinButtonHtml('90001','live'),
                            shadow: _tiCheckinButtonHtml('90001','shadow')}));
""")
        for mode in ("live", "shadow"):
            h = out[mode]
            # Button first, then the mode line, then the link (brief: under it).
            self.assertLess(h.index("Import from check-in"), h.index(LABEL), mode)
            self.assertIn("openCheckinSheet('90001')", h)
        self.assertLess(out["live"].index("Live booking"), out["live"].index(LABEL))
        self.assertLess(out["shadow"].index("Shadow booking"), out["shadow"].index(LABEL))

    def test_the_import_button_itself_is_unchanged(self):
        out = self.js(r"""
console.log(JSON.stringify({live: _tiCheckinButtonHtml('90001','live')}));
""")
        self.assertTrue(out["live"].startswith('<div onclick="tiLoadCheckin('))
        self.assertIn("✅ Import from check-in", out["live"])

    def test_intro_says_check_in_first_when_the_mode_is_on(self):
        out = self.js(r"""
var r = {};
var modes = ['live','shadow','off'];
(function next(i){
  if(i >= modes.length){ console.log(JSON.stringify(r)); return; }
  modeAnswer = modes[i];
  _els['ti-intro'] = {id:'ti-intro', style:{}, innerHTML:'', textContent: TI_INTRO_SHEET, isConnected:true};
  _els['ti-checkin-slot'] = {id:'ti-checkin-slot', style:{}, innerHTML:'', textContent:'', isConnected:true};
  tiLoadCheckinButton('90001').then(function(){
    r[modes[i]] = _els['ti-intro'].textContent;
    next(i + 1);
  });
})(0);
""")
        self.assertEqual(out["live"], out["shadow"])
        self.assertIn("from the check-in sheet", out["live"])
        self.assertTrue(out["live"].startswith("Pull the actual times worked back onto each call —"))
        # Off keeps the old line, word for word.
        self.assertIn("Read the Google Sheet THE GOAT generated", out["off"])
        self.assertNotIn("check-in", out["off"])

    def test_an_unreadable_mode_leaves_the_off_wording(self):
        out = self.js(r"""
modeOk = false;
_els['ti-intro'] = {id:'ti-intro', style:{}, innerHTML:'', textContent: TI_INTRO_SHEET, isConnected:true};
_els['ti-checkin-slot'] = {id:'ti-checkin-slot', style:{}, innerHTML:'', textContent:'', isConnected:true};
tiLoadCheckinButton('90001').then(function(){
  console.log(JSON.stringify({intro: _els['ti-intro'].textContent,
                              slot: _els['ti-checkin-slot'].innerHTML}));
});
""")
        self.assertIn("Read the Google Sheet THE GOAT generated", out["intro"])
        self.assertEqual(out["slot"], "")

    def test_both_intro_lines_exist_and_differ(self):
        out = self.js(r"""
console.log(JSON.stringify({sheet: TI_INTRO_SHEET, checkin: TI_INTRO_CHECKIN}));
""")
        self.assertNotEqual(out["sheet"], out["checkin"])
        self.assertIn("review everything before anything is written", out["sheet"])
        self.assertIn("review everything before anything is written", out["checkin"])

    def test_review_screen_header_carries_the_link(self):
        out = self.js(r"""
_tiState = {bookingId: '90001'};
var r = {};
['live','shadow','off'].forEach(function(m){
  r[m] = _tiCheckinBlock({source: {kind:'checkin', mode: m, generated_at: '2026-11-21T08:14:00+11:00'},
                          calls: [], unknown_call_ids: []});
});
r.sheet_import = _tiCheckinBlock({source: {kind:'gsheet', mode:'live'}, calls: []});
console.log(JSON.stringify(r));
""")
        for mode in ("live", "shadow"):
            self.assertIn(LABEL, out[mode], mode)
            self.assertIn("From the check-in sheet", out[mode])
            self.assertIn("read at 08:14", out[mode])
        self.assertNotIn(LABEL, out["off"])
        # A Google Sheet import is not a check-in import: no block, no link.
        self.assertEqual(out["sheet_import"], "")


class Placement(unittest.TestCase):
    """Where the markup sits in the page, which the node tests can't see."""

    def test_the_call_dialog_has_a_slot_and_loads_it(self):
        src = H.page()
        self.assertIn("'<div id=\"call-checkin-wrap-'+callId+'\"></div>'", src)
        self.assertIn("callLoadCheckinSheet(bookingId, callId);", src)

    def test_the_call_dialog_loader_is_never_awaited(self):
        """It must not hold the dialog up (brief: never blocking)."""
        src = H.page()
        self.assertNotIn("await callLoadCheckinSheet", src)

    def test_the_helper_opens_a_new_window_like_open_crew_notes(self):
        src = H.page()
        i = src.index("function openCheckinSheet(")
        body = src[i:i + 400]
        self.assertIn("window.open(hubUrl(", body)
        self.assertIn("'_blank'", body)

    def test_the_intro_div_reads_its_text_from_the_constant(self):
        self.assertIn("""'<div id="ti-intro" style="font-size:12px;color:var(--muted);padding-bottom:12px">' + TI_INTRO_SHEET + '</div>'""",
                      H.page())

    def test_the_off_wording_is_word_for_word_what_it_was(self):
        """Off bookings keep the current text (brief §3). This is the whole
        sentence as it stood before 5.117.0 — if it has to change, change it
        deliberately, here as well."""
        self.assertIn(
            "var TI_INTRO_SHEET   = 'Pull the actual times worked back onto each "
            "call. Read the Google Sheet THE GOAT generated, or upload a finished "
            "workbook — you\\'ll review everything before anything is written.';",
            H.page())

    def test_no_call_parameter_was_invented(self):
        """There is no ?call= deep link on the check-in screen. Don't add one."""
        src = H.page()
        i = src.index("function openCheckinSheet(")
        self.assertNotIn("call=", src[i:i + 400])

# ─────────────────────────────────────────────────────────────────────────────
# /api/ops/checkin-today
# ─────────────────────────────────────────────────────────────────────────────

KEY = "made-up-test-key-90001"


class FakeResp(object):
    def __init__(self, status, body):
        self.status_code = status
        self._body = body

    def json(self):
        return self._body


def call_row(booking_id, call_id, start_iso, name=None):
    """One get-calls-bulk.php row, as fetch_calls_for_ops hands it back."""
    return {"booking_id": booking_id, "call_id": call_id,
            "booking_name": name or ("Show %s" % booking_id),
            "venue": "MCG", "call_name": "Load In",
            "start_iso": start_iso, "required": 4, "booked": 4}


class CheckinTodayRoute(unittest.TestCase):
    """The route. No network: SmartStaff and the edge function are both faked."""

    DAY = "2026-11-21"

    def setUp(self):
        A._checkin_today_cache.clear()
        self.addCleanup(A._checkin_today_cache.clear)
        self.mode_reads = []
        # booking_id -> mode, or an Exception / FakeResp to raise / return.
        self.modes = {}
        self.rows = []
        self.calls_err = None
        p = mock.patch.object(A, "GOAT_CHECKIN_KEY", KEY)
        p.start()
        self.addCleanup(p.stop)

    def _http_get(self, url, **kw):
        bid = int(kw["params"]["booking_id"])
        self.mode_reads.append(bid)
        want = self.modes.get(bid, "off")
        if isinstance(want, Exception):
            raise want
        if isinstance(want, FakeResp):
            return want
        return FakeResp(200, {"booking_id": bid, "mode": want})

    def get(self, cohort="admin", query="", logged_in=True):
        with A.app.test_client() as c:
            with c.session_transaction() as s:
                s["sid"] = "t"
            with mock.patch.multiple(
                     A,
                     get_ss_session=(lambda: object()) if logged_in else (lambda: None),
                     fetch_calls_for_ops=lambda ss, start, end: (
                         (None, self.calls_err) if self.calls_err else (self.rows, None)),
                 ), \
                 mock.patch.object(A.http, "get", self._http_get), \
                 mock.patch.dict(A._ss_identity, {"t": {"cohort": cohort, "usergroupID": 1,
                                                        "user_id": "90001"}}):
                r = c.get("/api/ops/checkin-today?day=" + self.DAY + query)
        return r, r.get_json()

    # ── what reaches the lane ────────────────────────────────────────────────

    def test_shadow_and_live_are_kept_and_off_is_dropped(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00"),
                     call_row(2, 21, self.DAY + "T10:00:00"),
                     call_row(3, 31, self.DAY + "T12:00:00")]
        self.modes = {1: "shadow", 2: "live", 3: "off"}
        r, body = self.get()
        self.assertEqual(r.status_code, 200, body)
        self.assertEqual([b["booking_id"] for b in body["bookings"]], [1, 2])
        self.assertEqual([b["mode"] for b in body["bookings"]], ["shadow", "live"])
        self.assertEqual(body["counts"], {"total": 2, "shadow": 1, "live": 1})
        # Every booking with a call today was LOOKED AT, even the Off one.
        self.assertEqual(sorted(self.mode_reads), [1, 2, 3])

    def test_a_booking_with_no_row_at_all_is_off(self):
        """No row upstream means Off, which means not in the lane."""
        self.rows = [call_row(7, 71, self.DAY + "T09:00:00")]
        self.modes = {7: FakeResp(200, {"booking_id": 7, "mode": None})}
        r, body = self.get()
        self.assertEqual(body["bookings"], [])
        self.assertEqual(body["counts"]["total"], 0)

    def test_rows_carry_what_the_lane_draws(self):
        self.rows = [call_row(5, 51, self.DAY + "T19:30:00", name="Harry Styles")]
        self.modes = {5: "live"}
        r, body = self.get()
        b, = body["bookings"]
        self.assertEqual(b["booking_id"], 5)
        self.assertEqual(b["booking_name"], "Harry Styles")
        self.assertEqual(b["mode"], "live")
        self.assertEqual(b["start_iso"], self.DAY + "T19:30:00")
        self.assertEqual(b["venue"], "MCG")

    def test_one_read_per_booking_not_per_call(self):
        """12312 has nine calls. That is ONE mode read, not nine."""
        self.rows = [call_row(12312, 40028 + i, self.DAY + "T08:00:00") for i in range(9)]
        self.modes = {12312: "live"}
        r, body = self.get()
        self.assertEqual(self.mode_reads, [12312])
        self.assertEqual(len(body["bookings"]), 1)

    def test_a_booking_keeps_its_earliest_call_and_rows_sort_by_it(self):
        self.rows = [call_row(1, 11, self.DAY + "T23:00:00"),
                     call_row(1, 12, self.DAY + "T08:00:00"),   # earliest for 1
                     call_row(1, 13, self.DAY + "T10:00:00"),
                     call_row(2, 21, self.DAY + "T06:00:00")]   # earliest overall
        self.modes = {1: "live", 2: "shadow"}
        r, body = self.get()
        self.assertEqual([b["booking_id"] for b in body["bookings"]], [2, 1])
        self.assertEqual(body["bookings"][1]["start_iso"], self.DAY + "T08:00:00")

    def test_a_booking_with_no_start_iso_still_appears_last(self):
        self.rows = [call_row(1, 11, ""), call_row(2, 21, self.DAY + "T09:00:00")]
        self.modes = {1: "live", 2: "live"}
        r, body = self.get()
        self.assertEqual([b["booking_id"] for b in body["bookings"]], [2, 1])

    def test_a_junk_booking_id_is_skipped(self):
        self.rows = [call_row("not-a-number", 11, self.DAY + "T08:00:00"),
                     call_row(2, 21, self.DAY + "T09:00:00")]
        self.modes = {2: "live"}
        r, body = self.get()
        self.assertEqual([b["booking_id"] for b in body["bookings"]], [2])
        self.assertEqual(self.mode_reads, [2])

    # ── the cap ──────────────────────────────────────────────────────────────

    def test_the_cap_bites_and_says_so(self):
        self.rows = [call_row(i, i * 10, "%sT%02d:00:00" % (self.DAY, i % 24))
                     for i in range(1, 31)]                       # 30 bookings
        self.modes = {i: "live" for i in range(1, 31)}
        r, body = self.get()
        self.assertTrue(body["capped"])
        self.assertEqual(body["seen"], 30)
        self.assertEqual(body["max_bookings"], A.CHECKIN_TODAY_MAX_BOOKINGS)
        self.assertEqual(len(self.mode_reads), A.CHECKIN_TODAY_MAX_BOOKINGS)
        self.assertEqual(len(body["bookings"]), A.CHECKIN_TODAY_MAX_BOOKINGS)

    def test_exactly_the_cap_is_not_capped(self):
        n = A.CHECKIN_TODAY_MAX_BOOKINGS
        self.rows = [call_row(i, i * 10, "%sT%02d:00:00" % (self.DAY, i % 24))
                     for i in range(1, n + 1)]
        self.modes = {i: "live" for i in range(1, n + 1)}
        r, body = self.get()
        self.assertFalse(body["capped"])
        self.assertEqual(body["seen"], n)
        self.assertEqual(len(self.mode_reads), n)

    def test_under_the_cap_is_not_capped(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00")]
        self.modes = {1: "shadow"}
        r, body = self.get()
        self.assertFalse(body["capped"])
        self.assertEqual(body["seen"], 1)

    # ── a mode read that fails ───────────────────────────────────────────────

    def test_a_failed_mode_read_drops_only_that_booking(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00"),
                     call_row(2, 21, self.DAY + "T09:00:00"),
                     call_row(3, 31, self.DAY + "T10:00:00")]
        self.modes = {1: "live",
                      2: FakeResp(503, {"error": "Not configured"}),   # edge fn down
                      3: "shadow"}
        r, body = self.get()
        self.assertEqual(r.status_code, 200, body)
        self.assertEqual([b["booking_id"] for b in body["bookings"]], [1, 3])
        self.assertEqual(body["counts"], {"total": 2, "shadow": 1, "live": 1})

    def test_a_raising_mode_read_drops_only_that_booking(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00"),
                     call_row(2, 21, self.DAY + "T09:00:00")]
        self.modes = {1: RuntimeError("connection reset"), 2: "live"}
        r, body = self.get()
        self.assertEqual([b["booking_id"] for b in body["bookings"]], [2])

    def test_an_unreadable_body_drops_only_that_booking(self):
        class Garbage(FakeResp):
            def json(self):
                raise ValueError("not json")
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00"),
                     call_row(2, 21, self.DAY + "T09:00:00")]
        self.modes = {1: Garbage(200, None), 2: "shadow"}
        r, body = self.get()
        self.assertEqual([b["booking_id"] for b in body["bookings"]], [2])

    def test_every_mode_read_failing_is_an_empty_lane_not_an_error(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00")]
        self.modes = {1: FakeResp(502, {})}
        r, body = self.get()
        self.assertEqual(r.status_code, 200)
        self.assertEqual(body["bookings"], [])
        self.assertNotIn("unavailable", body)

    def test_no_check_in_key_means_an_empty_lane(self):
        """Not an error: a box with no key configured simply has no check-in."""
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00")]
        self.modes = {1: "live"}
        with mock.patch.object(A, "GOAT_CHECKIN_KEY", ""):
            r, body = self.get()
        self.assertEqual(r.status_code, 200)
        self.assertEqual(body["bookings"], [])
        self.assertEqual(self.mode_reads, [])      # never even asked

    # ── soft-fail and gates ─────────────────────────────────────────────────

    def test_smartstaff_down_is_unavailable_not_a_failure(self):
        self.calls_err = "HTTP 500"
        r, body = self.get()
        self.assertEqual(r.status_code, 200)
        self.assertTrue(body["unavailable"])
        self.assertEqual(body["error"], "HTTP 500")
        self.assertEqual(self.mode_reads, [])      # no fan-out on a failed window

    def test_an_upstream_400_surfaces_as_unavailable(self):
        """What the lane showed on 20 Nov: 'Unavailable (HTTP 400)'. The route
        still answers 200 — the 400 is the SECOND hop, and the soft-fail is what
        keeps one bad window from taking the dashboard down."""
        self.calls_err = "HTTP 400"
        r, body = self.get()
        self.assertEqual(r.status_code, 200)
        self.assertTrue(body["unavailable"])
        self.assertEqual(body["error"], "HTTP 400")
        self.assertEqual(self.mode_reads, [])

    def test_not_logged_in(self):
        r, body = self.get(logged_in=False)
        self.assertEqual(r.status_code, 401)

    def test_crew_cannot_read_the_lane(self):
        """The same gate as openCheckinSheet and the single-booking mode route."""
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00")]
        self.modes = {1: "live"}
        r, _ = self.get(cohort="crew")
        self.assertEqual(r.status_code, 403)
        self.assertEqual(self.mode_reads, [])

    def test_ops_and_leadership_can_read_the_lane(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00")]
        self.modes = {1: "live"}
        for cohort in ("admin", "operations", "leadership"):
            A._checkin_today_cache.clear()
            self.mode_reads = []
            r, body = self.get(cohort=cohort)
            self.assertEqual(r.status_code, 200, cohort)
            self.assertEqual(len(body["bookings"]), 1, cohort)

    # ── the cache ───────────────────────────────────────────────────────────

    def test_a_second_look_is_served_from_the_cache(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00")]
        self.modes = {1: "live"}
        r1, b1 = self.get()
        r2, b2 = self.get()
        self.assertEqual(self.mode_reads, [1])     # read once, not twice
        self.assertEqual(b1, b2)

    def test_force_skips_the_cache(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00")]
        self.modes = {1: "live"}
        self.get()
        self.get(query="&force=1")
        self.assertEqual(self.mode_reads, [1, 1])

    def test_an_expired_cache_entry_is_re_read(self):
        self.rows = [call_row(1, 11, self.DAY + "T08:00:00")]
        self.modes = {1: "live"}
        self.get()
        # Age the entry past its TTL rather than sleeping through it.
        for k in list(A._checkin_today_cache):
            _, payload = A._checkin_today_cache[k]
            A._checkin_today_cache[k] = (0, payload)
        self.get()
        self.assertEqual(self.mode_reads, [1, 1])

    def test_the_answer_carries_the_day_and_a_stamp(self):
        self.rows = []
        r, body = self.get()
        self.assertEqual(body["day"], self.DAY)
        self.assertRegex(body["generated_at"], r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$")

    def test_an_empty_day_is_an_empty_lane(self):
        self.rows = []
        r, body = self.get()
        self.assertEqual(body["bookings"], [])
        self.assertEqual(body["counts"]["total"], 0)
        self.assertFalse(body["capped"])


class CheckinTodayWindow(unittest.TestCase):
    """The window asked for is ONE day, with get-calls-bulk.php's EXCLUSIVE end.

    This class exists because of a live bug on 20 Nov 2026: the lane asked for
    (day, day) and every load came back "Unavailable (HTTP 400)". get-calls-bulk
    .php's `end` is exclusive \u2014 api_ops_crewlists says so in as many words \u2014 so
    (day, day) is an EMPTY range and the endpoint refuses it. The first version
    of this test asserted start == end and so certified the bug.

    This lane is the only caller that wants a single day, so it is the only one
    that can trip over the convention. Hence asserting the pair, not reading it."""

    def _window(self, query="?day=2026-11-21"):
        A._checkin_today_cache.clear()
        self.addCleanup(A._checkin_today_cache.clear)
        seen = {}

        def fake(ss, start, end):
            seen["start"], seen["end"] = start, end
            return [], None

        with A.app.test_client() as c:
            with c.session_transaction() as s:
                s["sid"] = "t"
            with mock.patch.multiple(A, get_ss_session=lambda: object(),
                                     fetch_calls_for_ops=fake), \
                 mock.patch.dict(A._ss_identity, {"t": {"cohort": "admin", "usergroupID": 1,
                                                        "user_id": "90001"}}):
                r = c.get("/api/ops/checkin-today" + query)
        return seen, r, r.get_json()

    def test_one_day_is_day_to_the_next_day_end_exclusive(self):
        seen, r, body = self._window()
        self.assertEqual(seen, {"start": "2026-11-21", "end": "2026-11-22"})
        self.assertEqual(r.status_code, 200)

    def test_the_window_is_never_an_empty_range(self):
        """THE REGRESSION. start == end is what produced HTTP 400."""
        seen, _, _ = self._window()
        self.assertNotEqual(seen["start"], seen["end"])

    def test_it_crosses_a_month_end_correctly(self):
        seen, _, _ = self._window("?day=2026-11-30")
        self.assertEqual(seen, {"start": "2026-11-30", "end": "2026-12-01"})

    def test_it_crosses_a_year_end_correctly(self):
        seen, _, _ = self._window("?day=2026-12-31")
        self.assertEqual(seen, {"start": "2026-12-31", "end": "2027-01-01"})

    def test_it_handles_a_leap_day(self):
        seen, _, _ = self._window("?day=2028-02-28")
        self.assertEqual(seen, {"start": "2028-02-28", "end": "2028-02-29"})

    def test_the_answer_reports_the_window_it_asked_for(self):
        """So this class of bug is visible from the browser, not just the log."""
        seen, _, body = self._window()
        self.assertEqual(body["window"], {"start": "2026-11-21", "end": "2026-11-22"})
        self.assertEqual(body["day"], "2026-11-21")

    def test_with_no_day_the_window_starts_today(self):
        seen, _, body = self._window(query="")
        today = datetime.now().strftime("%Y-%m-%d")
        tomorrow = (datetime.now() + timedelta(days=1)).strftime("%Y-%m-%d")
        self.assertEqual(seen, {"start": today, "end": tomorrow})

    def test_a_malformed_day_is_refused_before_smartstaff(self):
        asked = []

        def fake(ss, start, end):
            asked.append((start, end))
            return [], None

        for bad in ("garbage", "2026-13-01", "21-11-2026", "2026-11", ""):
            A._checkin_today_cache.clear()
            with A.app.test_client() as c:
                with c.session_transaction() as s:
                    s["sid"] = "t"
                with mock.patch.multiple(A, get_ss_session=lambda: object(),
                                         fetch_calls_for_ops=fake), \
                     mock.patch.dict(A._ss_identity, {"t": {"cohort": "admin", "usergroupID": 1,
                                                            "user_id": "90001"}}):
                    r = c.get("/api/ops/checkin-today?day=" + bad)
            if bad == "":
                # An empty ?day= falls back to today, like no parameter at all.
                self.assertEqual(r.status_code, 200, bad)
            else:
                self.assertEqual(r.status_code, 400, bad)
                self.assertEqual(r.get_json()["error"], "day must be YYYY-MM-DD")
        # Nothing malformed ever reached SmartStaff.
        self.assertEqual(len(asked), 1)      # only the empty-string fallback


class SharedModeRead(unittest.TestCase):
    """checkin_mode_read — one read, two callers, one idea of 'readable'."""

    def setUp(self):
        p = mock.patch.object(A, "GOAT_CHECKIN_KEY", KEY)
        p.start()
        self.addCleanup(p.stop)

    def test_mode_and_no_error(self):
        with mock.patch.object(A.http, "get", lambda u, **k: FakeResp(200, {"mode": "live"})):
            self.assertEqual(A.checkin_mode_read(90001), ("live", None))

    def test_no_row_reads_off(self):
        with mock.patch.object(A.http, "get", lambda u, **k: FakeResp(200, {})):
            self.assertEqual(A.checkin_mode_read(90001), ("off", None))

    def test_failures_come_back_as_data_with_a_status(self):
        cases = [
            (lambda u, **k: FakeResp(503, {}), 502),
            (lambda u, **k: FakeResp(500, {}), 502),
        ]
        for fake, status in cases:
            with mock.patch.object(A.http, "get", fake):
                mode, err = A.checkin_mode_read(90001)
            self.assertIsNone(mode)
            self.assertEqual(err[1], status)

    def test_a_missing_key_is_a_500_without_a_request(self):
        asked = []
        with mock.patch.object(A, "GOAT_CHECKIN_KEY", ""), \
             mock.patch.object(A.http, "get", lambda u, **k: asked.append(u)):
            mode, err = A.checkin_mode_read(90001)
        self.assertIsNone(mode)
        self.assertEqual(err[1], 500)
        self.assertEqual(asked, [])

    def test_it_sends_the_key_and_the_booking_id(self):
        sent = {}

        def fake(url, **kw):
            sent["url"], sent["kw"] = url, kw
            return FakeResp(200, {"mode": "shadow"})

        with mock.patch.object(A.http, "get", fake):
            A.checkin_mode_read(12312)
        self.assertEqual(sent["url"], A.CHECKIN_BOOKING_MODE_URL)
        self.assertEqual(sent["kw"]["params"], {"booking_id": 12312})
        self.assertEqual(sent["kw"]["headers"], {"X-Goat-Service-Key": KEY})


# ─────────────────────────────────────────────────────────────────────────────
# The lane markup
# ─────────────────────────────────────────────────────────────────────────────

LANE_CONSTS = "\n".join(
    l for l in H.page().splitlines()
    if l.startswith(("const OPS_CHECKIN_MODE_COLORS", "const OPS_CHECKIN_MODE_LABELS"))
)

LANE_STUBS = r"""
var _opsLaneState = {checkin_today: {}};
var unavailable = [], asAt = [], detail = [];
function renderOpsUnavailable(l){ unavailable.push(l.key); }
function setOpsAsAt(t){ asAt.push(t); }
function setOpsLaneDetail(l, has){ detail.push([l.key, has]); }
var LANE = {key: 'checkin_today', label: 'Check-in today'};
"""


@unittest.skipUnless(H.NODE, "node not installed")
class Lane(unittest.TestCase):

    JS = H.extract("checkinSheetLinkHtml", "_tiIsoHHMM",
                   "loadOpsCheckinToday", "renderOpsCheckinToday",
                   "opsCheckinTodaySubhead", "opsCheckinTodayRow")

    def js(self, body):
        return H.run(STUBS + CONSTS + LANE_CONSTS + LANE_STUBS + "\n" + self.JS + "\n" + body)

    def test_empty_shows_a_zero_badge_not_a_hidden_card(self):
        """Mike, 20 Nov 2026: like its neighbours, not hidden."""
        out = self.js(r"""
_opsLaneState.checkin_today = {status:'ready', data:{bookings:[], counts:{total:0}}};
renderOpsCheckinToday(LANE);
console.log(JSON.stringify({badge: _els['ops-badge-checkin_today'].textContent,
                            body: _els['ops-body-checkin_today'].innerHTML,
                            detail: detail}));
""")
        # str(): a real DOM stringifies textContent, the node stub keeps the number.
        self.assertEqual(str(out["badge"]), "0")
        self.assertIn("ops-empty", out["body"])
        self.assertIn("No booking on check-in today.", out["body"])
        # No detail window behind it, ever.
        self.assertEqual(out["detail"], [["checkin_today", False]])

    def _render(self, data):
        return self.js("""
_opsLaneState.checkin_today = {status:'ready', data: %s};
renderOpsCheckinToday(LANE);
console.log(JSON.stringify({badge: _els['ops-badge-checkin_today'].textContent,
                            body: _els['ops-body-checkin_today'].innerHTML}));
""" % data)

    def test_a_row_per_booking_with_chip_time_and_link(self):
        out = self._render("""{bookings:[
  {booking_id:'12291', booking_name:'Harry Styles', mode:'live',   start_iso:'2026-11-23T19:30:00'},
  {booking_id:'12312', booking_name:'Rod Laver',    mode:'shadow', start_iso:'2026-11-21T08:00:00'}],
  counts:{total:2, live:1, shadow:1}}""")
        body = out["body"]
        self.assertEqual(str(out["badge"]), "2")
        self.assertIn("Harry Styles", body)
        self.assertIn("Rod Laver", body)
        self.assertIn("19:30", body)
        self.assertIn("08:00", body)
        self.assertIn(">Live<", body)
        self.assertIn(">Shadow<", body)
        self.assertIn("openCheckinSheet('12291')", body)
        self.assertIn("openCheckinSheet('12312')", body)
        self.assertEqual(body.count(LABEL), 2)

    def test_rows_are_static_and_the_link_is_the_target(self):
        """A whole row that opened another system would be a trap."""
        out = self._render("""{bookings:[
  {booking_id:'1', booking_name:'A', mode:'live', start_iso:'2026-11-21T08:00:00'}],
  counts:{total:1, live:1}}""")
        self.assertIn("ops-row ops-row--static", out["body"])
        self.assertNotIn("opsRowClick", out["body"])
        # The only onclick in the row is the link's.
        self.assertEqual(out["body"].count("onclick="), 1)
        self.assertIn("onclick=\"openCheckinSheet(", out["body"])

    def test_the_subhead_counts_the_modes(self):
        out = self.js(r"""
console.log(JSON.stringify({
  two:  opsCheckinTodaySubhead({bookings:[1,2], counts:{total:2, live:1, shadow:1}}),
  one:  opsCheckinTodaySubhead({bookings:[1],   counts:{total:1, live:1}}),
  shad: opsCheckinTodaySubhead({bookings:[1],   counts:{total:1, shadow:1}})}));
""")
        self.assertEqual(out["two"], "2 bookings \u00b7 1 Live \u00b7 1 Shadow")
        self.assertEqual(out["one"], "1 booking \u00b7 1 Live")
        self.assertEqual(out["shad"], "1 booking \u00b7 1 Shadow")

    def test_the_capped_line_says_what_is_not_on_screen(self):
        out = self.js(r"""
console.log(JSON.stringify({
  capped: opsCheckinTodaySubhead({bookings:[1], counts:{total:1, live:1},
                                  capped:true, max_bookings:25, seen:40}),
  plain:  opsCheckinTodaySubhead({bookings:[1], counts:{total:1, live:1},
                                  capped:false, max_bookings:25, seen:3})}));
""")
        self.assertIn("checked the first 25 of 40 bookings with a call today", out["capped"])
        self.assertNotIn("checked the first", out["plain"])

    def test_a_booking_name_is_escaped(self):
        out = self._render("""{bookings:[
  {booking_id:'1', booking_name:'<script>x</script>', mode:'live', start_iso:'2026-11-21T08:00:00'}],
  counts:{total:1, live:1}}""")
        self.assertNotIn("<script>x", out["body"])
        self.assertIn("&lt;script", out["body"])

    def test_a_missing_start_time_reads_as_a_dash(self):
        out = self._render("""{bookings:[
  {booking_id:'1', booking_name:'A', mode:'live', start_iso:''}],
  counts:{total:1, live:1}}""")
        self.assertIn("\u2014", out["body"])

    def test_crew_get_a_row_with_no_link(self):
        out = self.js(r"""
window._cohort = 'crew';
_opsLaneState.checkin_today = {status:'ready', data:{bookings:[
  {booking_id:'1', booking_name:'A', mode:'live', start_iso:'2026-11-21T08:00:00'}],
  counts:{total:1, live:1}}};
renderOpsCheckinToday(LANE);
console.log(JSON.stringify({body: _els['ops-body-checkin_today'].innerHTML}));
""")
        self.assertIn("ops-row", out["body"])
        self.assertNotIn("openCheckinSheet", out["body"])
        self.assertNotIn(LABEL, out["body"])

    def test_the_loader_reads_the_route_and_stamps_as_at(self):
        out = self.js(r"""
fetch = function(url){
  fetched.push([url, 'GET']);
  return Promise.resolve({ok:true, json: function(){ return Promise.resolve(
    {bookings:[{booking_id:'1', booking_name:'A', mode:'live', start_iso:'2026-11-21T08:00:00'}],
     counts:{total:1, live:1}, generated_at:'2026-11-21T08:05:00'}); }});
};
loadOpsCheckinToday(LANE).then(function(){
  console.log(JSON.stringify({fetched: fetched, asAt: asAt,
                              status: _opsLaneState.checkin_today.status,
                              badge: _els['ops-badge-checkin_today'].textContent}));
});
""")
        self.assertEqual(out["fetched"], [["/api/ops/checkin-today", "GET"]])
        self.assertEqual(out["asAt"], ["2026-11-21T08:05:00"])
        self.assertEqual(out["status"], "ready")
        self.assertEqual(str(out["badge"]), "1")

    def test_refresh_forces_past_the_cache(self):
        out = self.js(r"""
fetch = function(url){
  fetched.push([url, 'GET']);
  return Promise.resolve({ok:true, json: function(){ return Promise.resolve(
    {bookings:[], counts:{total:0}}); }});
};
loadOpsCheckinToday(LANE, true).then(function(){
  console.log(JSON.stringify({fetched: fetched}));
});
""")
        self.assertEqual(out["fetched"], [["/api/ops/checkin-today?force=1", "GET"]])

    def test_unavailable_renders_the_unavailable_card(self):
        out = self.js(r"""
fetch = function(){ return Promise.resolve({ok:true, json: function(){
  return Promise.resolve({unavailable:true, error:'HTTP 500'}); }}); };
loadOpsCheckinToday(LANE).then(function(){
  console.log(JSON.stringify({unavailable: unavailable,
                              status: _opsLaneState.checkin_today.status,
                              error: _opsLaneState.checkin_today.error}));
});
""")
        self.assertEqual(out["unavailable"], ["checkin_today"])
        self.assertEqual(out["status"], "unavailable")
        self.assertEqual(out["error"], "HTTP 500")

    def test_a_thrown_load_is_isolated_to_this_lane(self):
        out = self.js(r"""
fetch = function(){ return Promise.reject(new Error('offline')); };
loadOpsCheckinToday(LANE).then(function(){
  console.log(JSON.stringify({unavailable: unavailable,
                              status: _opsLaneState.checkin_today.status,
                              error: _opsLaneState.checkin_today.error}));
});
""")
        self.assertEqual(out["unavailable"], ["checkin_today"])
        self.assertEqual(out["error"], "offline")


class LanePlacement(unittest.TestCase):
    """Where the lane sits, and the fields it declares."""

    def test_it_is_on_bookings_and_calls(self):
        src = H.page()
        i = src.index("{ key:'checkin_today'")
        entry = src[i:i + 300]
        self.assertIn("board:'calls'", entry)
        self.assertIn("label:'Check-in today'", entry)

    def test_it_declares_empty_dims_so_the_loader_can_walk_them(self):
        src = H.page()
        i = src.index("{ key:'checkin_today'")
        self.assertIn("dims:[]", src[i:i + 300])

    def test_its_cohorts_match_the_route_and_the_opener(self):
        src = H.page()
        i = src.index("{ key:'checkin_today'")
        self.assertIn("cohorts:['admin','operations','leadership']", src[i:i + 300])

    def test_a_no_detail_lane_gets_no_tooltip(self):
        self.assertIn("lane.noDetail ? ''", H.page())



if __name__ == "__main__":
    unittest.main()

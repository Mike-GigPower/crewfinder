#!/usr/bin/env python3
"""Check-in Live mode in THE GOAT (BRIEF-checkin-live-mode, Part B, 5.114.0).

  - POST /api/booking/<id>/checkin-mode accepts live (switcher only), still
    refuses anything else before the key check, and GET passes live through
  - the switch: Off / Shadow / Live, each mode highlights its own button and
    shows its own note; Live asks first with the brief's text
  - the badge for everyone else, per mode
  - Generate Google Sheet on a Live booking warns, doesn't block; other modes
    and an unreadable mode don't ask
  - Import Times: the check-in button first, with the Live / Shadow line

Made-up booking 90001 and switcher id 90001 only (Crew Hub faked).
Run: python3 test_checkin_live_mode.py
"""
import json
import unittest
from unittest import mock

import app as A
import js_harness as H

SWITCHER = 90001
KEY = "made-up-test-key-90001"


class FakeResp(object):
    def __init__(self, status, body):
        self.status_code = status
        self._body = body

    def json(self):
        return self._body


class ModeRoutes(unittest.TestCase):

    def setUp(self):
        self.posts, self.gets = [], []
        self.answer = FakeResp(200, {"mode": "live"})
        p = mock.patch.multiple(A, GOAT_CHECKIN_KEY=KEY, CHECKIN_PILOT_SWITCHERS={SWITCHER})
        p.start()
        self.addCleanup(p.stop)

    def _post(self, url, **kw):
        self.posts.append((url, kw))
        return self.answer

    def _get(self, url, **kw):
        self.gets.append((url, kw))
        return self.answer

    def call(self, method, body=None, user_id=SWITCHER, cohort="admin"):
        with A.app.test_client() as c:
            with c.session_transaction() as s:
                s["sid"] = "t"
            with mock.patch.multiple(A, get_ss_session=lambda: object()), \
                 mock.patch.object(A.http, "post", self._post), \
                 mock.patch.object(A.http, "get", self._get), \
                 mock.patch.dict(A._ss_identity, {"t": {"cohort": cohort, "usergroupID": 1,
                                                        "user_id": str(user_id)}}):
                if method == "POST":
                    r = c.post("/api/booking/90001/checkin-mode", json=body)
                else:
                    r = c.get("/api/booking/90001/checkin-mode")
        return r, r.get_json()

    def test_valid_modes(self):
        self.assertEqual(A.CHECKIN_VALID_MODES, {"off", "shadow", "live"})

    def test_switcher_can_set_live(self):
        r, body = self.call("POST", {"mode": "Live"})
        self.assertEqual(r.status_code, 200, body)
        self.assertEqual(body, {"booking_id": 90001, "mode": "live", "can_switch": True})
        (url, kw), = self.posts
        self.assertEqual(url, A.CHECKIN_BOOKING_MODE_URL)
        self.assertEqual(kw["json"], {"booking_id": 90001, "mode": "live", "set_by_user_id": SWITCHER})
        self.assertEqual(kw["headers"], {"X-Goat-Service-Key": KEY})

    def test_off_and_shadow_still_accepted(self):
        for m in ("off", "shadow"):
            r, _ = self.call("POST", {"mode": m})
            self.assertEqual(r.status_code, 200, m)

    def test_unknown_mode_refused_before_the_key_and_crew_hub(self):
        with mock.patch.object(A, "GOAT_CHECKIN_KEY", ""):
            for m in ("official", "", None, "lives"):
                r, body = self.call("POST", {"mode": m})
                self.assertEqual(r.status_code, 422, m)
                self.assertEqual(body["error"], "Mode must be off, shadow or live.")
        self.assertEqual(self.posts, [])

    def test_live_is_still_switcher_only(self):
        r, _ = self.call("POST", {"mode": "live"}, user_id=90002)
        self.assertEqual(r.status_code, 403)
        self.assertEqual(self.posts, [])

    def test_get_passes_live_through(self):
        r, body = self.call("GET", user_id=90002, cohort="operations")
        self.assertEqual(r.status_code, 200)
        self.assertEqual((body["mode"], body["can_switch"]), ("live", False))


STUBS = r"""
function escapeHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
var shown = [], asked = [], fetched = [], answer = true, modeAnswer = 'live', modeOk = true;
function showFeedback(m){ shown.push(m); }
function goatAsk(title, text, ok){ asked.push([title, text, ok]); return Promise.resolve(answer); }
var window = {_cohort: 'admin'};
var _els = {};
var document = {getElementById: function(id){
  return _els[id] || (_els[id] = {id: id, style: {}, innerHTML: '', textContent: '', isConnected: true});
}};
function openEntityModal(){} function gsheetLoadStatus(){} function _gsheetShowExists(){}
function fetch(url, opts){
  fetched.push([url, opts && opts.method || 'GET', opts && opts.body || null]);
  if(/checkin-mode$/.test(url) && !(opts && opts.method === 'POST')){
    if(!modeOk) return Promise.reject(new Error('down'));
    return Promise.resolve({ok: true, json: function(){ return Promise.resolve({mode: modeAnswer, can_switch: true}); }});
  }
  if(/checkin-mode$/.test(url)){
    return Promise.resolve({ok: true, json: function(){ return Promise.resolve({mode: 'live'}); }});
  }
  return new Promise(function(){});   // generate-gsheet: never answers in the test
}
"""


@unittest.skipUnless(H.NODE, "node not installed")
class Screen(unittest.TestCase):

    JS = H.extract("bkCheckinControl", "bkCheckinMarkup", "bkLoadCheckinMode", "bkSetCheckinMode",
                   "_gsheetLiveDeclined", "generateGoogleSheet",
                   "_tiCheckinButtonHtml", "_tiCheckinModeLine")
    CONSTS = r"""
var _bkCheckinBusy = {};
var _gsheetInFlight = false;
""" + "\n".join(l for l in H.page().splitlines()
                if l.startswith(("var BK_CHECKIN_LIVE_CONFIRM", "var BK_CHECKIN_MODE_LABEL",
                                 "var GSHEET_LIVE_WARNING", "const TI_CHECKIN_SHADOW_LINE",
                                 "const TI_CHECKIN_LIVE_LINE")))

    def js(self, body):
        return H.run(STUBS + self.CONSTS + "\n" + self.JS + "\n" + body)

    def test_constants_found(self):
        for c in ("BK_CHECKIN_LIVE_CONFIRM", "BK_CHECKIN_MODE_LABEL", "GSHEET_LIVE_WARNING",
                  "TI_CHECKIN_SHADOW_LINE", "TI_CHECKIN_LIVE_LINE"):
            self.assertIn(c, self.CONSTS)

    def test_switch_highlights_each_mode_and_shows_its_note(self):
        out = self.js(r"""
var r = {};
['off','shadow','live'].forEach(function(m){
  var h = bkCheckinControl(m, '90001', 'bk-checkin-wrap');
  var btns = h.match(/<button[^>]*>[^<]*<\/button>/g);
  r[m] = {labels: btns.map(function(b){ return b.replace(/<[^>]*>/g,''); }),
          selected: btns.filter(function(b){ return b.indexOf('border-gold') >= 0; })
                        .map(function(b){ return b.replace(/<[^>]*>/g,''); }),
          clickable: btns.filter(function(b){ return b.indexOf('bkSetCheckinMode') >= 0; })
                         .map(function(b){ return b.replace(/<[^>]*>/g,''); }),
          note: h.replace(/^.*<div style="font-size:10px[^>]*>/, '').replace(/<\/div>$/, '')};
});
console.log(JSON.stringify(r));
""")
        self.assertEqual(out["live"]["labels"], ["Off", "Shadow", "Live"])
        self.assertEqual(out["off"]["selected"], ["Off"])
        self.assertEqual(out["shadow"]["selected"], ["Shadow"])
        self.assertEqual(out["live"]["selected"], ["Live"])
        self.assertEqual(out["live"]["clickable"], ["Off", "Shadow"])
        self.assertEqual(out["off"]["note"], "Off for this booking. The Google Sheet is the only timesheet.")
        self.assertEqual(out["shadow"]["note"], "Running alongside the Google Sheet. The sheet is still the record.")
        self.assertEqual(out["live"]["note"], "Live — the check-in sheet is the record. Import times from check-in.")

    def test_badge_for_everyone_else_per_mode(self):
        out = self.js(r"""
var r = {};
['off','shadow','live'].forEach(function(m){
  r[m] = bkCheckinMarkup({mode: m, can_switch: false}, '90001', 'h');
});
r.switcher_live = bkCheckinMarkup({mode: 'live', can_switch: true}, '90001', 'h');
window._cohort = 'operations';
r.unknown_ops = bkCheckinMarkup('unknown', '90001', 'h');
console.log(JSON.stringify(r));
""")
        self.assertEqual(out["off"], "")
        self.assertIn("Live check-in pilot — the Google Sheet is still the record", out["shadow"])
        self.assertIn("Live check-in — the check-in sheet is the record", out["live"])
        self.assertNotIn("Google Sheet is still", out["live"])
        self.assertIn("Live — the check-in sheet is the record. Import times from check-in.", out["switcher_live"])
        self.assertEqual(out["unknown_ops"], "")

    def test_live_asks_first_and_cancel_sends_nothing(self):
        out = self.js(r"""
answer = false;
bkSetCheckinMode('90001', 'h', 'live').then(function(){
  console.log(JSON.stringify({asked: asked, posts: fetched.filter(function(f){ return f[1]==='POST'; }),
                              busy: Object.keys(_bkCheckinBusy)}));
});
""")
        self.assertEqual(out["asked"], [["Make check-in the record?",
            "Make check-in the record for this booking? No Google Sheet. Times reach SmartStaff only through Import from check-in.",
            "Make it Live"]])
        self.assertEqual(out["posts"], [])
        self.assertEqual(out["busy"], [])

    def test_live_confirmed_posts_live(self):
        out = self.js(r"""
bkSetCheckinMode('90001', 'h', 'live').then(function(){
  console.log(JSON.stringify({posts: fetched.filter(function(f){ return f[1]==='POST'; }), shown: shown}));
});
""")
        self.assertEqual(len(out["posts"]), 1)
        self.assertEqual(json.loads(out["posts"][0][2]), {"mode": "live"})
        self.assertEqual(out["shown"], ["Live check-in: Live"])

    def test_shadow_and_off_do_not_ask(self):
        out = self.js(r"""
bkSetCheckinMode('90001', 'h', 'shadow').then(function(){
  return bkSetCheckinMode('90001', 'h', 'off');
}).then(function(){
  console.log(JSON.stringify({asked: asked.length, posts: fetched.filter(function(f){ return f[1]==='POST'; }).length, shown: shown}));
});
""")
        self.assertEqual(out, {"asked": 0, "posts": 2, "shown": ["Live check-in: Shadow", "Live check-in: Off"]})

    def _generate(self, setup):
        return self.js(setup + r"""
generateGoogleSheet('90001').then(function(){});
setTimeout = function(f){ f(); };
Promise.resolve().then(function(){}).then(function(){}).then(function(){}).then(function(){})
  .then(function(){}).then(function(){}).then(function(){}).then(function(){
  console.log(JSON.stringify({asked: asked, inflight: _gsheetInFlight,
    generated: fetched.filter(function(f){ return /generate-gsheet/.test(f[0]); }).length}));
});
""")

    def test_generate_on_live_warns_and_cancel_stops(self):
        out = self._generate("answer = false;")
        self.assertEqual(out["asked"], [["Live check-in booking",
            "This booking's times are recorded on the check-in sheet. Generate a Google Sheet anyway?",
            "Generate anyway"]])
        self.assertEqual(out["generated"], 0)
        self.assertFalse(out["inflight"])

    def test_generate_on_live_goes_ahead_when_confirmed(self):
        out = self._generate("answer = true;")
        self.assertEqual(len(out["asked"]), 1)
        self.assertEqual(out["generated"], 1)

    def test_generate_other_modes_and_unknown_do_not_ask(self):
        for setup in ("modeAnswer = 'shadow';", "modeAnswer = 'off';", "modeOk = false;"):
            out = self._generate(setup)
            self.assertEqual((out["asked"], out["generated"]), ([], 1), setup)

    def test_create_another_anyway_is_not_asked_twice(self):
        out = self.js(r"""
generateGoogleSheet('90001', true);
Promise.resolve().then(function(){}).then(function(){}).then(function(){
  console.log(JSON.stringify({asked: asked.length, modeReads: fetched.filter(function(f){ return /checkin-mode/.test(f[0]); }).length,
    generated: fetched.filter(function(f){ return /generate-gsheet\?force=1/.test(f[0]); }).length}));
});
""")
        self.assertEqual(out, {"asked": 0, "modeReads": 0, "generated": 1})

    def test_import_button_line_per_mode(self):
        out = self.js(r"""
console.log(JSON.stringify({live: _tiCheckinButtonHtml('90001','live'),
  shadow: _tiCheckinButtonHtml('90001','shadow'), other: _tiCheckinModeLine('off')}));
""")
        self.assertTrue(out["live"].startswith('<div onclick="tiLoadCheckin('))
        self.assertIn("Live booking — the check-in sheet is the record.", out["live"])
        self.assertNotIn("Shadow booking", out["live"])
        self.assertIn("Shadow booking — check these times carefully", out["shadow"])
        self.assertEqual(out["other"], "")

    def test_check_in_button_sits_above_the_google_sheet(self):
        src = H.page()
        i = src.index("'<div id=\"ti-checkin-slot\"></div>'")
        j = src.index("📊 Import from the Google Sheet")
        self.assertLess(i, j)


if __name__ == "__main__":
    unittest.main()

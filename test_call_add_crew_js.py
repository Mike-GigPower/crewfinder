"""+ Add Crew in the Call dialog (BRIEF-call-add-crew).

Runs the page's own functions under node (js_harness) with fetch, the roster
and the Finder's panel body stubbed. Skips when node isn't installed.

What these pin down:
  * the shared feed walk: Crew Finder's output is unchanged, Add Crew walks
    the whole booking and (to match what /api/goat/add-crew writes) follows
    recommended edges too;
  * the verdict: red blocks, a clash on a fed call / a policy warning / a full
    call each need ONE extra click between them, never two;
  * the request bodies: one person, this call only, soft calls empty, and the
    Finder's selectedCalls / consideredCalls never touched;
  * the over-warning (409) still asks, and Esc while it asks doesn't close the
    Add Crew overlay underneath.
"""

import re
import unittest

import js_harness as H

FUNCS = H.extract(
    "feedClosure", "_finderDownstreamCalls",
    "cacOverlay", "cacOnKey", "closeCallAddCrew", "cacHeader", "cacBtn",
    "openCallAddCrew", "cacRenderPrompt", "cacMatch", "cacCheck",
    "cacBuildTargets", "cacFindEntry", "cacVerdict", "cacRenderHeld",
    "cacPanelBody", "cacRenderPanel", "cacAdd", "cacSetBusy",
    "cacRenderOutcome", "offerResultTally",
) if H.NODE else ""


def _decl(name, open_c, close_c):
    src = H.page()
    i = src.index("var %s = %s" % (name, open_c))
    j = src.index("%s;" % close_c, i)
    return src[i:j + 2]


CONSTS = (_decl("CAC_STATUS_LABEL", "{", "}") + "\nvar _cacState = null;\n") if H.NODE else ""

# A DOM just rich enough for markup built with innerHTML: every id="…" in
# assigned markup becomes an element, ` disabled` on it sets .disabled, and
# insertAdjacentHTML appends. Inputs keep a value the test sets.
PRELUDE = r"""
var window = globalThis;
var _els = {};
var _listeners = [];
function _mk(id){
  var el = { id: id, value: '', textContent: '', disabled: false, style: {}, _html: '',
             focus: function(){}, remove: function(){ if(this.id) delete _els[this.id]; },
             addEventListener: function(){}, appendChild: function(){},
             querySelector: function(){ return null; },
             insertAdjacentHTML: function(pos, h){ this.innerHTML = this._html + h; } };
  Object.defineProperty(el, 'innerHTML', {
    get: function(){ return this._html; },
    set: function(h){
      this._html = h;
      var re = /<(\w+)[^>]*\bid="([^"]+)"([^>]*)>/g, m;
      while((m = re.exec(h))){
        var tag = m[0];
        var child = _els[m[2]] && m[2] === 'cac-name' ? _els[m[2]] : _mk(m[2]);
        child.disabled = /\sdisabled[\s>]/.test(tag) || /\sdisabled$/.test(tag.slice(0,-1));
        var t = h.slice(m.index + tag.length).match(/^([^<]*)/);
        child.textContent = t ? t[1] : '';
        var v = tag.match(/\bvalue="([^"]*)"/); if(v && m[2] !== 'cac-name') child.value = v[1];
        _els[m[2]] = child;
      }
    }
  });
  return el;
}
var document = {
  body: { appendChild: function(el){ if(el.id) _els[el.id] = el; } },
  getElementById: function(id){ return _els[id] || null; },
  createElement: function(){ return _mk(''); },
  addEventListener: function(t, f){ _listeners.push(f); },
  removeEventListener: function(t, f){ _listeners = _listeners.filter(function(x){ return x !== f; }); },
};
function escapeHtml(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function decodeEntities(s){ return s == null ? '' : String(s).replace(/&#39;/g, "'").replace(/&amp;/g, '&'); }

// The Finder's state — must come out exactly as it went in.
var selectedCalls = new Map([['999', {call_id:'999'}]]);
var consideredCalls = new Map();
var currentResult = {targets: [{call_id: 'FINDER'}]};
var _panelTargets = null;
function crewDetailBody(c){ _panelTargets = currentResult.targets; return '<panel '+c.name+'>'; }

var _cfRoster = [];
var _rosterLoads = 0;
function loadCfRoster(){ _rosterLoads++; _cfRoster = [
  {id: 9734, name: "O&#39;Lack, Michael", ein: 5925},
  {id: 111,  name: 'Foster, Aysha', ein: 1}]; return Promise.resolve(); }

var _over = [];
var _overAnswer = true;
function goatOverConfirm(w){
  _over.push(w);
  // While it asks, Esc must reach it, not close Add Crew.
  _listeners.forEach(function(f){ f({key:'Escape', preventDefault:function(){}, stopImmediatePropagation:function(){}}); });
  return Promise.resolve(_overAnswer);
}
var _reloaded = [];
function openCallDialog(b, c){ _reloaded.push([b, c]); }

// fetch: /api/call/<b>/<c> details, /api/availability, /api/goat/add-crew.
var _calls = {};   // call_id -> detail (or {error})
var _avail = null;
var _add = [];     // queue of {status, json}
var _sent = [];
window.fetch = function(u, o){
  var body = null; try{ body = JSON.parse((o && o.body) || 'null'); }catch(e){}
  _sent.push({url: u, body: body});
  var st = 200, j;
  var m = u.match(/^\/api\/call\/(\d+)\/(\d+)$/);
  if(m) j = _calls[m[2]] || {error: 'no such call'};
  else if(u === '/api/availability') j = _avail;
  else if(u === '/api/goat/add-crew'){ var n = _add.shift(); st = n.status; j = n.json; }
  return Promise.resolve({ok: st >= 200 && st < 300, status: st, json: function(){ return Promise.resolve(j); }});
};
var fetch = window.fetch;

function _detail(id, name, s, e){ return {call_name: name, start_dt: s, end_dt: e, venue: 'Marvel Stadium'}; }
_calls['39795'] = _detail('39795', 'General', '2026-11-28T10:00:00', '2026-11-28T18:00:00');
_calls['39819'] = _detail('39819', 'Load Out', '2026-11-28T22:00:00', '2026-11-29T03:00:00');

window._cohort = 'admin';
window._callAddCtx = {bookingId: '12291', callId: '39795', callName: 'General',
                      crew: [{id: 111, name: 'Foster, Aysha', status: 'confirmed'},
                             {id: 222, name: 'Nobody, Ann', status: 'declined'}],
                      required: 10, filled: 9};
window._curCallBookingCalls = [
  {call_id: 39795, call_name: 'General',  feeds: [39819, 40001], feeds_recommended: [40001]},
  {call_id: 39819, call_name: 'Load Out', feeds: [], feeds_recommended: []},
  {call_id: 40001, call_name: 'Show',     feeds: [], feeds_recommended: []},
];
_calls['40001'] = _detail('40001', 'Show', '2026-11-28T19:00:00', '2026-11-28T22:00:00');

function _entry(o){ return Object.assign({id: 9734, name: "O'Lack, Michael", detail: '',
  call_results: [{call_id: '39795', call_name: 'General', available: true, detail: ''},
                 {call_id: '39819', call_name: 'Load Out', available: true, detail: ''},
                 {call_id: '40001', call_name: 'Show', available: true, detail: ''}],
  conflict_warnings: [], offer_clashes: [], induction_warnings: []}, o || {}); }
function _result(bucket, e){
  var r = {available: [], conflicts: [], skipped: [], location_unknown: [],
           targets: [{call_id:'39795', call_name:'General', start_dt:'2026-11-28T10:00:00', end_dt:'2026-11-28T18:00:00', venue:'Marvel Stadium'},
                     {call_id:'39819', call_name:'Load Out', start_dt:'2026-11-28T22:00:00', end_dt:'2026-11-29T03:00:00', venue:'Marvel Stadium'}]};
  r[bucket].push(e); return r;
}
function _type(name){ _els['cac-name'].value = name; }
function _state(){
  var a = _els['cac-add'], c = _els['cac-confirm'];
  return { box: (_els['cac-box']||{}).innerHTML || '', msg: (_els['cac-msg']||{}).textContent || '',
           add: a ? {text: a.textContent, disabled: a.disabled} : null,
           conf: c ? {text: c.textContent, disabled: c.disabled} : null,
           overlay: !!_els['cac-overlay'], sent: _sent.slice(), over: _over.slice(), reloaded: _reloaded.slice(),
           err: (_els['cac-err']||{}).textContent || '',
           finderSel: Array.from(selectedCalls.keys()), finderCons: consideredCalls.size,
           finderCR: currentResult.targets[0].call_id, panelTargets: _panelTargets,
           rosterLoads: _rosterLoads };
}
async function _open(){ await openCallAddCrew('12291', '39795'); }
function _done(){ console.log(JSON.stringify(_state())); }
"""


def run(script):
    return H.run(PRELUDE + CONSTS + FUNCS + "\n(async function(){\n" + script + "\n})().catch(function(e){ console.log(JSON.stringify({err: String(e && e.stack || e)})); });")


def _adds(out):
    return [s for s in out["sent"] if s["url"] == "/api/goat/add-crew"]


def _avails(out):
    return [s for s in out["sent"] if s["url"] == "/api/availability"]


class ButtonTests(unittest.TestCase):

    def test_button_is_admin_only_and_not_on_cancelled_calls(self):
        """Static: the button sits after Find Crew with both guards."""
        src = H.page()
        i = src.index("entityBtn('🔍 Find Crew'")
        seg = src[i:i + 600]
        self.assertRegex(seg, r"\(isCancelled \|\| window\._cohort !== 'admin'\) \? '' : entityBtn\('＋ Add Crew'")
        self.assertIn("openCallAddCrew('\"+bookingId+\"','\"+callId+\"')", seg)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_non_admin_gets_nothing(self):
        out = run("window._cohort = 'operations'; await _open(); _done();")
        self.assertFalse(out["overlay"])
        self.assertEqual(out["sent"], [])


class FeedWalkTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_finder_walk_unchanged(self):
        """Locked edges only, on-screen calls only, cycles end, no upstream."""
        out = H.run(FUNCS and H.extract("feedClosure", "_finderDownstreamCalls") + r"""
        var window = globalThis;
        window._finderCalls = [
          {call_id: 1, feeds: [2, 5], feeds_recommended: [5]},
          {call_id: 2, feeds: [3, 1], feeds_recommended: []},
          {call_id: 3, feeds: [4],    feeds_recommended: []},
          {call_id: 5, feeds: [],     feeds_recommended: []},
        ];   // 4 is not on screen
        var a = _finderDownstreamCalls({call_id: 1}).map(function(c){ return c.call_id; });
        var b = _finderDownstreamCalls({call_id: 3}).map(function(c){ return c.call_id; });
        console.log(JSON.stringify({a: a, b: b}));
        """)
        self.assertEqual(out["a"], [2, 3])
        self.assertEqual(out["b"], [])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_add_crew_walk_follows_every_edge_the_server_writes(self):
        out = H.run(H.extract("feedClosure") + r"""
        var calls = [
          {call_id: 1, feeds: [2, 5], feeds_recommended: [5]},
          {call_id: 2, feeds: [3, 1], feeds_recommended: []},
          {call_id: 3, feeds: [],     feeds_recommended: []},
          {call_id: 5, feeds: [],     feeds_recommended: []},
          {call_id: 9, feeds: [1],    feeds_recommended: []},
        ];
        console.log(JSON.stringify(feedClosure(1, calls, true).map(function(c){ return c.call_id; })));
        """)
        self.assertEqual(sorted(out), [2, 3, 5])   # 9 is upstream: never


class PromptTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_unknown_name_checks_nothing(self):
        out = run("await _open(); _type('Nobody, Atall'); await cacCheck(); _done();")
        self.assertIn('No crew member called "Nobody, Atall"', out["msg"])
        self.assertEqual(_avails(out), [])
        self.assertEqual(out["rosterLoads"], 1)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_match_is_case_insensitive_and_decodes_entities(self):
        out = run("""
        _avail = _result('available', _entry());
        await _open(); _type("o'lack, michael"); await cacCheck(); _done();""")
        self.assertEqual(len(_avails(out)), 1)
        self.assertIn("O&#39;Lack, Michael", out["box"])   # decoded, then escaped once
        self.assertNotIn("&amp;#39;", out["box"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_already_confirmed_needs_no_check(self):
        out = run("await _open(); _type('Foster, Aysha'); await cacCheck(); _done();")
        self.assertEqual(_avails(out), [])
        self.assertIn("already on this call — Confirmed", out["box"])
        self.assertIsNone(out["add"])
        self.assertNotIn("Unconfirmed in Booked Crew", out["box"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_declined_gets_the_reoffer_hint(self):
        out = run("""
        _cfRoster = [{id: 222, name: 'Nobody, Ann'}];
        await _open(); _type('Nobody, Ann'); await cacCheck(); _done();""")
        self.assertIn("Declined", out["box"])
        self.assertIn("change their status to Unconfirmed in Booked Crew", out["box"])


class CheckRequestTests(unittest.TestCase):

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_availability_body_and_finder_state(self):
        out = run("""
        _avail = _result('available', _entry());
        await _open(); _type("O'Lack, Michael"); await cacCheck(); _done();""")
        body = _avails(out)[0]["body"]
        self.assertEqual(body["only_crew_ids"], [9734])
        self.assertEqual(body["soft_call_ids"], [])
        self.assertEqual([c["call_id"] for c in body["calls"]], ["39795", "39819", "40001"])
        for c in body["calls"]:
            self.assertTrue(c["start_dt"] and c["end_dt"] and c["venue"])
        # Finder state untouched, including after the panel's currentResult swap.
        self.assertEqual(out["finderSel"], ["999"])
        self.assertEqual(out["finderCons"], 0)
        self.assertEqual(out["finderCR"], "FINDER")
        self.assertEqual(out["panelTargets"][0]["call_id"], "39795")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_unreadable_fed_call_is_named_not_fatal(self):
        out = run("""
        delete _calls['40001'];
        _avail = _result('available', _entry());
        await _open(); _type("O'Lack, Michael"); await cacCheck(); _done();""")
        self.assertEqual([c["call_id"] for c in _avails(out)[0]["body"]["calls"]], ["39795", "39819"])
        self.assertIn("Couldn't check #40001", out["box"])


class VerdictTests(unittest.TestCase):

    def _panel(self, setup, then="_done();"):
        return run(setup + """
        await _open(); _type("O'Lack, Michael"); await cacCheck(); """ + then)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_free_one_click_sends(self):
        out = self._panel("""
        window._callAddCtx.filled = 3;
        _avail = _result('available', _entry());
        _add.push({status: 200, json: {results: [{success: true, crew: "O'Lack", call: 'General'}]}});""",
            "var mid = _state(); await cacAdd(true); var o = _state(); o.mid = mid; console.log(JSON.stringify(o));")
        self.assertIn(">Available<", out["mid"]["box"])
        adds = _adds(out)
        self.assertEqual(len(adds), 1)
        b = adds[0]["body"]
        self.assertEqual(b["crew"], [{"name": "O&#39;Lack, Michael", "crew_id": "9734"}])
        self.assertEqual([c["call_id"] for c in b["calls"]], ["39795"])
        self.assertTrue(b["confirm"])
        self.assertFalse(b["ack_over"])
        self.assertEqual(out["reloaded"], [["12291", "39795"]])
        self.assertIn("added and confirmed", out["box"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_red_on_this_call_disables_both(self):
        out = self._panel("""
        window._callAddCtx.filled = 3;
        var e = _entry({detail: 'Rule 1 - Overlap: 28 Nov 09:00-17:00 @ MCG'});
        e.call_results[0].available = false; e.call_results[0].detail = 'Rule 1 - Overlap: 28 Nov 09:00-17:00 @ MCG';
        _avail = _result('conflicts', e);""", "await cacAdd(false); await cacAdd(true); _done();")
        self.assertTrue(out["add"]["disabled"])
        self.assertTrue(out["conf"]["disabled"])
        self.assertIn("Rule 1 - Overlap", out["box"])
        self.assertIn("SmartStaff won&#39;t book a clashing shift", out["box"])
        self.assertEqual(_adds(out), [])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_partial_needs_one_extra_click(self):
        out = self._panel("""
        window._callAddCtx.filled = 3;
        var e = _entry({partial: true});
        e.call_results[1].available = false; e.call_results[1].detail = 'Rule 1 - Overlap';
        _avail = _result('available', e);
        _add.push({status: 200, json: {results: [{success: true, call: 'General'}]}});""",
            "await cacAdd(false); var first = _state(); await cacAdd(false); "
            "console.log(JSON.stringify({first: first, after: _state()}));")
        first, after = out["first"], out["after"]
        self.assertIn("SmartStaff will refuse #39819 Load Out: Rule 1 - Overlap", first["box"])
        self.assertEqual(first["add"]["text"], "Add anyway")
        self.assertEqual(first["conf"]["text"], "Confirm anyway")
        self.assertEqual(_adds(first), [])
        self.assertEqual(len(_adds(after)), 1)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_amber_and_full_share_one_click(self):
        out = self._panel("""
        window._callAddCtx.filled = 10;
        _avail = _result('available', _entry({conflict_warnings: ['Rule 2 - Fatigue: 8h gap']}));
        _add.push({status: 200, json: {results: [{success: true, call: 'General'}]}});""",
            "await cacAdd(true); var first = _state(); await cacAdd(true); "
            "console.log(JSON.stringify({first: first, after: _state()}));")
        first = out["first"]
        self.assertIn("Rule 2 - Fatigue", first["box"])
        self.assertIn("#39795 is full — 10/10. Adding takes it to 11.", first["box"])
        self.assertEqual(first["box"].count('id="cac-reasons"'), 1)
        self.assertEqual(_adds(first), [])
        self.assertEqual(len(_adds(out["after"])), 1)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_boss_call_one_person_no_prompt(self):
        out = self._panel("""
        window._callAddCtx.filled = 0; window._callAddCtx.callName = 'Crew Boss';
        _avail = _result('available', _entry());
        _add.push({status: 200, json: {results: [{success: true, call: 'Crew Boss'}]}});""",
            "await cacAdd(false); _done();")
        self.assertEqual(len(_adds(out)), 1)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_inductions_are_shown_but_need_no_click(self):
        out = self._panel("""
        window._callAddCtx.filled = 3;
        _avail = _result('available', _entry({induction_warnings: ['No induction: Marvel Stadium']}));
        _add.push({status: 200, json: {results: [{success: true, call: 'General'}]}});""",
            "var mid = _state(); await cacAdd(false); console.log(JSON.stringify({mid: mid, after: _state()}));")
        self.assertIn("No induction: Marvel Stadium", out["mid"]["box"])
        self.assertEqual(len(_adds(out["after"])), 1)


class WriteTests(unittest.TestCase):

    def _go(self, setup, then):
        return run("""
        window._callAddCtx.filled = 3;
        _avail = _result('available', _entry());
        """ + setup + """
        await _open(); _type("O'Lack, Michael"); await cacCheck(); """ + then)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_409_asks_then_resends_with_ack(self):
        out = self._go("""
        _add.push({status: 409, json: {needs_confirm: true, warnings: [{call_name: 'Load Out'}]}});
        _add.push({status: 200, json: {results: [{success: true, call: 'General'}]}});""",
            "await cacAdd(false); await new Promise(function(r){ setTimeout(r, 20); }); _done();")
        adds = _adds(out)
        self.assertEqual(len(out["over"]), 1)
        self.assertEqual(len(adds), 2)
        self.assertFalse(adds[0]["body"]["ack_over"])
        self.assertTrue(adds[1]["body"]["ack_over"])
        self.assertTrue(out["overlay"], "Esc during the over-warning closed Add Crew")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_409_cancel_sends_nothing_more(self):
        out = self._go("""
        _overAnswer = false;
        _add.push({status: 409, json: {needs_confirm: true, warnings: [{call_name: 'Load Out'}]}});""",
            "await cacAdd(false); await new Promise(function(r){ setTimeout(r, 20); }); _done();")
        self.assertEqual(len(_adds(out)), 1)
        self.assertEqual(out["reloaded"], [])
        self.assertFalse(out["add"]["disabled"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_failure_is_shown_never_counted_as_success(self):
        out = self._go("""
        _add.push({status: 200, json: {results: [
          {success: true, call: 'Load Out'},
          {success: false, call: 'General', error: 'SmartStaff refused this call — check for a calendar clash'}]}});""",
            "await cacAdd(false); _done();")
        self.assertIn("failed", out["box"])
        self.assertIn("SmartStaff refused this call", out["box"])
        self.assertIn("1 call.", out["box"])
        self.assertEqual(out["reloaded"], [["12291", "39795"]])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_nothing_added_does_not_reload(self):
        out = self._go("""
        _add.push({status: 200, json: {results: [{skipped: 'already on this call', call: 'General'}]}});""",
            "await cacAdd(false); _done();")
        self.assertIn("Nothing was added", out["box"])
        self.assertIn("already on call", out["box"])
        self.assertEqual(out["reloaded"], [])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_server_error_keeps_the_panel(self):
        out = self._go("""
        _add.push({status: 500, json: {error: 'Not logged in'}});""",
            "await cacAdd(true); _done();")
        self.assertEqual(out["err"], "Add & Confirm failed: Not logged in")
        self.assertFalse(out["conf"]["disabled"])


if __name__ == "__main__":
    unittest.main()

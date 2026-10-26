"""Booking plan review panel (BRIEF-staffing-plans-b §3.4, §3.5): the places
line, × removal (B12) and Add plan's grouping by root calls, the 409
over-confirm and Cancel (B11).

Runs the page's own functions under node (js_harness) against a fake booking
plan and a fake /api/goat/add-crew. Skips when node isn't installed."""

import json
import unittest

import js_harness as H

FUNCS = H.extract(
    "_planPlacesText", "_planEsc", "_planClearTicks", "_planCallKey", "renderBookingPlan", "_bpCall",
    "_bookingPlanPeople", "bookingPlanRemove", "_bookingPlanRender", "bookingPlanSend",
    "_planReqReviewHtml",
) if H.NODE else ""

PLAN = r"""
var selectedCalls = new Map([[1,{}],[2,{}],[3,{}]]);
var selected = new Set(); function updateSelCount(){}
var _activePlan = null;
var overlay = [];
function showOverlay(t, sub){ overlay.push('show'); }
function hideOverlay(){ overlay.push('hide'); }
var plan = {kind:'booking', booking_id:700, read_back:'RB', notes:['n'], experience_as_at:'x',
  summary:{places_total:5},
  calls:[{call_id:1, call_name:'Load In', start_dt:'2027-03-10T08:00:00', carried:false, carries:[3],
          places:2, places_breakdown:null, picks:[{user_id:10,name:'Ann',why:'w',flags:[]},{user_id:11,name:'Bo',why:'w',flags:[]}], notes:[]},
         {call_id:2, call_name:'Show', start_dt:'2027-03-10T13:00:00', carried:false, carries:[],
          places:1, places_breakdown:null, picks:[{user_id:10,name:'Ann',why:'w',flags:[]}], notes:[]},
         {call_id:3, call_name:'Load Out', start_dt:'2027-03-10T18:00:00', carried:true, carries:[],
          places:3, places_breakdown:null, picks:[{user_id:10,name:'Ann',why:'w',flags:[]},{user_id:11,name:'Bo',why:'w',flags:[]},
                                                 {user_id:12,name:'Cy',why:'w',flags:[]}], notes:[]}],
  people:[{user_id:10,name:'Ann',calls:[1,2,3],root_calls:[1,2]},
          {user_id:11,name:'Bo',calls:[1,3],root_calls:[1]},
          {user_id:12,name:'Cy',calls:[3],root_calls:[3]}]};
_el('plan-strip');
"""


class PlacesLineTests(unittest.TestCase):
    @unittest.skipUnless(H.NODE, "node not installed")
    def test_places_text(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + r"""
console.log(JSON.stringify([
  _planPlacesText(0, {confirmed:9, promo_pending:1, awaiting:5}),
  _planPlacesText(0, {confirmed:9, promo_pending:0, awaiting:0}),
  _planPlacesText(4, {confirmed:1, promo_pending:0, awaiting:6}),
  _planPlacesText(4, {confirmed:1, promo_pending:0, awaiting:0}),
  _planPlacesText(1, null),
]));""")
        self.assertEqual(out, [
            "0 places — 9 confirmed, 5 offers awaiting reply · 1 promotion pending",
            "0 places — 9 confirmed",
            "4 places (6 held by offers awaiting reply)",
            "4 places",
            "1 place",
        ])


class BookingPanelTests(unittest.TestCase):
    @unittest.skipUnless(H.NODE, "node not installed")
    def test_remove_carries_and_regroups(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + PLAN + r"""
renderBookingPlan({plan:plan}, {});
bookingPlanRemove(11, 1);                 // Bo off the load in -> off the load out too
var a = _bookingPlanPeople().map(p => [p.user_id, p.calls, p.root_calls]);
bookingPlanRemove(12, 3);                 // Cy only on the load out
var b = _bookingPlanPeople().map(p => [p.user_id, p.calls, p.root_calls]);
bookingPlanRemove(10, 3);                 // Ann off the carried load out -> off the load in as well
var c = _bookingPlanPeople().map(p => [p.user_id, p.calls, p.root_calls]);
console.log(JSON.stringify({a:a, b:b, c:c, html:_el('plan-strip').innerHTML.indexOf('removed by hand') >= 0}));
""")
        self.assertEqual(out["a"], [[10, ["1", "2", "3"], ["1", "2"]], [12, ["3"], ["3"]]])
        self.assertEqual(out["b"], [[10, ["1", "2", "3"], ["1", "2"]]])
        self.assertEqual(out["c"], [[10, ["2"], ["2"]]])
        self.assertTrue(out["html"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_offer_clash_flag_chip_in_panel(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + PLAN + r"""
plan.calls[1].picks[0].flags = ['\u2709 Rule 2 - 9hr shift needs 6hr gap (only 2.0hrs)'];
renderBookingPlan({plan:plan}, {});
var h = _el('plan-strip').innerHTML;
var at = h.indexOf('\u2709 Rule 2');
console.log(JSON.stringify({chip: at >= 0 && h.lastIndexOf('<span', at) > h.lastIndexOf('Ann', at)}));
""")
        self.assertTrue(out["chip"])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_send_groups_and_over_cancel(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + PLAN + r"""
var sent = [], shown = null, overAnswers = [true, false], overlayWhenAsked = [];
async function goatOverConfirm(w){ overlayWhenAsked.push(overlay[overlay.length-1]); return overAnswers.shift(); }
function showOfferResults(t, r, always){ shown = [t, r.length, !!always]; return {added:r.length, unverified:0, skipped:0, failed:0}; }
function alert(m){ throw new Error('alert: ' + m); }
var calls = 0;
async function fetch(url, o){
  var b = JSON.parse(o.body); sent.push({calls:b.calls.map(c=>c.call_id), crew:b.crew.map(c=>c.crew_id), ack:!!b.ack_over, confirm:b.confirm});
  calls++;
  // group 1 ([1]) goes through, group 2 ([1,2]) gets a 409 then goes on ack,
  // group 3 ([3]) gets a 409 and the operator cancels.
  var over = (calls === 2 || calls === 4) && !b.ack_over;
  return {ok: !over, status: over ? 409 : 200,
          json: async () => over ? {needs_confirm:true, warnings:[{}]} : {results:b.crew.map(c => ({success:true}))}};
}
(async function(){
  renderBookingPlan({plan:plan}, {});
  await bookingPlanSend(true);
  console.log(JSON.stringify({sent:sent, shown:shown, notSent:[..._activePlan.notSent],
                              sending:_activePlan.sending, asked:overlayWhenAsked,
                              overlayEnd:overlay[overlay.length-1]}));
})();
""")
        self.assertEqual(out["sent"], [
            {"calls": [1], "crew": ["11"], "ack": False, "confirm": True},
            {"calls": [1, 2], "crew": ["10"], "ack": False, "confirm": True},
            {"calls": [1, 2], "crew": ["10"], "ack": True, "confirm": True},
            {"calls": [3], "crew": ["12"], "ack": False, "confirm": True},
        ])
        self.assertEqual(out["shown"], ["Add & Confirm plan - results", 2, True])   # always shown
        self.assertEqual(out["notSent"], ["#3 Load Out"])
        self.assertFalse(out["sending"])
        self.assertEqual(out["asked"], ["hide", "hide"])      # overlay lifted for each question
        self.assertEqual(out["overlayEnd"], "hide")

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_cancel_on_first_group_sends_nothing(self):
        out = H.run(H.DOM_PRELUDE + FUNCS + PLAN + r"""
var sent = 0, shown = null;
async function goatOverConfirm(){ return false; }
function showOfferResults(t, r){ shown = t; return {}; }
async function fetch(url, o){ sent++; return {ok:false, status:409, json: async () => ({needs_confirm:true, warnings:[{}]})}; }
(async function(){
  renderBookingPlan({plan:plan}, {});
  await bookingPlanSend(false);
  console.log(JSON.stringify({sent:sent, shown:shown, notSent:[..._activePlan.notSent],
                              html:_el('plan-strip').innerHTML.indexOf('Not sent:') >= 0}));
})();
""")
        self.assertEqual(out["sent"], 1)                       # nothing resent after Cancel
        self.assertIsNone(out["shown"])                        # no empty "results" window
        self.assertEqual(out["notSent"], ["#1 Load In", "#1 Load In + #2 Show", "#3 Load Out"])
        self.assertTrue(out["html"])


class DrawerReadBackTests(unittest.TestCase):
    """A plan read-back waiting for yes: the reply always reaches the GOAT with
    the read-back's id; REALLY? and the other easter eggs never answer it."""

    SEND = H.extract("goatSend", "isSillyGoatQuestion") if H.NODE else ""

    def run_send(self, pending, text):
        return H.run(H.DOM_PRELUDE + self.SEND + r"""
var _goatPlanReadBack = %s, _goatHistory = [{role:'assistant', content:'Planning booking 11831.'}], _goatBusy = false;
var _goatPlanAnswers = {'1:LF': 'yes'};
_el('goat-input').value = %s; _el('goat-send'); _el('goat-input').focus = function(){};
var silly = 0, egg = 0, bodies = [];
function checkGoatEasterEgg(t){ egg++; return null; }
function appendGoatSillyResponse(){ silly++; }
function appendGoatMessage(){}
function showGoatTyping(){ return 1; }
function removeGoatTyping(){}
async function fetch(url, o){ bodies.push(JSON.parse(o.body)); return {ok:false, json: async () => ({})}; }
(async function(){
  await goatSend();
  window._answersSent = bodies.map(b => b.requirement_answers || null);
  console.log(JSON.stringify({silly:silly, egg:egg, sent:bodies.map(b => b.plan_read_back || null),
                              pendingAfter:_goatPlanReadBack}));
})();
""" % (json.dumps(pending), json.dumps(text)))

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_yes_with_read_back_waiting_reaches_the_goat(self):
        for text in ("yes", "yes, build the plan", "lol"):
            out = self.run_send("rb123", text)
            self.assertEqual(out, {"silly": 0, "egg": 0, "sent": ["rb123"], "pendingAfter": None}, text)

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_requirement_answers_ride_with_the_card_id(self):
        # Stage C §4.3: the card's clicked answers go with its id, never alone.
        js = self.SEND
        out = H.run(H.DOM_PRELUDE + js + r"""
var _goatPlanReadBack = 'rb5', _goatHistory = [], _goatBusy = false, _goatPlanAnswers = {'1:LF': 'no'};
_el('goat-input').value = 'yes'; _el('goat-send'); _el('goat-input').focus = function(){};
function checkGoatEasterEgg(){ return null; } function appendGoatMessage(){}
function showGoatTyping(){ return 1; } function removeGoatTyping(){} function appendGoatSillyResponse(){}
var bodies = [];
async function fetch(url, o){ bodies.push(JSON.parse(o.body)); return {ok:false, json: async () => ({})}; }
(async function(){
  await goatSend();
  _goatPlanReadBack = null; _el('goat-input').value = 'plan booking 11831, all calls';
  await goatSend();
  console.log(JSON.stringify(bodies.map(b => [b.plan_read_back || null, b.requirement_answers || null])));
})();
""")
        self.assertEqual(out, [["rb5", {"1:LF": "no"}], [None, None]])

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_read_back_turn_stores_history_note(self):
        out = H.run(H.DOM_PRELUDE + self.SEND + r"""
var _goatPlanReadBack = null, _goatHistory = [], _goatBusy = false, _goatPlanAnswers = {};
_el('goat-input').value = 'plan booking 11831, all calls, 20/80 old to new'; _el('goat-send');
_el('goat-input').focus = function(){};
var cards = [], bubbles = 0;
function checkGoatEasterEgg(){ return null; }
function appendGoatMessage(){}
function appendGoatStreamingMessage(){ bubbles++; return {}; }
function updateGoatStreamingMessage(){}
function finaliseGoatStreamingMessage(){}
function showGoatTyping(){ return 1; }
function removeGoatTyping(){}
function showGoatToolBadge(){}
function renderGoatOpenCard(){} function renderGoatPlanCard(){}
function renderGoatReadBackCard(p){ cards.push(p.id); }
var chunks = ['data: {"type":"tool_start","tool":"plan_staffing"}\n',
              'data: {"type":"plan_read_back","payload":{"id":"rb9","text":"RB"}}\n',
              'data: {"type":"history","text":"[Plan read-back shown]"}\n',
              'data: {"type":"done"}\n'];
async function fetch(){
  return {ok:true, body:{getReader(){ return {read: async () => chunks.length
    ? {done:false, value:chunks.shift()} : {done:true}}; }}};
}
function TextDecoder(){ this.decode = v => v; }
(async function(){
  await goatSend();
  console.log(JSON.stringify({cards:cards, bubbles:bubbles, pending:_goatPlanReadBack,
                              last:_goatHistory[_goatHistory.length-1]}));
})();
""")
        self.assertEqual(out["cards"], ["rb9"])
        self.assertEqual(out["bubbles"], 0)                 # no GOAT text bubble
        self.assertEqual(out["pending"], "rb9")
        self.assertEqual(out["last"], {"role": "assistant", "content": "[Plan read-back shown]"})

    @unittest.skipUnless(H.NODE, "node not installed")
    def test_yes_without_read_back_unchanged(self):
        out = self.run_send(None, "yes")
        self.assertEqual(out["silly"], 1)                 # the old behaviour, untouched
        self.assertEqual(out["sent"], [])


if __name__ == "__main__":
    unittest.main()

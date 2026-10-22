"""Call dialog, Stage 3 acceptance: "Set…" beside amber "venue has no travel
time" took you back to the booking on the Calls tab, the tab the booking was
on when the call was opened from it. It now opens the booking on Details with
the travel editor open.

Runs the page's own navigation functions under node (js_harness): the stack
push, trim and tab hand-back, the tab pick, bkIntentTab, and the pricing
context load that opens the editor. openBookingDialog and renderBookingDialog
are reduced to the lines that decide the tab: carry, trim, pick, intent."""

import unittest

import js_harness as H

FUNCS = H.extract(
    "entityOpenCall", "entityOpenBookingFromCall", "bkIntentTab", "entityStackPush",
    "entityStackTrim", "entityTabsHandBack", "entityTabsPick", "entityTabsCarry",
    "bkLoadPricingContext", "bkRenderTravel", "bkRenderEvent", "bkEditTravel",
    "bkPctxMsg", "bkPctxLink", "pcFmtMinutes", "ctBtn",
) if H.NODE else ""

SCRIPT = H.DOM_PRELUDE + r"""
var _entityStack = [];
var _bkPctx = { bookingId: null, data: null }, _bkPctxBusy = false;
function bkRerenderCalls(){}
function entityScrollEl(){ return null; }
function entityCurrentName(){ return 'view'; }
function entityRenderTrail(){}
function _efVal(id){ var e = document.getElementById(id); return e ? e.value : ''; }
window._cohort = 'admin';
_el('entity-modal').style.display = '';

// What is on screen: the view's key and its tab.
var screen = { key: null, tab: null };
function entityTabsState(key){ return screen.key === key ? { tab: screen.tab, crewView: null } : null; }

var travelMinutes = null;   // the venue has no travel time
async function fetch(url){
  return { ok: true, status: 200, json: async function(){
    return { travel_allowed: true, card_ok: true, can_change: true, call_flags: {}, event: null,
             venue_travel: { venue_id: 431, return_minutes: travelMinutes } }; } };
}

var d = { venue: { id: 431, name: 'ZZ Venue' }, calls: [{ call_id: 39322 }] };
var loads = [];
function openCallDialog(b, c){ screen = { key: 'call:' + b + ':' + c, tab: 'details' }; }
// openBookingDialog + renderBookingDialog, as far as the tab goes.
async function openBookingDialog(b){
  entityTabsCarry('booking:' + b);
  entityStackTrim('booking:' + b);
  var pick = entityTabsPick('booking:' + b);
  var tab = bkIntentTab(b, pick.tab, d);
  screen = { key: 'booking:' + b, tab: tab };
  _el('bk-travel-wrap').innerHTML = ''; _el('bk-event-wrap');
  window._curBookingEdit = d;
  await bkLoadPricingContext(b, d);
  loads.push({ tab: tab, editor: /id="bk-travel-h"/.test(_el('bk-travel-wrap').innerHTML),
               travel: _el('bk-travel-wrap').innerHTML });
}

// Wait for the next booking load to finish (the openers don't return it).
async function nextLoad(){
  var n = loads.length;
  for(var i = 0; i < 1000 && loads.length === n; i++) await new Promise(function(r){ setTimeout(r, 0); });
  return loads[loads.length - 1];
}

(async function(){
  // The booking, on its Calls tab; a call opened from it; then Set….
  await openBookingDialog('12214');
  screen.tab = 'calls';
  entityOpenCall('12214', '39322');
  var p = nextLoad(); entityOpenBookingFromCall('12214', '39322');
  var viaSet = await p;

  // Plain Back to the booking afterwards: its own tab, no editor (one shot).
  screen.tab = 'calls';
  entityOpenCall('12214', '39322');
  await openBookingDialog('12214');
  var plainBack = loads[loads.length - 1];

  // Set… on an M&OP booking's call: Details, but no travel editor to open.
  entityOpenCall('12214', '39322');
  fetch = async function(){ return { ok: true, status: 200, json: async function(){
    return { travel_allowed: false, card_ok: true, can_change: true, call_flags: {}, event: null,
             venue_travel: { venue_id: 431, return_minutes: null } }; } }; };
  p = nextLoad(); entityOpenBookingFromCall('12214', '39322');
  var onMopt = await p;

  console.log(JSON.stringify({ viaSet: viaSet, plainBack: plainBack, onMopt: onMopt,
                               intentLeft: window._bkOpenIntent || null }));
})().catch(function(e){ console.error(e); process.exit(1); });
"""


@unittest.skipUnless(H.NODE, "node not installed")
class SetTravelFromCall(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.out = H.run(FUNCS + "\n" + SCRIPT)

    def test_set_opens_details_with_the_travel_editor(self):
        self.assertEqual(self.out["viaSet"]["tab"], "details")
        self.assertTrue(self.out["viaSet"]["editor"], self.out["viaSet"]["travel"])

    def test_it_is_one_shot(self):
        self.assertEqual(self.out["plainBack"]["tab"], "calls")
        self.assertFalse(self.out["plainBack"]["editor"])
        self.assertIsNone(self.out["intentLeft"])

    def test_no_editor_where_travel_isnt_allowed(self):
        self.assertEqual(self.out["onMopt"]["tab"], "details")
        self.assertFalse(self.out["onMopt"]["editor"])


if __name__ == "__main__":
    unittest.main()
